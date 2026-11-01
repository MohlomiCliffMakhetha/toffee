<?php
// Toffee API: profiles, and a channel for every profile with comment threads
// (public or private, with images, files, voice notes, stickers and GIFs).
// Built from the BrainCandy leaderboard, without the game.
//
// Each channel has a Public tab and a Private tab. A private comment is between
// two people. A private reply to a public comment copies that comment into the
// Private tab (once per person replying) and the private replies thread under
// the copy, so private replies never sit inside a public thread, and a private
// thread never gets a public reply.
//
// Patterns reused from the Torii messenger: a CSRF header, password_hash, login rate limits,
// SQLite in WAL mode, uploads checked by content (finfo) and served back through this script.
//
// Every response is JSON except ?a=file. Reads are GET and never write anything (not to the
// database, not to the disk); writes are POST with the X-CSRF-Token header (from ?a=me). So
// toffee can run as several copies on Torii: GETs to any copy, POSTs to the writer. See DATA.md.

declare(strict_types=1);
// Warnings go to the log, never into the JSON replies.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require __DIR__ . '/webpush.php';
require __DIR__ . '/s3.php';

$DATA = getenv('TOFFEE_DATA') ?: '/data';
$UPLOADS = "$DATA/uploads";   // files, when they aren't in S3 (TORII_S3_ENDPOINT unset)
// On Torii with several copies: 'writer' (the one copy that may write) or 'reader' (its
// database is a read-only copy). Unset: the only copy, as before.
$WRITER = getenv('TORII_ROLE') !== 'reader';
$S3 = s3_on();
if ($WRITER && !$S3 && !is_dir($UPLOADS)) @mkdir($UPLOADS, 0700, true);

const MAX_BODY = 2000;              // characters per comment
const MAX_ATTACHMENTS = 4;          // per comment (photos, voice notes, files, a sticker or GIF)
const EDIT_WINDOW = 300;            // seconds: edit or delete within 5 minutes, then it's permanent
const SESSION_DAYS = 30;            // stay signed in this long after your last visit (the cookie is renewed daily)
const UPLOAD_QUOTA = 1024 * 1024 * 1024;   // bytes of uploads per person (mind the Pi's disk: people x this)
const ROOTS_PAGE = 30;
const MEDIA_PIECE = 1024 * 1024;   // bytes of a video or voice note per request (see ?a=file)              // top comments per page of a channel tab
// Sizes are per file. The web server must let files this big through: see .torii.Dockerfile
// (upload_max_filesize / post_max_size), which allows a little more than the largest here.
const UPLOAD_KINDS = [
    'image' => ['max' => 10 * 1024 * 1024, 'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp']],
    // Voice notes (recorded in toffee: purpose=voice). Phones record WebM, MP4 or Ogg audio,
    // which can look like video by their contents.
    'audio' => ['max' => 10 * 1024 * 1024, 'mimes' => ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/x-m4a', 'audio/aac', 'audio/wav', 'audio/x-wav', 'video/webm', 'video/mp4',
        'audio/m4a', 'video/quicktime', 'audio/x-caf']],   // (iPhones: their recordings can look like QuickTime or M4A)
    'video' => ['max' => 50 * 1024 * 1024, 'mimes' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/3gpp']],
    // Documents and anything else, except programs: always downloaded, never opened in toffee.
    'file'  => ['max' => 20 * 1024 * 1024, 'mimes' => []],
    // Your own sticker/GIF collection (as in the messenger): reusable in many posts.
    'sticker' => ['max' => 1 * 1024 * 1024, 'mimes' => ['image/png', 'image/webp', 'image/jpeg', 'image/gif']],
    'gif'   => ['max' => 8 * 1024 * 1024, 'mimes' => ['image/gif', 'video/mp4', 'video/webm', 'video/quicktime']],
];

// Programs and scripts are refused (by their contents and by their name), so toffee can't be
// used to pass them around.
const BLOCKED_MIMES = ['application/x-dosexec', 'application/x-executable', 'application/x-mach-binary', 'application/x-sharedlib',
    'application/x-pie-executable', 'application/vnd.android.package-archive', 'application/x-msdownload', 'application/x-msi',
    'application/java-archive', 'text/x-shellscript', 'application/x-shellscript', 'text/javascript', 'application/javascript'];
const BLOCKED_EXTS = ['exe', 'msi', 'com', 'scr', 'bat', 'cmd', 'ps1', 'vbs', 'js', 'mjs', 'jar', 'apk', 'app', 'dmg', 'pkg', 'deb', 'rpm', 'sh', 'bin', 'dll', 'so'];

$a = $_GET['a'] ?? '';

// Slow work that the reply needn't wait for (phone notifications): run after it's sent.
$AFTER = [];
function out(array $data, int $code = 200): void {
    global $AFTER, $CSRF_NEW;
    if ($CSRF_NEW) $data['csrf'] = csrf_token();   // signed in or out: the page's token changes with it
    // (Text with broken characters, saved by an old version or sent by hand, mustn't break a page for
    // everyone: such bytes become \uFFFD.)
    $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    // Gzipped here rather than with ob_gzhandler, so Content-Length below stays the real length.
    if (strlen($json) > 1024 && extension_loaded('zlib') && preg_match('/\bgzip\b/i', $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')) {
        $json = gzencode($json, 6);
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
    }
    if ($AFTER) { header('Content-Length: ' . strlen($json)); header('Connection: close'); }
    echo $json;
    if ($AFTER) {
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        else { while (ob_get_level()) ob_end_flush(); flush(); }
        foreach ($AFTER as $job) $job();
    }
    exit;
}
function fail(string $msg, int $code = 400): void { out(['ok' => false, 'error' => $msg], $code); }

// A reader opens its copy read-only (opening it for writing would fail there).
$db = new PDO("sqlite:$DATA/toffee.db", null, null, $WRITER ? [] : [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
// synchronous=NORMAL: with WAL the database can't be damaged by a power cut (at most the last
// moment's change is lost), and the SD card isn't flushed after every single change.
$db->exec(($WRITER ? 'PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; ' : '')
    . 'PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA temp_store=MEMORY; PRAGMA mmap_size=67108864; PRAGMA cache_size=-8000;');
// toffee's own channels, made by the server and pinned at the top of home. They work like
// anyone's channel (the owner posts publicly, everyone reacts and replies, the Private tab is
// for writing to the owner), except that each new public post is an announcement: everyone
// is told. Help & Feedback is run by signing in as 'help' (password: HELP_PASS_HASH below).
// Adding one: also bump SCHEMA_VERSION, so the next request creates it.
const OFFICIAL_CHANNELS = [
    'help' => ['Help & Feedback', 'News from toffee. Tell us what you think: reply to a post, or write privately (Private tab).'],
];
// bcrypt of the Help account's first password (given to the admin once). Only the hash is
// here; after signing in, the admin keeps the recovery key to change it.
const HELP_PASS_HASH = '$2y$12$rXUXlao/kgttfOzZgpRphuMasYjCf7FknkMEdlRYS9vnl3FaFjy36';
function is_official(string $username): bool {
    return isset(OFFICIAL_CHANNELS[strtolower($username)]);
}

// Tables, columns and indexes. Checked with one cheap PRAGMA per request; the work below only
// runs when the database is older than SCHEMA_VERSION (bump it with every change here), and
// every step is safe to run again on a database that already has it. Only the writer upgrades:
// a reader serves its copy as it is (the writer's upgrade reaches it with the next copy).
const SCHEMA_VERSION = 11;
if ($WRITER && (int)$db->query('PRAGMA user_version')->fetchColumn() < SCHEMA_VERSION) {
    $db->exec('BEGIN IMMEDIATE');   // one request upgrades; the others wait for it (busy_timeout)
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < SCHEMA_VERSION) migrate($db);
    $db->exec('COMMIT');
}
function migrate(PDO $db): void {
    $db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE COLLATE NOCASE, pass TEXT NOT NULL,
        display_name TEXT NOT NULL, bio TEXT NOT NULL DEFAULT '', avatar_id INTEGER, created_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS uploads (
        id INTEGER PRIMARY KEY, owner_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL, kind TEXT NOT NULL, mime TEXT NOT NULL, size INTEGER NOT NULL,
        original TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY, board_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        author_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        parent_id INTEGER REFERENCES posts(id) ON DELETE CASCADE,
        is_private INTEGER NOT NULL DEFAULT 0, recipient_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        body TEXT NOT NULL DEFAULT '', upload_id INTEGER REFERENCES uploads(id), created_at INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS posts_board ON posts(board_user_id, id);
    CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
        kind TEXT NOT NULL, created_at INTEGER NOT NULL, read_at INTEGER);
    CREATE INDEX IF NOT EXISTS notifications_user ON notifications(user_id, id);
    CREATE TABLE IF NOT EXISTS post_uploads (
        post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
        upload_id INTEGER NOT NULL REFERENCES uploads(id), pos INTEGER NOT NULL, PRIMARY KEY (post_id, upload_id));
    CREATE TABLE IF NOT EXISTS post_reactions (
        post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        emoji TEXT NOT NULL, PRIMARY KEY (post_id, user_id));
    -- Phones and browsers that asked for background notifications (Web Push).
    CREATE TABLE IF NOT EXISTS push_subs (
        endpoint TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        p256dh TEXT NOT NULL, auth TEXT NOT NULL, created_at INTEGER NOT NULL);
    -- Recent sign-in and sign-up attempts, for rate limits (kind: login / register; k: an IP).
    CREATE TABLE IF NOT EXISTS throttle (kind TEXT NOT NULL, k TEXT NOT NULL, at INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS throttle_k ON throttle(kind, k, at);
    DROP TABLE IF EXISTS login_attempts;
    ");
    $cols = fn($t) => $db->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('library', $cols('uploads'))) $db->exec('ALTER TABLE uploads ADD COLUMN library INTEGER NOT NULL DEFAULT 0');
    $pcols = $cols('posts');
    if (!in_array('edited_at', $pcols)) $db->exec('ALTER TABLE posts ADD COLUMN edited_at INTEGER');
    // Private copy of a public comment: which comment, and who replied privately to it.
    if (!in_array('source_id', $pcols)) $db->exec('ALTER TABLE posts ADD COLUMN source_id INTEGER');
    if (!in_array('started_by', $pcols)) $db->exec('ALTER TABLE posts ADD COLUMN started_by INTEGER');
    // Background pictures: your channel's Public tab, its Private tab, your profile pages.
    $ucols = $cols('users');
    foreach (['bg_public_id', 'bg_private_id', 'bg_settings_id', 'bg_banner_id'] as $col) {
        if (!in_array($col, $ucols)) $db->exec("ALTER TABLE users ADD COLUMN $col INTEGER");
    }
    // Recovery key (only its hash), and a counter that goes up when the password is reset:
    // sessions from before are signed out, so a reset locks out anyone who had got in.
    if (!in_array('recovery_hash', $ucols)) $db->exec('ALTER TABLE users ADD COLUMN recovery_hash TEXT');
    if (!in_array('session_gen', $ucols)) $db->exec('ALTER TABLE users ADD COLUMN session_gen INTEGER NOT NULL DEFAULT 0');
    // Replies of a comment, uploads in use, and each person's uploads: looked up on every page.
    $db->exec('CREATE INDEX IF NOT EXISTS posts_source ON posts(source_id, started_by);
        CREATE INDEX IF NOT EXISTS posts_parent ON posts(parent_id);
        CREATE INDEX IF NOT EXISTS posts_upload ON posts(upload_id);
        CREATE INDEX IF NOT EXISTS post_uploads_upload ON post_uploads(upload_id);
        CREATE INDEX IF NOT EXISTS uploads_owner ON uploads(owner_id);
        CREATE INDEX IF NOT EXISTS notifications_post ON notifications(post_id);');
    // Version 4: conversations and their fingerprints (author/recipient), the 🔔 count (unread only),
    // phones per person, and each channel's latest public post on home.
    $db->exec('CREATE INDEX IF NOT EXISTS posts_author ON posts(author_id, recipient_id);
        CREATE INDEX IF NOT EXISTS posts_recipient ON posts(recipient_id);
        CREATE INDEX IF NOT EXISTS notifications_unread ON notifications(user_id) WHERE read_at IS NULL;
        CREATE INDEX IF NOT EXISTS push_subs_user ON push_subs(user_id);
        CREATE INDEX IF NOT EXISTS posts_board_roots ON posts(board_user_id, is_private, parent_id, id);');
    $from = (int)$db->query('PRAGMA user_version')->fetchColumn();
    foreach (OFFICIAL_CHANNELS as $name => [$display, $bio]) {
        $st = $db->prepare('SELECT 1 FROM users WHERE username = ?');
        $st->execute([$name]);
        if ($st->fetchColumn()) continue;   // made already (hashing a password is slow: only when needed)
        $db->prepare('INSERT INTO users (username, pass, display_name, bio, created_at) VALUES (?, ?, ?, ?, ?)')
           ->execute([$name, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $display, $bio, time()]);
    }
    if ($from < 3) help_becomes_owned($db);
    // Version 5: settings kept in the database (copies of toffee only get the database), and
    // sign-ins in signed cookies (no session files): the key that signs them, and the sign-ins
    // ended with Sign out. The phone-notification keys move in from vapid.json.
    $db->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS signed_out (sid TEXT PRIMARY KEY, until INTEGER NOT NULL);');
    $set = $db->prepare('INSERT OR IGNORE INTO settings (k, v) VALUES (?, ?)');
    $set->execute(['cookie_key', bin2hex(random_bytes(32))]);
    if (!setting($db, 'vapid')) {
        global $DATA;
        $old = is_file("$DATA/vapid.json") ? json_decode((string)file_get_contents("$DATA/vapid.json"), true) : null;
        $set->execute(['vapid', json_encode($old ?: vapid_new())]);
    }
    // Version 6: voice and video calls (see CALLS below). Only the setup goes through here; the
    // call itself goes straight between the two phones.
    $db->exec('CREATE TABLE IF NOT EXISTS calls (
            id INTEGER PRIMARY KEY, caller_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            callee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, video INTEGER NOT NULL DEFAULT 0,
            state TEXT NOT NULL DEFAULT \'ringing\', offer TEXT NOT NULL, answer TEXT,
            created_at INTEGER NOT NULL, answered_at INTEGER, alive_at INTEGER, ended_at INTEGER);
        CREATE INDEX IF NOT EXISTS calls_callee ON calls(callee_id, state);
        CREATE INDEX IF NOT EXISTS calls_caller ON calls(caller_id, state);');
    // Version 7: a voice note's or video's length (shown before anything loads), and a video's
    // cover picture (its first frame, made by the phone that sent it).
    $upcols = $cols('uploads');
    if (!in_array('duration', $upcols)) $db->exec('ALTER TABLE uploads ADD COLUMN duration REAL');
    if (!in_array('poster_id', $upcols)) $db->exec('ALTER TABLE uploads ADD COLUMN poster_id INTEGER');
    $db->exec('CREATE INDEX IF NOT EXISTS uploads_poster ON uploads(poster_id) WHERE poster_id IS NOT NULL');
    // Version 8: one-off groups. A private message that @names others is seen by all of them,
    // with every reply; @naming someone in a reply adds them. group_id: the thread's first
    // message (on it and every reply). with_id: whose conversation it shows in, for that member.
    if (!in_array('group_id', $cols('posts'))) $db->exec('ALTER TABLE posts ADD COLUMN group_id INTEGER');
    $db->exec('CREATE TABLE IF NOT EXISTS group_members (
            group_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            with_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            added_at INTEGER NOT NULL, PRIMARY KEY (group_id, user_id));
        CREATE INDEX IF NOT EXISTS group_members_user ON group_members(user_id, with_id);
        CREATE INDEX IF NOT EXISTS posts_group ON posts(group_id) WHERE group_id IS NOT NULL;');
    // Version 9: group calls (ROOMS below). Each phone talks straight to every other one; here
    // only who's in, and each pair's offer and answer.
    $db->exec('CREATE TABLE IF NOT EXISTS rooms (
            id INTEGER PRIMARY KEY, group_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
            started_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, video INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL, ended_at INTEGER);
        CREATE INDEX IF NOT EXISTS rooms_group ON rooms(group_id, ended_at);
        CREATE TABLE IF NOT EXISTS room_people (
            room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            state TEXT NOT NULL DEFAULT \'ringing\', joined_at INTEGER, alive_at INTEGER, PRIMARY KEY (room_id, user_id));
        CREATE INDEX IF NOT EXISTS room_people_user ON room_people(user_id, state);
        CREATE TABLE IF NOT EXISTS room_links (
            room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE, from_id INTEGER NOT NULL, to_id INTEGER NOT NULL,
            offer TEXT NOT NULL, answer TEXT, created_at INTEGER NOT NULL, PRIMARY KEY (room_id, from_id, to_id));');
    // Version 10: adding people to a call between two. Its own room, with no group (group_id
    // may be empty: the table is rebuilt for that; only the list of who was in past calls goes).
    // calls.room_id: where a call between two moved to. added_by: who brought someone in.
    $notNull = array_column($db->query('PRAGMA table_info(rooms)')->fetchAll(), 'notnull', 'name');
    if (!empty($notNull['group_id'])) {
        $st = $set = null;   // (SQLite won't drop a table while an earlier statement here is still open)
        $db->exec('CREATE TABLE rooms_new (
                id INTEGER PRIMARY KEY, group_id INTEGER REFERENCES posts(id) ON DELETE CASCADE,
                started_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, video INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL, ended_at INTEGER, call_id INTEGER);
            INSERT INTO rooms_new (id, group_id, started_by, video, created_at, ended_at) SELECT id, group_id, started_by, video, created_at, ended_at FROM rooms;
            DROP TABLE rooms;
            ALTER TABLE rooms_new RENAME TO rooms;
            CREATE INDEX IF NOT EXISTS rooms_group ON rooms(group_id, ended_at);');
    }
    if (!in_array('added_by', $cols('room_people'))) $db->exec('ALTER TABLE room_people ADD COLUMN added_by INTEGER');
    if (!in_array('room_id', $cols('calls'))) $db->exec('ALTER TABLE calls ADD COLUMN room_id INTEGER');
    // Version 11: the home feed's fingerprint is a counter the database keeps itself: any change to
    // a public post, a reaction on one, or a profile adds 1 (it used to read every post, reaction
    // and person on each check). Triggers, so no code path can forget it; deletes through ON
    // DELETE CASCADE fire them too. Plus the indexes the file check and attachment check use.
    $db->exec("INSERT OR IGNORE INTO settings (k, v) VALUES ('feed_ver', '0');
        CREATE TRIGGER IF NOT EXISTS feed_post_in AFTER INSERT ON posts WHEN NEW.is_private = 0 BEGIN UPDATE settings SET v = v + 1 WHERE k = 'feed_ver'; END;
        CREATE TRIGGER IF NOT EXISTS feed_post_up AFTER UPDATE ON posts WHEN NEW.is_private = 0 OR OLD.is_private = 0 BEGIN UPDATE settings SET v = v + 1 WHERE k = 'feed_ver'; END;
        CREATE TRIGGER IF NOT EXISTS feed_post_out AFTER DELETE ON posts WHEN OLD.is_private = 0 BEGIN UPDATE settings SET v = v + 1 WHERE k = 'feed_ver'; END;
        CREATE TRIGGER IF NOT EXISTS feed_rx_in AFTER INSERT ON post_reactions WHEN (SELECT is_private FROM posts WHERE id = NEW.post_id) = 0 BEGIN UPDATE settings SET v = v + 1 WHERE k = 'feed_ver'; END;
        CREATE TRIGGER IF NOT EXISTS feed_rx_out AFTER DELETE ON post_reactions WHEN (SELECT is_private FROM posts WHERE id = OLD.post_id) IS NOT 1 BEGIN UPDATE settings SET v = v + 1 WHERE k = 'feed_ver'; END;
        CREATE TRIGGER IF NOT EXISTS feed_user_in AFTER INSERT ON users BEGIN UPDATE settings SET v = v + 1 WHERE k = 'feed_ver'; END;
        CREATE TRIGGER IF NOT EXISTS feed_user_up AFTER UPDATE OF display_name, bio, avatar_id, bg_banner_id ON users BEGIN UPDATE settings SET v = v + 1 WHERE k = 'feed_ver'; END;
        CREATE TRIGGER IF NOT EXISTS feed_user_out AFTER DELETE ON users BEGIN UPDATE settings SET v = v + 1 WHERE k = 'feed_ver'; END;
        CREATE INDEX IF NOT EXISTS users_avatar ON users(avatar_id) WHERE avatar_id IS NOT NULL;
        CREATE INDEX IF NOT EXISTS users_bg_public ON users(bg_public_id) WHERE bg_public_id IS NOT NULL;
        CREATE INDEX IF NOT EXISTS users_bg_private ON users(bg_private_id) WHERE bg_private_id IS NOT NULL;
        CREATE INDEX IF NOT EXISTS users_bg_settings ON users(bg_settings_id) WHERE bg_settings_id IS NOT NULL;
        CREATE INDEX IF NOT EXISTS users_bg_banner ON users(bg_banner_id) WHERE bg_banner_id IS NOT NULL;");
    $db->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
}
// Version 3: Help & Feedback was open (anyone posted publicly). Now its admin signs in as
// 'help' and posts announcements; the feedback people had posted there moves to the Private
// tab, each post with its replies becoming a conversation between its writer and Help.
function help_becomes_owned(PDO $db): void {
    [$display, $bio] = OFFICIAL_CHANNELS['help'];
    $db->prepare("UPDATE users SET pass = ?, display_name = ?, bio = ?, session_gen = session_gen + 1 WHERE username = 'help'")
       ->execute([HELP_PASS_HASH, $display, $bio]);
    $help = (int)$db->query("SELECT id FROM users WHERE username = 'help'")->fetchColumn();
    $roots = $db->prepare('SELECT id, author_id FROM posts WHERE board_user_id = ? AND parent_id IS NULL AND is_private = 0 AND author_id != ?');
    $roots->execute([$help, $help]);
    $tree = $db->prepare('WITH RECURSIVE t(id) AS (SELECT ? UNION ALL SELECT p.id FROM posts p JOIN t ON p.parent_id = t.id) SELECT id FROM t');
    $move = $db->prepare('UPDATE posts SET is_private = 1, recipient_id = CASE WHEN author_id = :help THEN :writer ELSE :help END WHERE id = :id');
    foreach ($roots->fetchAll() as $root) {
        $tree->execute([$root['id']]);
        foreach ($tree->fetchAll(PDO::FETCH_COLUMN) as $id) $move->execute([':help' => $help, ':writer' => $root['author_id'], ':id' => $id]);
    }
}

function setting(PDO $db, string $k): ?string {
    try { $st = $db->prepare('SELECT v FROM settings WHERE k = ?'); $st->execute([$k]); } catch (PDOException $e) { return null; }   // (no table yet)
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}
function vapid(PDO $db): array { return json_decode((string)setting($db, 'vapid'), true) ?: []; }

// ---- signing in: a signed cookie, so no session is stored anywhere ------------------
// tf_auth = uid.gen.sid.expires.signature (HMAC with the cookie_key setting). gen is the
// person's session_gen (a password reset signs every device out); sid names this sign-in, so
// Sign out can end just this one (signed_out). The CSRF token is a signature of the sign-in
// (or, signed out, of this browser's tf_csrf cookie), so it needn't be stored either.
$KEY = (string)setting($db, 'cookie_key');
$CSRF_NEW = false;
$AUTH = null;   // ['uid', 'gen', 'sid', 'exp'] of a valid cookie
function https(): bool { return (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || !empty($_SERVER['HTTPS']); }
function cookie(string $name, string $value, int $expires): void {
    setcookie($name, $value, ['expires' => $expires, 'path' => '/', 'secure' => https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function auth_cookie(int $uid, int $gen, string $sid): void {
    global $KEY, $AUTH;
    $exp = time() + SESSION_DAYS * 86400;
    $v = "$uid.$gen.$sid.$exp";
    cookie('tf_auth', $v . '.' . hash_hmac('sha256', $v, $KEY), $exp);
    $AUTH = ['uid' => $uid, 'gen' => $gen, 'sid' => $sid, 'exp' => $exp];
}
function csrf_token(): string {
    global $KEY, $AUTH, $me;
    if ($AUTH && $me) return hash_hmac('sha256', "csrf:user:{$AUTH['sid']}", $KEY);
    global $GUEST;
    return hash_hmac('sha256', "csrf:guest:$GUEST", $KEY);
}
if (preg_match('/^(\d+)\.(\d+)\.([0-9a-f]{32})\.(\d+)\.([0-9a-f]{64})$/', $_COOKIE['tf_auth'] ?? '', $m) && $KEY !== ''
    && hash_equals(hash_hmac('sha256', "$m[1].$m[2].$m[3].$m[4]", $KEY), $m[5]) && (int)$m[4] > time()) {
    $AUTH = ['uid' => (int)$m[1], 'gen' => (int)$m[2], 'sid' => $m[3], 'exp' => (int)$m[4]];
}
// Signed in before this version (PHP session files in /data/sessions): still signed in, and
// moved to the cookie with this reply. Only reads the old file. (Not for ?a=file: the page
// must see the new token in a JSON reply.) Remove once nobody can have one left: 2026-12.
$LEGACY_CSRF = null;
if (!$AUTH && preg_match('/^[\w,-]{20,128}$/', $_COOKIE['tf_session'] ?? '') && is_file($old = "$DATA/sessions/sess_{$_COOKIE['tf_session']}")
    && filemtime($old) > time() - SESSION_DAYS * 86400) {
    $raw = (string)@file_get_contents($old);
    if (preg_match('/(?:^|;)uid\|i:(\d+);/', $raw, $u)) {
        $gen = preg_match('/(?:^|;)gen\|i:(\d+);/', $raw, $g) ? (int)$g[1] : 0;
        if (preg_match('/(?:^|;)csrf\|s:64:"([0-9a-f]{64})";/', $raw, $c)) $LEGACY_CSRF = $c[1];
        if ($a === 'file') $AUTH = ['uid' => (int)$u[1], 'gen' => $gen, 'sid' => str_repeat('0', 32), 'exp' => PHP_INT_MAX];
        else { auth_cookie((int)$u[1], $gen, bin2hex(random_bytes(16))); cookie('tf_session', '', 1); $CSRF_NEW = true; }
    }
}
$GUEST = preg_match('/^[0-9a-f]{32}$/', $_COOKIE['tf_csrf'] ?? '') ? $_COOKIE['tf_csrf'] : '';
if ($GUEST === '') { $GUEST = bin2hex(random_bytes(16)); cookie('tf_csrf', $GUEST, time() + 10 * 365 * 86400); }

$me = null;
if ($AUTH) {
    $st = $db->prepare('SELECT id, username, display_name, bio, avatar_id, bg_public_id, bg_private_id, bg_settings_id, bg_banner_id,
        recovery_hash IS NOT NULL AS has_recovery, session_gen, EXISTS (SELECT 1 FROM signed_out WHERE sid = ?) AS gone FROM users WHERE id = ?');
    $st->execute([$AUTH['sid'], $AUTH['uid']]);
    $me = $st->fetch() ?: null;
    // Signed out on this device, or signed in before the password was reset (by someone with the
    // recovery key): not any more.
    if ($me && ($me['gone'] || (int)$me['session_gen'] !== $AUTH['gen'])) $me = null;
    if (!$me) $AUTH = null;
    // Renewed once a day while you use toffee (a new cookie; nothing is written here).
    elseif ($AUTH['exp'] < time() + (SESSION_DAYS - 1) * 86400) auth_cookie($AUTH['uid'], $AUTH['gen'], $AUTH['sid']);
}
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    // Torii sends writes to the writer; one reaching a read-only copy can only be refused.
    if (!$WRITER) fail('Saving is paused for a moment. Try again.', 503);
    // push_resubscribe comes from the service worker, which has no token: it proves itself with
    // the secret of the subscription it replaces instead (see that action).
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($a !== 'push_resubscribe' && !hash_equals(csrf_token(), $sent) && !($LEGACY_CSRF && hash_equals($LEGACY_CSRF, $sent))) {
        fail('Please reload the page and try again.', 403);
    }
    $isJson = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    // JSON bodies are small (files come as a form, to ?a=upload): nothing big gets read in.
    if ($isJson && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 256 * 1024) fail('That is too much at once.', 413);
    $in = $isJson ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    // Only proper UTF-8 text gets in (broken bytes would break every page that shows it).
    array_walk_recursive($in, function (&$v) { if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) $v = mb_scrub($v, 'UTF-8'); });
}

function need_user(?array $me): array {
    if (!$me) fail('Create a profile or sign in first.', 401);
    return $me;
}
function public_user(array $u): array {
    return [
        'username' => $u['username'],
        'display_name' => $u['display_name'],
        'bio' => $u['bio'] ?? '',
        'avatar' => !empty($u['avatar_id']) ? 'api.php?a=file&v=2&id=' . (int)$u['avatar_id'] : null,
        'pinned' => is_official($u['username']),
    ];
}
function file_url($id): ?string { return $id ? 'api.php?a=file&v=2&id=' . (int)$id : null; }
// An attachment as the app gets it, with its length and cover picture when known (voice notes, videos).
function att_out(array $a): array {
    $o = ['url' => file_url($a['id']), 'kind' => $a['kind'], 'mime' => $a['mime'], 'name' => $a['original'], 'size' => (int)$a['size']];
    if (isset($a['duration'])) $o['duration'] = round((float)$a['duration'], 1);
    if (!empty($a['poster_id'])) $o['poster'] = file_url($a['poster_id']);
    return $o;
}
function backgrounds(array $u, bool $withSettings = false): array {
    $b = ['public' => file_url($u['bg_public_id'] ?? null), 'private' => file_url($u['bg_private_id'] ?? null),
          'banner' => file_url($u['bg_banner_id'] ?? null)];
    if ($withSettings) $b['settings'] = file_url($u['bg_settings_id'] ?? null);
    return $b;
}
// User ids of the existing accounts @named in a comment.
function mention_ids(PDO $db, string $body): array {
    if (!preg_match_all('/@([A-Za-z0-9_-]{3,20})/', $body, $m)) return [];
    $q = $db->prepare('SELECT id FROM users WHERE username = ?');
    $ids = [];
    foreach (array_unique($m[1]) as $name) {
        $q->execute([$name]);
        if ($uid = (int)$q->fetchColumn()) $ids[] = $uid;
    }
    return $ids;
}
// A display name can't copy someone else's name or username (so nobody can pose as them).
function name_taken(PDO $db, string $name, int $exceptId): bool {
    $st = $db->prepare('SELECT 1 FROM users WHERE id != ? AND (lower(display_name) = lower(?) OR lower(username) = lower(?))');
    $st->execute([$exceptId, $name, $name]);
    return (bool)$st->fetchColumn();
}
// Notifications for a new post (never to its author):
//   private reply  -> only its recipient
//   public reply   -> the author of the comment replied to, always
//   @name          -> that person (public posts only; delete the @ and they aren't told)
//   new top comment on a channel -> the channel's owner
//   new public post by toffee's own channel (Help & Feedback) -> everyone: an announcement
function notify_for_post(PDO $db, int $postId): void {
    $st = $db->prepare('SELECT p.*, pp.author_id AS parent_author FROM posts p LEFT JOIN posts pp ON pp.id = p.parent_id WHERE p.id = ?');
    $st->execute([$postId]);
    $p = $st->fetch();
    if (!$p) return;
    $author = (int)$p['author_id'];
    $who = [];   // user_id => kind (first reason wins)
    if ($p['is_private'] && $p['group_id']) {   // a group's message: everyone in it
        $st = $db->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
        $st->execute([$p['group_id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) $who[(int)$uid] = 'private';
    } elseif ($p['is_private']) {
        if ($p['recipient_id']) $who[(int)$p['recipient_id']] = 'private';
    } else {
        if ($p['parent_author']) $who[(int)$p['parent_author']] = 'reply';
        foreach (mention_ids($db, (string)$p['body']) as $uid) if (!isset($who[$uid])) $who[$uid] = 'mention';
        if (!$p['parent_id'] && !isset($who[(int)$p['board_user_id']])) $who[(int)$p['board_user_id']] = 'board';
        if (!$p['parent_id'] && $author === (int)$p['board_user_id']) {
            $st = $db->prepare('SELECT username FROM users WHERE id = ?');
            $st->execute([$author]);
            if (is_official((string)$st->fetchColumn())) {
                foreach ($db->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $uid) $who[(int)$uid] ??= 'announcement';
            }
        }
    }
    unset($who[$author]);
    $ins = $db->prepare('INSERT INTO notifications (user_id, post_id, kind, created_at) VALUES (?, ?, ?, ?)');
    $tx = !$db->inTransaction() && count($who) > 1;   // many at once (Help's news to everyone): one write
    if ($tx) $db->beginTransaction();
    foreach ($who as $uid => $kind) $ins->execute([$uid, $postId, $kind, time()]);
    if ($tx) $db->commit();
    // Phones are told after the reply is sent: posting never waits on a push service.
    global $AFTER;
    if ($who) $AFTER[] = fn() => push_for_post($db, $p, $who);
}

// The same notifications as a phone notification, for everyone who turned them on
// (all phones at once, so a slow push service doesn't hold up the rest).
function push_for_post(PDO $db, array $p, array $who): void {
    global $DATA;
    $st = $db->prepare('SELECT a.display_name, b.username FROM users a, users b WHERE a.id = ? AND b.id = ?');
    $st->execute([$p['author_id'], $p['board_user_id']]);
    $names = $st->fetch();
    $verb = ['private' => 'sent you a private message', 'reply' => 'replied to you', 'mention' => 'mentioned you', 'board' => 'posted on your channel',
             'announcement' => 'posted news'];
    $text = $p['body'] !== '' ? mb_substr((string)$p['body'], 0, 120) : '📎 attachment';
    $root = $p['parent_id'] ? (int)$p['parent_id'] : (int)$p['id'];
    // A private message opens the conversation with its sender (their channel's Private tab).
    $st = $db->prepare('SELECT username FROM users WHERE id = ?');
    $st->execute([$p['author_id']]);
    $c = $p['is_private'] ? (string)$st->fetchColumn() : $names['username'];
    $url = '/?c=' . rawurlencode($c) . '&tab=' . ($p['is_private'] ? 'private' : 'public') . '&post=' . (int)$p['id'];
    // A group's message opens each member's conversation with its starter.
    $groupUrl = [];
    if ($p['is_private'] && !empty($p['group_id'])) {
        $st = $db->prepare('SELECT gm.user_id, u.username FROM group_members gm JOIN users u ON u.id = gm.with_id WHERE gm.group_id = ?');
        $st->execute([$p['group_id']]);
        foreach ($st->fetchAll() as $g) $groupUrl[(int)$g['user_id']] = '/?c=' . rawurlencode($g['username']) . '&tab=private&post=' . (int)$p['id'];
    }
    $subs = $db->prepare('SELECT endpoint, p256dh, auth FROM push_subs WHERE user_id = ?');
    $unread = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $sends = [];
    foreach ($who as $uid => $kind) {
        $subs->execute([$uid]);
        $unread->execute([$uid]);
        $badge = (int)$unread->fetchColumn();   // the number on the app's icon
        foreach ($subs->fetchAll() as $sub) $sends[] = [$sub, [
            'title' => "{$names['display_name']} " . ($verb[$kind] ?? 'posted'),
            'body' => $text, 'url' => $groupUrl[$uid] ?? $url, 'tag' => 'toffee-' . $root, 'badge' => $badge,
        ], $kind === 'private' ? ['urgency' => 'high'] : []];   // a private message wakes a sleeping phone
    }
    push_send($db, $sends);
}
// Send [subscription, message] pairs; forget phones that have unsubscribed.
function push_send(PDO $db, array $sends): void {
    if (!$sends) return;
    $codes = webpush_send_all(vapid($db), $sends, 'mailto:toffee@torii-edge-cloud.duckdns.org');
    $gone = $db->prepare('DELETE FROM push_subs WHERE endpoint = ?');
    foreach ($codes as $endpoint => $code) {
        if ($code === 404 || $code === 410) $gone->execute([$endpoint]);   // unsubscribed / expired
        // Anything else that isn't a success: in the server log, to see why a phone missed it.
        elseif ($code < 200 || $code >= 300) error_log("toffee push: $code from " . parse_url($endpoint, PHP_URL_HOST));
    }
}

// Calls (the endpoints are under CALLS below).
const RING_SECONDS = 45;    // unanswered after this: a missed call
const ALIVE_SECONDS = 40;
const ROOM_MAX = 6;          // people talking at once in a group call (each phone connects to every other)   // a call nobody has said is still going for this long is over (closed tab)
function ice_servers(int $uid): array {
    $out = [['urls' => array_values(array_filter(array_map('trim', explode(',', (string)(getenv('TOFFEE_STUN') ?: 'stun:stun.cloudflare.com:3478')))))]];
    if (($turn = (string)getenv('TOFFEE_TURN_URLS')) !== '' && ($secret = (string)getenv('TOFFEE_TURN_SECRET')) !== '') {
        $name = (time() + 6 * 3600) . ":$uid";
        $out[] = ['urls' => array_map('trim', explode(',', $turn)), 'username' => $name,
            'credential' => base64_encode(hash_hmac('sha1', $name, $secret, true))];
    }
    return $out;
}
// The phone notification for a call: it stays on screen, and each new one (same tag) rings again.
function ring_phones(PDO $db, array $from, int $to, int $id, bool $video): void {
    $subs = $db->prepare('SELECT endpoint, p256dh, auth FROM push_subs WHERE user_id = ?');
    $subs->execute([$to]);
    push_send($db, array_map(fn($s) => [$s, ['title' => ($video ? '🎥 ' : '📞 ') . "{$from['display_name']} is calling",
        'body' => $video ? 'Video call: tap to answer' : 'Voice call: tap to answer', 'call' => true,
        'url' => '/?c=' . rawurlencode((string)$from['username']) . '&tab=private', 'tag' => "toffee-call-$id"],
        ['urgency' => 'high', 'ttl' => RING_SECONDS, 'topic' => "call$id"]], $subs->fetchAll()));   // wakes the phone; never arrives after the ringing
}
// A call that's still on (ringing, or answered and alive) for this person, if any.
function call_busy(PDO $db, int $uid): bool {
    $st = $db->prepare("SELECT 1 FROM calls WHERE (caller_id = :u OR callee_id = :u) AND ended_at IS NULL
        AND ((state = 'ringing' AND created_at > :ring) OR (state = 'accepted' AND alive_at > :alive)) LIMIT 1");
    $st->execute([':u' => $uid, ':ring' => time() - RING_SECONDS, ':alive' => time() - ALIVE_SECONDS]);
    return (bool)$st->fetchColumn() || in_room($db, $uid) !== null;
}
// Group calls: the room you're talking in (joined, and still alive), if any.
function in_room(PDO $db, int $uid): ?int {
    $st = $db->prepare("SELECT rp.room_id FROM room_people rp JOIN rooms r ON r.id = rp.room_id
        WHERE rp.user_id = ? AND rp.state = 'in' AND rp.alive_at > ? AND r.ended_at IS NULL LIMIT 1");
    $st->execute([$uid, time() - ALIVE_SECONDS]);
    return ($id = $st->fetchColumn()) ? (int)$id : null;
}
// Of those talking, the ones $uid should send an offer to: only the later of two sends one (so
// two never cross); the same second: the higher id. Checked on every room_state, so both always agree.
function room_offer_to(PDO $db, int $roomId, int $uid, array $live): array {
    $st = $db->prepare('SELECT user_id, joined_at FROM room_people WHERE room_id = ?');
    $st->execute([$roomId]);
    $since = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    $mine = $since[$uid] ?? PHP_INT_MAX;
    return array_values(array_filter($live, fn($x) => $x !== $uid && (($since[$x] ?? 0) < $mine || (($since[$x] ?? 0) === $mine && $x < $uid))));
}
// Who's talking in a room right now (joined and alive).
function room_live(PDO $db, int $roomId): array {
    $st = $db->prepare("SELECT user_id FROM room_people WHERE room_id = ? AND state = 'in' AND alive_at > ?");
    $st->execute([$roomId, time() - ALIVE_SECONDS]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
// A group's call that's on: started a moment ago (still ringing) or someone's in it.
function room_on(PDO $db, int $groupId): ?array {
    $st = $db->prepare('SELECT * FROM rooms WHERE group_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$groupId]);
    $r = $st->fetch();
    if (!$r) return null;
    return room_live($db, (int)$r['id']) || $r['created_at'] > time() - 10 ? $r : null;   // (nobody in: over, worked out here, so a GET writes nothing)
}
// A group call ringing for you (someone in it, you not answered yet).
function room_ring_for(PDO $db, int $uid): ?array {
    $st = $db->prepare("SELECT r.id, r.video, r.group_id, r.created_at, u.username, u.display_name, u.avatar_id FROM room_people rp JOIN rooms r ON r.id = rp.room_id
        JOIN users u ON u.id = COALESCE(rp.added_by, r.started_by) WHERE rp.user_id = ? AND rp.state = 'ringing' AND r.ended_at IS NULL
        AND COALESCE(rp.joined_at, r.created_at) > CAST(? AS INTEGER) ORDER BY r.id DESC LIMIT 1");   // (CAST: PDO sends text, and an expression has no column type to turn it back into a number)
    $st->execute([$uid, time() - RING_SECONDS]);
    $r = $st->fetch();
    if (!$r || !room_live($db, (int)$r['id'])) return null;
    return ['room' => (int)$r['id'], 'video' => (bool)$r['video'], 'group' => $r['group_id'] ? (int)$r['group_id'] : null,
        'from' => ['username' => $r['username'], 'display_name' => $r['display_name'], 'avatar' => file_url($r['avatar_id'])]];
}
function my_room(PDO $db, int $id, int $uid): array {
    // A group's call: its members. A call between two that grew: the people brought into it.
    $st = $db->prepare('SELECT r.* FROM rooms r WHERE r.id = :id AND (
        (r.group_id IS NOT NULL AND EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = r.group_id AND gm.user_id = :u))
        OR (r.group_id IS NULL AND EXISTS (SELECT 1 FROM room_people rp WHERE rp.room_id = r.id AND rp.user_id = :u)))');
    $st->execute([':u' => $uid, ':id' => $id]);
    $r = $st->fetch();
    if (!$r) fail('Call not found.', 404);
    return $r;
}
function ringing_for(PDO $db, int $uid): ?array {
    $st = $db->prepare("SELECT c.id, c.video, u.username, u.display_name, u.avatar_id FROM calls c JOIN users u ON u.id = c.caller_id
        WHERE c.callee_id = ? AND c.state = 'ringing' AND c.ended_at IS NULL AND c.created_at > ? ORDER BY c.id DESC LIMIT 1");
    $st->execute([$uid, time() - RING_SECONDS]);
    $c = $st->fetch();
    return $c ? ['id' => (int)$c['id'], 'video' => (bool)$c['video'],
        'from' => ['username' => $c['username'], 'display_name' => $c['display_name'], 'avatar' => file_url($c['avatar_id'])]] : null;
}
function my_call(PDO $db, int $id, int $uid): array {
    $st = $db->prepare('SELECT * FROM calls WHERE id = ? AND (caller_id = ? OR callee_id = ?)');
    $st->execute([$id, $uid, $uid]);
    $c = $st->fetch();
    if (!$c) fail('Call not found.', 404);
    // Ringing too long: missed (worked out here, so a GET writes nothing).
    if ($c['state'] === 'ringing' && $c['created_at'] <= time() - RING_SECONDS) $c['state'] = 'missed';
    if ($c['state'] === 'accepted' && $c['alive_at'] <= time() - ALIVE_SECONDS) $c['state'] = 'ended';
    return $c;
}

// The visitor's address. Traefik adds the address it saw to the END of X-Forwarded-For, so
// only the last entry is real; anything before it was sent by the visitor and can be made up.
// The header is only believed from a proxy on a private network, never from the internet.
function client_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '?';
    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    $fromProxy = !filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($xff === '' || !$fromProxy) return $remote;
    $last = trim((string)substr(strrchr(",$xff", ','), 1));
    return filter_var($last, FILTER_VALIDATE_IP) ? $last : $remote;
}
// Rate limits: refuse once $key (an IP) has done $kind $limit times in the last $window seconds.
function throttle(PDO $db, string $kind, string $key, int $limit, int $window, string $msg): void {
    $st = $db->prepare('SELECT COUNT(*) FROM throttle WHERE kind = ? AND k = ? AND at >= ?');
    $st->execute([$kind, $key, time() - $window]);
    if ((int)$st->fetchColumn() >= $limit) fail($msg, 429);
}
function throttle_hit(PDO $db, string $kind, string $key): void {
    $db->prepare('INSERT INTO throttle (kind, k, at) VALUES (?, ?, ?)')->execute([$kind, $key, time()]);
    $db->prepare('DELETE FROM throttle WHERE at < ?')->execute([time() - 86400]);   // nothing older than a day matters
}

// Delete uploads (rows and files) matching $where that nothing uses any more: no comment,
// no profile picture or background, and not in someone's sticker/GIF collection.
function gc_uploads(PDO $db, string $dir, string $where, array $params): void {
    $st = $db->prepare("SELECT id, name FROM uploads u WHERE ($where) AND library = 0
        AND NOT EXISTS (SELECT 1 FROM posts WHERE upload_id = u.id)
        AND NOT EXISTS (SELECT 1 FROM post_uploads WHERE upload_id = u.id)
        AND NOT EXISTS (SELECT 1 FROM users WHERE u.id IN (avatar_id, bg_public_id, bg_private_id, bg_settings_id, bg_banner_id))
        AND NOT EXISTS (SELECT 1 FROM uploads v WHERE v.poster_id = u.id)");
    $st->execute($params);
    $del = $db->prepare('DELETE FROM uploads WHERE id = ?');
    foreach ($st->fetchAll() as $up) {
        $del->execute([$up['id']]);
        remove_file($up['name']);
    }
}
// A file in S3 (its key has a /: u/<owner>/<random>) or in /data/uploads. From S3 after the
// reply is sent, so deleting a profile with many files doesn't keep you waiting.
function remove_file(string $name): void {
    global $UPLOADS, $AFTER;
    if (str_contains($name, '/')) $AFTER[] = fn() => s3_delete($name);
    else @unlink("$UPLOADS/$name");
}

// A short fingerprint of what you can see on a channel: it changes when a post is added,
// edited or deleted, or a reaction changes. Polling compares it instead of reloading everything.
function board_sig(PDO $db, int $boardId, int $uid): string {
    $st = $db->prepare('SELECT COUNT(*), COALESCE(MAX(id), 0), COALESCE(MAX(edited_at), 0) FROM posts
        WHERE board_user_id = :b AND (is_private = 0 OR author_id = :u OR recipient_id = :u)');
    $st->execute([':b' => $boardId, ':u' => $uid]);
    $posts = implode('.', $st->fetch(PDO::FETCH_NUM));
    $st = $db->prepare('SELECT COUNT(*), TOTAL(pr.user_id * unicode(pr.emoji)) FROM post_reactions pr JOIN posts p ON p.id = pr.post_id
        WHERE p.board_user_id = :b AND (p.is_private = 0 OR p.author_id = :u OR p.recipient_id = :u)');
    $st->execute([':b' => $boardId, ':u' => $uid]);
    return $posts . '.' . implode('.', $st->fetch(PDO::FETCH_NUM));
}
// Fingerprint of everything the home feed (?a=channels) shows: public posts (new, edited,
// deleted, replies), reactions on them, and people (new, gone, a new name, bio or picture).
function feed_sig(PDO $db): string {
    return 'f' . (string)$db->query("SELECT v FROM settings WHERE k = 'feed_ver'")->fetchColumn();   // (kept by triggers: see version 11)
}
// Recovery keys: TOFF- and 16 characters (80 random bits) in the Crockford alphabet, which has
// no I, L, O or U, so a key read off paper is hard to get wrong. Only a hash is kept; with 80
// random bits a fast hash is safe (unlike a password, a key can't be guessed).
const KEY_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
function recovery_hash(string $key): string {
    $k = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key));
    if (str_starts_with($k, 'TOFF')) $k = substr($k, 4);
    $k = strtr($k, ['O' => '0', 'I' => '1', 'L' => '1']);   // easy misreadings
    return hash('sha256', "toffee-recovery:$k");
}
function new_recovery_key(PDO $db, int $uid): string {
    $chars = '';
    for ($i = 0; $i < 16; $i++) $chars .= KEY_ALPHABET[random_int(0, 31)];
    $key = 'TOFF-' . implode('-', str_split($chars, 4));
    $db->prepare('UPDATE users SET recovery_hash = ? WHERE id = ?')->execute([recovery_hash($key), $uid]);
    return $key;
}
function sign_in(PDO $db, int $uid): void {
    global $me, $CSRF_NEW;
    $st = $db->prepare('SELECT session_gen FROM users WHERE id = ?');
    $st->execute([$uid]);
    auth_cookie($uid, (int)$st->fetchColumn(), bin2hex(random_bytes(16)));
    $me = ['id' => $uid];   // (for the token in this reply)
    $CSRF_NEW = true;
}
function sign_out(PDO $db): void {
    global $AUTH, $me, $CSRF_NEW;
    if ($AUTH) {
        $db->prepare('INSERT OR IGNORE INTO signed_out (sid, until) VALUES (?, ?)')->execute([$AUTH['sid'], $AUTH['exp']]);
        $db->prepare('DELETE FROM signed_out WHERE until < ?')->execute([time()]);   // cookies past their date sign nobody in anyway
    }
    cookie('tf_auth', '', 1);
    cookie('tf_session', '', 1);
    $AUTH = $me = null;
    $CSRF_NEW = true;
}
function valid_sub(array $in): array {
    $endpoint = (string)($in['endpoint'] ?? '');
    $keys = (array)($in['keys'] ?? []);
    if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 1000 || empty($keys['p256dh']) || empty($keys['auth'])) fail('That subscription looks wrong.');
    // Only the browsers' own push services: the Pi never sends requests anywhere else (not to
    // addresses inside the network either).
    $host = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
    $ok = $host === 'fcm.googleapis.com' || $host === 'web.push.apple.com' || $host === 'push.services.mozilla.com'
        || preg_match('/\.(push\.apple\.com|push\.services\.mozilla\.com|notify\.windows\.com)$/', $host)
        || preg_match('/(^|\.)push\.invalid$/', $host);   // (the tests' own: .invalid never reaches any computer)
    if (!$ok || parse_url($endpoint, PHP_URL_PORT) !== null) fail('That subscription looks wrong.');
    if (strlen(b64url_decode((string)$keys['p256dh'])) !== 65 || strlen(b64url_decode((string)$keys['auth'])) !== 16) fail('That subscription looks wrong.');
    if (p256_public_from_raw(b64url_decode((string)$keys['p256dh'])) === false) fail('That subscription looks wrong.');   // (a real key: a fake one broke everyone's pushes in its batch)
    return [$endpoint, (string)$keys['p256dh'], (string)$keys['auth']];
}

// The private messages between you and $other (whoever's channel they were written in), or
// your notes to yourself when $other is you.
// A group's messages show in each member's conversation with whoever started it (the starter:
// with the person it was first sent to), never in conversations between other members.
function pair_sql(int $me, int $other): array {
    if ($me === $other) return ['(author_id = :u AND recipient_id = :u AND group_id IS NULL)', [':u' => $me]];
    return ['(((author_id = :u AND recipient_id = :o) OR (author_id = :o AND recipient_id = :u)) AND group_id IS NULL
        OR group_id IN (SELECT gm.group_id FROM group_members gm WHERE gm.user_id = :u AND gm.with_id = :o))', [':u' => $me, ':o' => $other]];
}
// Fingerprint of a conversation (as board_sig is of a channel).
function conv_sig(PDO $db, int $me, int $other): string {
    [$pair, $args] = pair_sql($me, $other);
    $st = $db->prepare("SELECT COUNT(*), COALESCE(MAX(id), 0), COALESCE(MAX(edited_at), 0) FROM posts WHERE is_private = 1 AND $pair");
    $st->execute($args);
    $posts = implode('.', $st->fetch(PDO::FETCH_NUM));
    $st = $db->prepare("SELECT COUNT(*), TOTAL(pr.user_id * unicode(pr.emoji)) FROM post_reactions pr JOIN posts p ON p.id = pr.post_id
        WHERE p.is_private = 1 AND " . str_replace(['author_id', 'recipient_id'], ['p.author_id', 'p.recipient_id'], $pair));
    $st->execute($args);
    return "c$posts." . implode('.', $st->fetch(PDO::FETCH_NUM));
}

// Who may see a post: public posts are for everyone; private ones only for
// their author and recipient (a group's: its members).
function can_see(array $p, ?array $me): bool {
    if (!$p['is_private']) return true;
    if (!array_key_exists('group_id', $p)) throw new LogicException('can_see needs group_id');   // (never decide without it)
    if ($p['group_id']) return $me && group_member((int)$p['group_id'], (int)$me['id']);
    return $me && ((int)$p['author_id'] === (int)$me['id'] || (int)$p['recipient_id'] === (int)$me['id']);
}
function group_member(int $gid, int $uid): bool {
    global $db;
    static $seen = [];
    if (!isset($seen["$gid.$uid"])) {
        $st = $db->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
        $st->execute([$gid, $uid]);
        $seen["$gid.$uid"] = (bool)$st->fetchColumn();
    }
    return $seen["$gid.$uid"];
}
// Add people to a group (if not in it yet). They see it in their conversation with its starter.
function group_add(PDO $db, int $gid, array $uids, int $starter): array {
    $add = $db->prepare('INSERT OR IGNORE INTO group_members (group_id, user_id, with_id, added_at) VALUES (?, ?, ?, ?)');
    $new = [];
    foreach ($uids as $uid) { $add->execute([$gid, $uid, $starter, time()]); if ($add->rowCount()) $new[] = (int)$uid; }
    return $new;
}
// A group's members, for the "Members" list.
function group_people(PDO $db, array $gids): array {
    if (!$gids) return [];
    $out = [];
    foreach ($db->query('SELECT gm.group_id, u.username, u.display_name, u.avatar_id FROM group_members gm JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id IN (' . implode(',', array_map('intval', $gids)) . ') ORDER BY gm.added_at, u.display_name COLLATE NOCASE')->fetchAll() as $m) {
        $out[(int)$m['group_id']][] = ['username' => $m['username'], 'display_name' => $m['display_name'], 'avatar' => file_url($m['avatar_id'])];
    }
    return $out;
}

switch ($a) {

// ---- session -----------------------------------------------------------------
case 'me':
    out(['ok' => true, 'csrf' => csrf_token(), 'user' => $me ? public_user($me) + ['backgrounds' => backgrounds($me, true),
        'has_recovery' => (bool)$me['has_recovery']] : null]);

case 'register': {
    if (!$isPost) fail('POST only', 405);
    $u = trim((string)($in['username'] ?? ''));
    $p = (string)($in['password'] ?? '');
    $d = trim((string)($in['display_name'] ?? '')) ?: $u;
    // At most 5 new profiles an hour from one address: no bulk sign-ups (a whole household still fits).
    throttle($db, 'register', client_ip(), 5, 3600, 'Too many new profiles from here. Try again in an hour.');
    if (!preg_match('/^[A-Za-z0-9_-]{3,20}$/', $u)) fail('Username: 3-20 letters, numbers, - or _.');
    if (strlen($p) < 8) fail('Password: at least 8 characters.');
    if (mb_strlen($d) > 20) fail('Display name: up to 20 characters.');
    if (name_taken($db, $d, 0)) fail('That display name belongs to someone else. Please pick a different one.');
    try {
        // session_gen starts random: SQLite may give a deleted profile's id to the next one, and a
        // cookie still on the old owner's other phone must not sign them in as the new person.
        $db->prepare('INSERT INTO users (username, pass, display_name, created_at, session_gen) VALUES (?, ?, ?, ?, ' . random_int(1, 2 ** 31) . ')')
           ->execute([$u, password_hash($p, PASSWORD_DEFAULT), $d, time()]);
    } catch (PDOException $e) {
        fail('That username is taken.');
    }
    $uid = (int)$db->lastInsertId();
    throttle_hit($db, 'register', client_ip());
    sign_in($db, $uid);
    out(['ok' => true, 'recovery_key' => new_recovery_key($db, $uid)]);   // shown once, to be saved
}

case 'login': {
    if (!$isPost) fail('POST only', 405);
    $ip = client_ip();
    throttle($db, 'login', $ip, 10, 600, 'Too many attempts. Try again in a few minutes.');   // 10 wrong passwords per 10 minutes
    $st = $db->prepare('SELECT id, pass, recovery_hash FROM users WHERE username = ?');
    $st->execute([trim((string)($in['username'] ?? ''))]);
    $row = $st->fetch();
    if (!$row || !password_verify((string)($in['password'] ?? ''), $row['pass'])) {
        throttle_hit($db, 'login', $ip);
        fail('Wrong username or password.');
    }
    sign_in($db, (int)$row['id']);
    // Profiles from before recovery keys get theirs now.
    out(['ok' => true] + ($row['recovery_hash'] ? [] : ['recovery_key' => new_recovery_key($db, (int)$row['id'])]));
}

// Forgot your password: your username and recovery key set a new one. Every other device is
// signed out, the old key stops working, and you get a new key to save.
case 'recover': {
    if (!$isPost) fail('POST only', 405);
    $ip = client_ip();
    throttle($db, 'recover', $ip, 10, 3600, 'Too many attempts. Try again in an hour.');
    $p = (string)($in['password'] ?? '');
    if (strlen($p) < 8) fail('New password: at least 8 characters.');
    $st = $db->prepare('SELECT id, recovery_hash FROM users WHERE username = ?');
    $st->execute([trim((string)($in['username'] ?? ''))]);
    $row = $st->fetch();
    if (!$row || !$row['recovery_hash'] || !hash_equals($row['recovery_hash'], recovery_hash((string)($in['key'] ?? '')))) {
        throttle_hit($db, 'recover', $ip);
        fail("That username and recovery key don't match.");
    }
    $db->prepare('UPDATE users SET pass = ?, session_gen = session_gen + 1 WHERE id = ?')
       ->execute([password_hash($p, PASSWORD_DEFAULT), $row['id']]);
    sign_in($db, (int)$row['id']);
    out(['ok' => true, 'recovery_key' => new_recovery_key($db, (int)$row['id'])]);
}

// A new recovery key (the old one stops working). Your password is asked for, so someone who
// picks up your unlocked phone can't quietly make a key of their own.
// Delete your profile and everything in it: your posts, replies and messages (and the replies
// others left on your posts), reactions, notifications, phones, and every file you uploaded,
// from the disk too. Needs your password. toffee's own channels can't be deleted.
case 'delete_account': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $uid = (int)$u['id'];
    if (is_official((string)$u['username'])) fail("toffee's own channels can't be deleted.", 403);
    $ip = client_ip();
    throttle($db, 'login', $ip, 10, 600, 'Too many attempts. Try again in a few minutes.');
    $st = $db->prepare('SELECT pass FROM users WHERE id = ?');
    $st->execute([$uid]);
    if (!password_verify((string)($in['password'] ?? ''), (string)$st->fetchColumn())) {
        throttle_hit($db, 'login', $ip);
        fail('Wrong password.');
    }
    // Everything that goes with it (the database does the deleting, through ON DELETE CASCADE):
    // posts you wrote, posts on your channel, messages to you, and every reply under those.
    // Note their files first, so they can be removed from the disk afterwards.
    $tree = 'WITH RECURSIVE t(id) AS (SELECT id FROM posts WHERE author_id = :u OR recipient_id = :u OR board_user_id = :u
        UNION SELECT p.id FROM posts p JOIN t ON p.parent_id = t.id)';
    $st = $db->prepare("$tree SELECT upload_id FROM posts WHERE id IN (SELECT id FROM t) AND upload_id IS NOT NULL
        UNION SELECT upload_id FROM post_uploads WHERE post_id IN (SELECT id FROM t)");
    $st->execute([':u' => $uid]);
    $theirs = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));   // others' files in replies that go too
    $st = $db->prepare('SELECT name FROM uploads WHERE owner_id = ?');
    $st->execute([$uid]);
    $files = $st->fetchAll(PDO::FETCH_COLUMN);
    $db->beginTransaction();
    $db->prepare("$tree DELETE FROM posts WHERE id IN (SELECT id FROM t)")->execute([':u' => $uid]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);   // uploads, reactions, notifications, phones...
    $db->commit();
    foreach ($files as $name) remove_file($name);
    if ($theirs) gc_uploads($db, $UPLOADS, 'id IN (' . implode(',', $theirs) . ')', []);
    // Signed out here; any other device is signed out by the profile being gone.
    $AUTH = null;
    sign_out($db);
    out(['ok' => true]);
}

case 'recovery_new': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $ip = client_ip();
    throttle($db, 'login', $ip, 10, 600, 'Too many attempts. Try again in a few minutes.');
    $st = $db->prepare('SELECT pass FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    if (!password_verify((string)($in['password'] ?? ''), (string)$st->fetchColumn())) {
        throttle_hit($db, 'login', $ip);
        fail('Wrong password.');
    }
    out(['ok' => true, 'recovery_key' => new_recovery_key($db, (int)$u['id'])]);
}

case 'logout':
    if (!$isPost) fail('POST only', 405);
    sign_out($db);
    out(['ok' => true]);

case 'profile': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $d = trim((string)($in['display_name'] ?? $u['display_name']));
    $b = trim((string)($in['bio'] ?? $u['bio']));
    if ($d === '' || mb_strlen($d) > 20) fail('Display name: 1-20 characters.');
    if (name_taken($db, $d, (int)$u['id'])) fail('That display name belongs to someone else. Please pick a different one.');
    if (mb_strlen($b) > 160) fail('Bio: up to 160 characters.');
    $avatar = $u['avatar_id'];
    if (($in['avatar_id'] ?? '') === 'none') {
        $avatar = null;   // remove the profile picture
    } elseif (isset($in['avatar_id']) && $in['avatar_id'] !== '') {
        $st = $db->prepare("SELECT id FROM uploads WHERE id = ? AND owner_id = ? AND kind = 'image'");
        $st->execute([(int)$in['avatar_id'], $u['id']]);
        $avatar = $st->fetchColumn() ?: $avatar;
    }
    $db->prepare('UPDATE users SET display_name = ?, bio = ?, avatar_id = ? WHERE id = ?')
       ->execute([$d, $b, $avatar, $u['id']]);
    // New picture: post it to your own thread, so people can react and reply
    // (publicly or privately) like any comment.
    if ($avatar && (int)$avatar !== (int)$u['avatar_id']) {
        $db->prepare('INSERT INTO posts (board_user_id, author_id, body, upload_id, created_at) VALUES (?, ?, ?, ?, ?)')
           ->execute([$u['id'], $u['id'], '📷 New profile picture', $avatar, time()]);
    }
    out(['ok' => true]);
}

// ---- background notifications (Web Push) -----------------------------------------
case 'push_key': {
    need_user($me);
    out(['ok' => true, 'key' => vapid($db)['public']]);
}
case 'push_subscribe': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    [$endpoint, $p256dh, $auth] = valid_sub($in);
    $db->prepare('INSERT OR REPLACE INTO push_subs (endpoint, user_id, p256dh, auth, created_at) VALUES (?, ?, ?, ?, ?)')
       ->execute([$endpoint, $u['id'], $p256dh, $auth, time()]);
    out(['ok' => true]);
}
// The browser replaced a phone's subscription (Apple and Google do this now and then). The
// service worker sends the new one with the old one's endpoint and secret, which only that
// phone knows, so notifications carry on for the same person without opening toffee.
case 'push_resubscribe': {
    if (!$isPost) fail('POST only', 405);
    $st = $db->prepare('SELECT user_id, auth FROM push_subs WHERE endpoint = ?');
    $st->execute([(string)($in['old_endpoint'] ?? '')]);
    $old = $st->fetch();
    if (!$old || !hash_equals($old['auth'], (string)($in['old_auth'] ?? ''))) fail('Unknown subscription.', 404);
    [$endpoint, $p256dh, $auth] = valid_sub((array)($in['sub'] ?? []));
    $db->prepare('DELETE FROM push_subs WHERE endpoint = ?')->execute([(string)$in['old_endpoint']]);
    $db->prepare('INSERT OR REPLACE INTO push_subs (endpoint, user_id, p256dh, auth, created_at) VALUES (?, ?, ?, ?, ?)')
       ->execute([$endpoint, $old['user_id'], $p256dh, $auth, time()]);
    out(['ok' => true]);
}
// "Send me a test": a notification to every phone and browser you turned them on for.
case 'push_test': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $st = $db->prepare('SELECT endpoint, p256dh, auth FROM push_subs WHERE user_id = ?');
    $st->execute([$u['id']]);
    $subs = $st->fetchAll();
    if (!$subs) fail('Notifications are not on for any of your devices yet.');
    $msg = ['title' => 'toffee', 'body' => 'Notifications work 🎉', 'url' => '/', 'tag' => 'toffee-test'];
    $codes = webpush_send_all(vapid($db), array_map(fn($s) => [$s, $msg], $subs), 'mailto:toffee@torii-edge-cloud.duckdns.org');
    $gone = $db->prepare('DELETE FROM push_subs WHERE endpoint = ?');
    foreach ($codes as $endpoint => $code) if ($code === 404 || $code === 410) $gone->execute([$endpoint]);
    $sent = count(array_filter($codes, fn($c) => $c >= 200 && $c < 300));
    out(['ok' => true, 'sent' => $sent, 'devices' => count($codes)]);
}
case 'push_unsubscribe': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $db->prepare('DELETE FROM push_subs WHERE endpoint = ? AND user_id = ?')->execute([(string)($in['endpoint'] ?? ''), $u['id']]);
    out(['ok' => true]);
}

// Background picture for your channel's Public tab, Private tab, or your profile pages.
case 'background': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $cols = ['public' => 'bg_public_id', 'private' => 'bg_private_id', 'settings' => 'bg_settings_id', 'banner' => 'bg_banner_id'];
    $col = $cols[(string)($in['which'] ?? '')] ?? null;
    if (!$col) fail('Unknown background.');
    $id = null;
    if (!empty($in['upload_id'])) {
        $st = $db->prepare("SELECT id FROM uploads WHERE id = ? AND owner_id = ? AND kind = 'image'");
        $st->execute([(int)$in['upload_id'], $u['id']]);
        $id = $st->fetchColumn() ?: fail('Upload the picture again.');
    }
    $db->prepare("UPDATE users SET $col = ? WHERE id = ?")->execute([$id, $u['id']]);
    out(['ok' => true]);
}

// ---- channels: everyone with a profile, newest public activity first ----------------
case 'channels': {
    need_user($me);
    // Each channel with its latest public post, so the list shows what's new without opening them.
    // toffee's own channels first, then the most recently active (so if there are ever more than 500
    // people, the quietest ones are left out, not the newest).
    $open = implode(',', array_map(fn($n) => $db->quote($n), array_keys(OFFICIAL_CHANNELS)));
    $rows = $db->query("
        SELECT u.id, u.username, u.display_name, u.bio, u.avatar_id, u.created_at,
               lp.id AS lp_id, lp.body AS lp_body, lp.created_at AS lp_at, la.display_name AS lp_author,
               la.username AS lp_author_user, la.avatar_id AS lp_author_avatar, lp.edited_at AS lp_edited,
               -- every public reply under it, replies of replies too (as the channel counts them)
               (WITH RECURSIVE t(id) AS (SELECT lp.id UNION ALL SELECT r.id FROM posts r JOIN t ON r.parent_id = t.id AND r.is_private = 0)
                SELECT COUNT(*) - 1 FROM t) AS lp_replies
        FROM users u
        LEFT JOIN posts lp ON lp.id = (SELECT MAX(p.id) FROM posts p
                                       WHERE p.board_user_id = u.id AND p.is_private = 0 AND p.parent_id IS NULL)
        LEFT JOIN users la ON la.id = lp.author_id
        ORDER BY u.username IN ($open) DESC, lp.created_at IS NULL, lp.created_at DESC, u.id
        LIMIT 500")->fetchAll();
    // Each channel's public posts from the last 24 hours (up to 10, newest first): on home you
    // swipe through them on the channel's card, like a status.
    $st = $db->prepare('SELECT p.id, p.board_user_id, p.body, p.created_at AS at, p.edited_at AS edited, a.display_name AS author,
            a.username AS author_user, a.avatar_id AS author_avatar,
            (WITH RECURSIVE t(id) AS (SELECT p.id UNION ALL SELECT r.id FROM posts r JOIN t ON r.parent_id = t.id AND r.is_private = 0)
             SELECT COUNT(*) - 1 FROM t) AS replies
        FROM posts p JOIN users a ON a.id = p.author_id
        WHERE p.is_private = 0 AND p.parent_id IS NULL AND p.created_at > ? ORDER BY p.id DESC');
    $st->execute([time() - 86400]);
    $today = [];
    foreach ($st->fetchAll() as $t) if (count($today[(int)$t['board_user_id']] ?? []) < 10) $today[(int)$t['board_user_id']][] = $t;
    // Reactions on those posts, so you can see and add them from home.
    $ids = array_merge(array_map('intval', array_filter(array_column($rows, 'lp_id'))), ...array_map(fn($l) => array_map('intval', array_column($l, 'id')), array_values($today) ?: [[]]));
    $in = implode(',', $ids) ?: '0';
    $rx = [];
    foreach ($db->query("SELECT post_id, emoji, user_id FROM post_reactions WHERE post_id IN ($in)")->fetchAll() as $r) {
        $rx[$r['post_id']]['counts'][$r['emoji']] = ($rx[$r['post_id']]['counts'][$r['emoji']] ?? 0) + 1;
        if ((int)$r['user_id'] === (int)$me['id']) $rx[$r['post_id']]['mine'] = $r['emoji'];
    }
    // And their attachments (photos, videos, voice notes, documents), shown as in the channel.
    $atts = [];
    foreach ($db->query("SELECT pu.post_id, up.id, up.kind, up.mime, up.original, up.size, up.duration, up.poster_id FROM post_uploads pu
        JOIN uploads up ON up.id = pu.upload_id WHERE pu.post_id IN ($in) ORDER BY pu.pos")->fetchAll() as $att) {
        $atts[$att['post_id']][] = att_out($att);
    }
    foreach ($db->query("SELECT p.id AS post_id, up.id, up.kind, up.mime, up.original, up.size, up.duration, up.poster_id FROM posts p JOIN uploads up ON up.id = p.upload_id
        WHERE p.id IN ($in)")->fetchAll() as $att) {   // older posts: their one attachment
        $atts[$att['post_id']] ??= [att_out($att)];
    }
    // A post as home shows it (the latest, and each of today's).
    $card = function (array $c) use ($rx, $atts, $me): array {
        $id = (int)$c['id'];
        return ['id' => $id, 'at' => (int)$c['at'], 'author' => $c['author'],
            'author_user' => ['username' => $c['author_user'], 'display_name' => $c['author'], 'avatar' => file_url($c['author_avatar'])],
            'mine' => $c['author_user'] === $me['username'], 'edited' => !empty($c['edited']),
            'text' => mb_substr((string)$c['body'], 0, 1000), 'replies' => (int)$c['replies'],
            'reactions' => $rx[$id]['counts'] ?? (object)[], 'my_reaction' => $rx[$id]['mine'] ?? null,
            'attachments' => $atts[$id] ?? []];
    };
    $list = [];
    foreach ($rows as $row) {
        $list[] = public_user($row) + [
            'is_me' => $me && (int)$row['id'] === (int)$me['id'],
            'latest' => $row['lp_id'] ? $card(['id' => $row['lp_id'], 'at' => $row['lp_at'], 'author' => $row['lp_author'], 'author_user' => $row['lp_author_user'],
                'author_avatar' => $row['lp_author_avatar'], 'edited' => $row['lp_edited'], 'body' => $row['lp_body'], 'replies' => $row['lp_replies']]) : null,
            'today' => array_map($card, $today[(int)$row['id']] ?? []),
        ];
    }
    out(['ok' => true, 'channels' => $list]);
}

// ---- threads -------------------------------------------------------------------
// Public tab (?tab=public): the channel's posts, newest top comments first, ROOTS_PAGE at a
// time (?roots=N for more), each with all its replies.
// Private tab (?tab=private): your whole conversation with the channel's owner, from both
// channels (whoever's channel each message was written in): the newest ROOTS_PAGE messages
// that start a thread (?roots=N for more), each with its replies; a reply carries a 'quote' of
// what it answers. On your own channel that's your notes to yourself (the conversations list
// is ?a=conversations).
// ?include=ID loads back far enough to show that post (opening a notification).
// 'sig' is the fingerprint for polling (see board_sig / conv_sig).
case 'thread': {
    need_user($me);
    $st = $db->prepare('SELECT id, username, display_name, bio, avatar_id, bg_public_id, bg_private_id, bg_banner_id FROM users WHERE username = ?');
    $st->execute([(string)($_GET['user'] ?? '')]);
    $board = $st->fetch();
    if (!$board) fail('Channel not found.', 404);
    $priv = ($_GET['tab'] ?? '') === 'private' ? 1 : 0;
    $n = max(1, min(1000, (int)($_GET['roots'] ?? ROOTS_PAGE)));
    if ($priv) {
        [$pair, $pargs] = pair_sql((int)$me['id'], (int)$board['id']);
        // Threads, as on the Public tab: the newest $n messages that start one, each with every
        // reply under it (replies of a private message stay between the same two people).
        if (!empty($_GET['include'])) {
            $st = $db->prepare('WITH RECURSIVE up(id, parent_id) AS (SELECT id, parent_id FROM posts WHERE id = ?
                UNION ALL SELECT p.id, p.parent_id FROM posts p JOIN up ON p.id = up.parent_id) SELECT id FROM up WHERE parent_id IS NULL');
            $st->execute([(int)$_GET['include']]);
            if ($rootId = (int)$st->fetchColumn()) {
                $st = $db->prepare("SELECT COUNT(*) FROM posts WHERE is_private = 1 AND parent_id IS NULL AND $pair AND id >= :inc");
                $st->execute($pargs + [':inc' => $rootId]);
                $n = max($n, min(1000, (int)$st->fetchColumn()));
            }
        }
        $st = $db->prepare("SELECT id FROM posts WHERE is_private = 1 AND parent_id IS NULL AND $pair ORDER BY id DESC LIMIT " . ($n + 1));
        $st->execute($pargs);
        $roots = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $hasMore = count($roots) > $n;
        $roots = array_slice($roots, 0, $n);
        $ids = $roots ? array_map('intval', $db->query('WITH RECURSIVE tree(id) AS (SELECT id FROM posts WHERE id IN (' . implode(',', $roots) . ')
            UNION ALL SELECT p.id FROM posts p JOIN tree t ON p.parent_id = t.id) SELECT id FROM tree')->fetchAll(PDO::FETCH_COLUMN)) : [];
        // Its unread private-message notifications: the page marks them read (conversation_read),
        // since a GET doesn't write.
        $st = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL AND kind = 'private'
            AND post_id IN (SELECT id FROM posts WHERE is_private = 1 AND $pair)");
        $st->execute($pargs);
        $unread = (int)$st->fetchColumn();
    } else {
        $seen = '(is_private = 0 OR author_id = :u OR recipient_id = :u)';   // posts you may see
        $args = [':b' => $board['id'], ':priv' => $priv, ':u' => (int)$me['id']];
        if (!empty($_GET['include'])) {
            $st = $db->prepare('WITH RECURSIVE up(id, parent_id) AS (
                    SELECT id, parent_id FROM posts WHERE id = ? AND board_user_id = ?
                    UNION ALL SELECT p.id, p.parent_id FROM posts p JOIN up ON p.id = up.parent_id)
                SELECT id FROM up WHERE parent_id IS NULL');
            $st->execute([(int)$_GET['include'], $board['id']]);
            if ($rootId = (int)$st->fetchColumn()) {
                $st = $db->prepare("SELECT COUNT(*) FROM posts WHERE board_user_id = :b AND parent_id IS NULL AND is_private = :priv AND $seen AND id >= :r");
                $st->execute($args + [':r' => $rootId]);
                $n = max($n, min(1000, (int)$st->fetchColumn()));
            }
        }
        $st = $db->prepare("SELECT id FROM posts WHERE board_user_id = :b AND parent_id IS NULL AND is_private = :priv AND $seen
            ORDER BY id DESC LIMIT " . ($n + 1));
        $st->execute($args);
        $roots = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $hasMore = count($roots) > $n;
        $roots = array_slice($roots, 0, $n);
        $ids = [];
        if ($roots) {
            // The top comments and every reply under them (replies of replies too).
            $ids = array_map('intval', $db->query('WITH RECURSIVE tree(id) AS (SELECT id FROM posts WHERE id IN (' . implode(',', $roots) . ')
                UNION ALL SELECT p.id FROM posts p JOIN tree t ON p.parent_id = t.id) SELECT id FROM tree')->fetchAll(PDO::FETCH_COLUMN));
        }
    }
    $in = $ids ? implode(',', $ids) : '0';   // integers only, so safe to put in the SQL
    $rx = [];
    foreach ($db->query("SELECT post_id, emoji, user_id FROM post_reactions WHERE post_id IN ($in)")->fetchAll() as $r) {
        $rx[$r['post_id']]['counts'][$r['emoji']] = ($rx[$r['post_id']]['counts'][$r['emoji']] ?? 0) + 1;
        if ((int)$r['user_id'] === (int)$me['id']) $rx[$r['post_id']]['mine'] = $r['emoji'];
    }
    $atts = [];
    foreach ($db->query("SELECT pu.post_id, up.id, up.kind, up.mime, up.original, up.size, up.duration, up.poster_id FROM post_uploads pu
        JOIN uploads up ON up.id = pu.upload_id WHERE pu.post_id IN ($in) ORDER BY pu.pos")->fetchAll() as $att) {
        $atts[$att['post_id']][] = att_out($att);
    }
    $rows = $db->query("
        SELECT p.*, a.username AS a_username, a.display_name AS a_display, a.avatar_id AS a_avatar,
               r.display_name AS r_display, up.kind AS up_kind, up.mime AS up_mime, up.original AS up_name, up.size AS up_size,
               s.display_name AS s_display
        FROM posts p JOIN users a ON a.id = p.author_id
        LEFT JOIN users r ON r.id = p.recipient_id LEFT JOIN uploads up ON up.id = p.upload_id
        LEFT JOIN users s ON s.id = p.started_by
        WHERE p.id IN ($in) ORDER BY p.id ASC")->fetchAll();
    // In a conversation, a reply shows a line of what it answers (which may be older than the
    // messages loaded, or a public post).
    $quotes = [];
    if ($priv && ($pids = array_filter(array_map('intval', array_column($rows, 'parent_id'))))) {
        foreach ($db->query('SELECT p.id, p.body, p.is_private, p.author_id, p.recipient_id, p.group_id, a.display_name,
                (SELECT up.kind FROM post_uploads pu JOIN uploads up ON up.id = pu.upload_id WHERE pu.post_id = p.id ORDER BY pu.pos LIMIT 1) AS kind1,
                (SELECT up.kind FROM uploads up WHERE up.id = p.upload_id) AS kind0
            FROM posts p JOIN users a ON a.id = p.author_id WHERE p.id IN (' . implode(',', array_unique($pids)) . ')')->fetchAll() as $q) {
            if (!can_see($q, $me)) continue;
            $label = ['image' => '🖼 Photo', 'video' => '🎬 Video', 'audio' => '🎤 Voice note', 'sticker' => 'Sticker', 'gif' => 'GIF', 'file' => '📄 File'][$q['kind1'] ?: $q['kind0']] ?? '';
            $quotes[(int)$q['id']] = ['author' => $q['display_name'], 'text' => $q['body'] !== '' ? mb_substr($q['body'], 0, 90) : $label];
        }
    }
    $posts = [];
    foreach ($rows as $p) {
        if (!can_see($p, $me)) continue;
        $single = $p['upload_id'] ? [['url' => file_url($p['upload_id']),
            'kind' => $p['up_kind'], 'mime' => $p['up_mime'], 'name' => $p['up_name'], 'size' => (int)$p['up_size']]] : [];
        $list = $atts[$p['id']] ?? $single;   // older comments: their one attachment
        $posts[] = [
            'id' => (int)$p['id'], 'parent_id' => $p['parent_id'] ? (int)$p['parent_id'] : null,
            'private' => (bool)$p['is_private'], 'to' => $p['is_private'] ? $p['r_display'] : null,
            'author' => ['username' => $p['a_username'], 'display_name' => $p['a_display'], 'avatar' => file_url($p['a_avatar'])],
            'mine' => (int)$p['author_id'] === (int)$me['id'],
            'body' => $p['body'], 'at' => (int)$p['created_at'], 'edited' => !empty($p['edited_at']),
            'reactions' => $rx[$p['id']]['counts'] ?? (object)[], 'my_reaction' => $rx[$p['id']]['mine'] ?? null,
            'attachments' => $list, 'attachment' => $list[0] ?? null,
            'copied' => $p['started_by'] ? $p['s_display'] : null,   // private copy of a public comment: who replied privately
            'quote' => $p['parent_id'] ? ($quotes[(int)$p['parent_id']] ?? null) : null,
            'group' => $p['group_id'] ? (int)$p['group_id'] : null,   // a one-off group's message (see 'groups' for who's in it)
        ];
    }
    $groups = group_people($db, array_values(array_unique(array_filter(array_column($posts, 'group')))));
    $groupCalls = [];   // a group's call that's on now: "Join call" on it
    foreach (array_keys($groups) as $gid) if ($rc = room_on($db, $gid)) $groupCalls[$gid] = ['id' => (int)$rc['id'], 'video' => (bool)$rc['video'], 'in' => count(room_live($db, (int)$rc['id']))];
    out(['ok' => true, 'player' => public_user($board) + ['backgrounds' => backgrounds($board)], 'posts' => $posts, 'groups' => (object)$groups, 'group_calls' => (object)$groupCalls,
         'conversation' => (bool)$priv, 'roots' => count($roots), 'has_more' => $hasMore, 'unread' => $unread ?? 0,
         'sig' => $priv ? conv_sig($db, (int)$me['id'], (int)$board['id']) : board_sig($db, (int)$board['id'], (int)$me['id'])]);
}

// Your private conversations, like the feed: one per person (and your notes to yourself),
// each with its latest message from either of you, newest conversation first.
case 'conversations': {
    $u = need_user($me);
    $uid = (int)$u['id'];
    // Conversations between two only: groups are on the Groups list.
    $st = $db->prepare('SELECT CASE WHEN author_id = :u THEN recipient_id ELSE author_id END AS other, MAX(id) AS last
        FROM posts WHERE is_private = 1 AND group_id IS NULL AND (author_id = :u OR recipient_id = :u) GROUP BY other ORDER BY last DESC LIMIT 200');
    $st->execute([':u' => $uid]);
    $convs = $st->fetchAll();
    $lastIds = implode(',', array_map('intval', array_column($convs, 'last'))) ?: '0';
    $people = [];
    foreach ($db->query('SELECT id, username, display_name, bio, avatar_id FROM users WHERE id IN (' . (implode(',', array_map('intval', array_column($convs, 'other'))) ?: '0') . ')')->fetchAll() as $row) $people[(int)$row['id']] = $row;
    $msgs = [];
    foreach ($db->query("SELECT p.*, a.username AS a_username, a.display_name AS a_display, a.avatar_id AS a_avatar,
            u0.kind AS u0_kind, u0.mime AS u0_mime, u0.original AS u0_name, u0.size AS u0_size FROM posts p
        JOIN users a ON a.id = p.author_id LEFT JOIN uploads u0 ON u0.id = p.upload_id WHERE p.id IN ($lastIds)")->fetchAll() as $row) $msgs[(int)$row['id']] = $row;
    $rx = [];
    foreach ($db->query("SELECT post_id, emoji, user_id FROM post_reactions WHERE post_id IN ($lastIds)")->fetchAll() as $r) {
        $rx[$r['post_id']]['counts'][$r['emoji']] = ($rx[$r['post_id']]['counts'][$r['emoji']] ?? 0) + 1;
        if ((int)$r['user_id'] === $uid) $rx[$r['post_id']]['mine'] = $r['emoji'];
    }
    $atts = [];
    foreach ($db->query("SELECT pu.post_id, up.id, up.kind, up.mime, up.original, up.size, up.duration, up.poster_id FROM post_uploads pu JOIN uploads up ON up.id = pu.upload_id
        WHERE pu.post_id IN ($lastIds) ORDER BY pu.pos")->fetchAll() as $att) {
        $atts[$att['post_id']][] = att_out($att);
    }
    // Unread: private-message notifications in that conversation you haven't opened yet.
    $unread = $db->prepare("SELECT p.author_id AS other, COUNT(*) FROM notifications n JOIN posts p ON p.id = n.post_id
        WHERE n.user_id = ? AND n.kind = 'private' AND n.read_at IS NULL AND p.group_id IS NULL GROUP BY other");   // (a group's: on the Groups list)
    $unread->execute([$uid]);
    $unread = $unread->fetchAll(PDO::FETCH_KEY_PAIR);
    $list = [];
    foreach ($convs as $c) {
        $other = (int)$c['other'];
        $m = $msgs[(int)$c['last']] ?? null;
        if (!$m || !isset($people[$other])) continue;
        $list[] = ['with' => public_user($people[$other]), 'notes' => $other === $uid, 'unread' => (int)($unread[$other] ?? 0),
            'latest' => ['id' => (int)$m['id'], 'parent_id' => $m['parent_id'] ? (int)$m['parent_id'] : null, 'private' => true,
                'author' => ['username' => $m['a_username'], 'display_name' => $m['a_display'], 'avatar' => file_url($m['a_avatar'])],
                'mine' => (int)$m['author_id'] === $uid, 'body' => $m['body'], 'at' => (int)$m['created_at'],
                'attachments' => $atts[$m['id']] ?? ($m['upload_id'] ? [['url' => file_url($m['upload_id']), 'kind' => $m['u0_kind'], 'mime' => $m['u0_mime'],
                    'name' => $m['u0_name'], 'size' => (int)$m['u0_size']]] : []),   // older messages: their one attachment
                'reactions' => $rx[$m['id']]['counts'] ?? (object)[], 'my_reaction' => $rx[$m['id']]['mine'] ?? null,
                'group' => $m['group_id'] ? (int)$m['group_id'] : null]];
    }
    out(['ok' => true, 'conversations' => $list]);
}

// Your one-off groups, newest activity first (the Groups filter on your Private tab): who's in
// each, how it started, and where it is (your conversation with whoever started it).
case 'my_groups': {
    $u = need_user($me);
    $uid = (int)$u['id'];
    $st = $db->prepare('SELECT gm.group_id, gm.with_id, w.username AS with_user, w.display_name AS with_display,
            r.body, r.created_at, a.display_name AS starter, r.author_id,
            (SELECT MAX(p.created_at) FROM posts p WHERE p.group_id = gm.group_id) AS last_at,
            (SELECT MAX(p.id) FROM posts p WHERE p.group_id = gm.group_id) AS last_id,
            (SELECT COUNT(*) FROM posts p WHERE p.group_id = gm.group_id) AS messages,
            (SELECT COUNT(*) FROM notifications n JOIN posts p ON p.id = n.post_id WHERE n.user_id = :u AND n.read_at IS NULL AND p.group_id = gm.group_id) AS unread
        FROM group_members gm JOIN posts r ON r.id = gm.group_id JOIN users a ON a.id = r.author_id JOIN users w ON w.id = gm.with_id
        WHERE gm.user_id = :u ORDER BY last_at DESC LIMIT 300');
    $st->execute([':u' => $uid]);
    $rows = $st->fetchAll();
    $people = group_people($db, array_map('intval', array_column($rows, 'group_id')));
    // Each group's latest message (what a reply from the list answers).
    $last = [];
    $ids = implode(',', array_map('intval', array_column($rows, 'last_id'))) ?: '0';
    foreach ($db->query("SELECT p.id, p.body, p.author_id, a.username, a.display_name, a.avatar_id FROM posts p JOIN users a ON a.id = p.author_id WHERE p.id IN ($ids)")->fetchAll() as $m) $last[(int)$m['id']] = $m;
    out(['ok' => true, 'groups' => array_map(fn($g) => [
        'id' => (int)$g['group_id'], 'with' => ['username' => $g['with_user'], 'display_name' => $g['with_display']],
        'members' => $people[(int)$g['group_id']] ?? [], 'started_by' => (int)$g['author_id'] === $uid ? 'You' : $g['starter'],
        'first' => $g['body'] !== '' ? mb_substr((string)$g['body'], 0, 160) : '📎 attachment', 'at' => (int)$g['created_at'],
        'last_at' => (int)$g['last_at'], 'messages' => (int)$g['messages'], 'unread' => (int)$g['unread'],
        'latest' => ($m = $last[(int)$g['last_id']] ?? null) ? ['id' => (int)$m['id'], 'body' => $m['body'] !== '' ? mb_substr((string)$m['body'], 0, 300) : '📎 attachment',
            'mine' => (int)$m['author_id'] === $uid, 'author' => ['username' => $m['username'], 'display_name' => $m['display_name'], 'avatar' => file_url($m['avatar_id'])]] : null,
    ], $rows)]);
}

// One group's messages, oldest first (its thread opened on the Groups list). Members only.
case 'group_thread': {
    $u = need_user($me);
    $gid = (int)($_GET['id'] ?? 0);
    if (!group_member($gid, (int)$u['id'])) fail('That group no longer exists.', 404);
    $rows = $db->prepare('SELECT p.id, p.parent_id, p.body, p.created_at, p.edited_at, p.author_id, p.upload_id, a.username, a.display_name, a.avatar_id
        FROM posts p JOIN users a ON a.id = p.author_id WHERE p.group_id = ? ORDER BY p.id LIMIT 500');
    $rows->execute([$gid]);
    $rows = $rows->fetchAll();
    $in = implode(',', array_map('intval', array_column($rows, 'id'))) ?: '0';
    $atts = [];
    foreach ($db->query("SELECT pu.post_id, up.id, up.kind, up.mime, up.original, up.size, up.duration, up.poster_id FROM post_uploads pu
        JOIN uploads up ON up.id = pu.upload_id WHERE pu.post_id IN ($in) ORDER BY pu.pos")->fetchAll() as $att) $atts[$att['post_id']][] = att_out($att);
    $rx = [];
    foreach ($db->query("SELECT post_id, emoji, user_id FROM post_reactions WHERE post_id IN ($in)")->fetchAll() as $r) {
        $rx[$r['post_id']]['counts'][$r['emoji']] = ($rx[$r['post_id']]['counts'][$r['emoji']] ?? 0) + 1;
        if ((int)$r['user_id'] === (int)$u['id']) $rx[$r['post_id']]['mine'] = $r['emoji'];
    }
    out(['ok' => true, 'posts' => array_map(fn($p) => [
        'id' => (int)$p['id'], 'parent_id' => $p['parent_id'] ? (int)$p['parent_id'] : null, 'private' => true, 'group' => $gid,
        'author' => ['username' => $p['username'], 'display_name' => $p['display_name'], 'avatar' => file_url($p['avatar_id'])],
        'mine' => (int)$p['author_id'] === (int)$u['id'], 'body' => $p['body'], 'at' => (int)$p['created_at'], 'edited' => !empty($p['edited_at']),
        'attachments' => $atts[$p['id']] ?? [],   // (groups are new: their files are always in post_uploads)
        'reactions' => $rx[$p['id']]['counts'] ?? (object)[], 'my_reaction' => $rx[$p['id']]['mine'] ?? null,
    ], $rows)]);
}

case 'post': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $body = trim((string)($in['body'] ?? ''));
    if (mb_strlen($body) > MAX_BODY) fail('Comments: up to ' . MAX_BODY . ' characters.');
    $st = $db->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute([(string)($in['board'] ?? '')]);
    $boardId = (int)$st->fetchColumn();
    if (!$boardId) fail('Channel not found.', 404);

    $parentId = null; $recipient = null;
    $private = !empty($in['private']);
    $selfNote = false;   // a private post to yourself, on your own channel
    if (!empty($in['parent_id'])) {
        $st = $db->prepare('SELECT * FROM posts WHERE id = ?');
        $st->execute([(int)$in['parent_id']]);
        $parent = $st->fetch();
        // A conversation shows messages from both channels: a reply to a private message goes
        // with it, in its channel. A public post must be on this channel.
        if ($parent && $parent['is_private']) $boardId = (int)$parent['board_user_id'];
        if (!$parent || (int)$parent['board_user_id'] !== $boardId || !can_see($parent, $me)) fail('That comment no longer exists.', 404);
        $parentId = (int)$parent['id'];
        // Replies inside a private thread stay private, between the same two people (in a group:
        // its members).
        if ($parent['is_private']) {
            $private = true;
            $groupId = $parent['group_id'] ? (int)$parent['group_id'] : null;
            $recipient = (int)$parent['author_id'] === (int)$u['id'] ? (int)$parent['recipient_id'] : (int)$parent['author_id'];
            $selfNote = !$groupId && $recipient === (int)$u['id'] && (int)$parent['recipient_id'] === (int)$u['id'];
        } elseif ($private) {
            // A private reply to a public comment goes to the Private tab: under a copy of
            // that comment (one per person replying), between you and its author.
            $recipient = (int)$parent['author_id'];
            if ($recipient === (int)$u['id']) fail("That's your own comment. Private replies are for talking with someone else.");
            // @naming others here starts a group on that post: the copy becomes its first message.
            // If you two have talked privately under it already, the group gets a copy of its own
            // (what was said between two stays between two).
            $namedHere = array_values(array_diff(mention_ids($db, $body), [(int)$u['id'], $recipient]));
            $st = $db->prepare('SELECT id FROM posts WHERE source_id = ? AND started_by = ? AND is_private = 1 AND parent_id IS NULL AND group_id IS NULL ORDER BY id LIMIT 1');
            $st->execute([$parent['id'], $u['id']]);
            $copyId = (int)$st->fetchColumn();
            if ($namedHere && $copyId) {
                $st = $db->prepare('SELECT 1 FROM posts WHERE parent_id = ? LIMIT 1');
                $st->execute([$copyId]);
                if ($st->fetchColumn()) $copyId = 0;
            }
            if (!$copyId) {
                $db->prepare('INSERT INTO posts (board_user_id, author_id, is_private, recipient_id, body, upload_id, created_at, edited_at, source_id, started_by)
                              VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)')
                   ->execute([$boardId, $recipient, $u['id'], $parent['body'], $parent['upload_id'], $parent['created_at'],
                              $parent['edited_at'], $parent['id'], $u['id']]);
                $copyId = (int)$db->lastInsertId();
                $db->prepare('INSERT INTO post_uploads (post_id, upload_id, pos) SELECT ?, upload_id, pos FROM post_uploads WHERE post_id = ?')
                   ->execute([$copyId, $parent['id']]);
            }
            $parentId = $copyId;
            if ($namedHere) $groupCopy = $copyId;
        }
    } elseif (!$private && $boardId !== (int)$u['id']) {
        // Public posts on a channel are its owner's; everyone else replies, reacts, or writes privately.
        fail('Only the owner posts publicly here. Reply to a post, or write privately.', 403);
    } elseif ($private) {
        $recipient = $boardId; // a private comment to the person whose channel this is
        $selfNote = $recipient === (int)$u['id'];   // on your own channel: a private post to yourself
    }
    if ($private && $recipient === (int)$u['id'] && !$selfNote && empty($groupId)) fail("You can't send a private reply to yourself.");

    // Attachments: upload_ids (list) and/or upload_id (one); yours, and either from
    // your sticker/GIF collection or not used in another comment yet.
    $ids = array_map('intval', array_filter((array)($in['upload_ids'] ?? [])));
    if (!empty($in['upload_id'])) array_unshift($ids, (int)$in['upload_id']);
    $ids = array_values(array_unique($ids));
    if (count($ids) > MAX_ATTACHMENTS) fail('Up to ' . MAX_ATTACHMENTS . ' attachments per comment.');
    $chk = $db->prepare('SELECT id FROM uploads u WHERE id = ? AND owner_id = ? AND (library = 1 OR (
        NOT EXISTS (SELECT 1 FROM posts WHERE upload_id = u.id) AND NOT EXISTS (SELECT 1 FROM post_uploads WHERE upload_id = u.id)))');
    foreach ($ids as $id) {
        $chk->execute([$id, $u['id']]);
        if (!$chk->fetchColumn()) fail('An attachment is missing. Attach it again.');
    }
    $uploadId = $ids[0] ?? null;   // the first one, for older readers
    if ($body === '' && !$ids) fail('Write something or attach a file.');
    // A one-off group: a new private message to someone that @names others (they all see it, and
    // every reply), or @naming someone in a group's reply (they join it). Elsewhere a private
    // reply's @names add nobody: a conversation between two stays between two.
    $groupId = $groupId ?? null;
    $joining = [];
    if ($private && !$selfNote) {
        $named = array_values(array_diff(mention_ids($db, $body), [(int)$u['id'], (int)$recipient]));
        if ($groupId) $joining = $named;
        elseif ((!$parentId || !empty($groupCopy)) && $named) $joining = $named;   // (starts one: the message, or the copy of the post, becomes its first)
    }
    $db->beginTransaction();
    $db->prepare('INSERT INTO posts (board_user_id, author_id, parent_id, is_private, recipient_id, body, upload_id, created_at, group_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$boardId, $u['id'], $parentId, $private ? 1 : 0, $recipient, $body, $uploadId, time(), $groupId]);
    $newId = (int)$db->lastInsertId();
    $joined = [];
    if (!$groupId && $joining) {   // a new group, started by you, with the person you wrote to
        $groupId = !empty($groupCopy) ? (int)$groupCopy : $newId;
        $db->prepare('UPDATE posts SET group_id = ? WHERE id IN (?, ?)')->execute([$groupId, $groupId, $newId]);
        group_add($db, $groupId, [(int)$u['id']], (int)$recipient);   // (you see it in your conversation with them)
        group_add($db, $groupId, [(int)$recipient], (int)$u['id']);
        $joined = group_add($db, $groupId, $joining, (int)$u['id']);
    } elseif ($groupId && $joining) {
        $st = $db->prepare('SELECT author_id FROM posts WHERE id = ?');
        $st->execute([$groupId]);
        $joined = group_add($db, $groupId, $joining, (int)$st->fetchColumn());   // in their conversation with whoever started it
    }
    $db->commit();
    $pu = $db->prepare('INSERT INTO post_uploads (post_id, upload_id, pos) VALUES (?, ?, ?)');
    foreach ($ids as $i => $id) $pu->execute([$newId, $id, $i]);
    notify_for_post($db, $newId);
    out(['ok' => true, 'id' => $newId, 'private' => $private, 'group' => $groupId, 'joined' => count($joined)]);
}

// Where a TikTok short link (vm.tiktok.com/..., vt.tiktok.com/..., tiktok.com/t/...) leads: its
// full address has the video's number, which the embed needs. Only those hosts, over https, and
// only the redirect is read (no page is fetched), so this can't be used to reach anything else.
case 'resolve_link': {
    need_user($me);
    $url = (string)($_GET['url'] ?? '');
    $u = parse_url($url);
    $short = ($u['scheme'] ?? '') === 'https' && (in_array(strtolower($u['host'] ?? ''), ['vm.tiktok.com', 'vt.tiktok.com'], true)
        || (in_array(strtolower($u['host'] ?? ''), ['www.tiktok.com', 'tiktok.com'], true) && str_starts_with($u['path'] ?? '', '/t/')));
    if (!$short) fail('Not a TikTok short link.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (toffee link preview)']);
    curl_exec($ch);
    $to = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    if (!preg_match('#^https://(www\.|m\.)?tiktok\.com/@[^/]+/video/(\d+)#', $to, $m)) fail("Couldn't open that TikTok link. Try the full link from the video's page.");
    out(['ok' => true, 'url' => $m[0]]);
}

// Who reacted to a post, and with what (for the "Who reacted" pop-up).
case 'reactions': {
    need_user($me);
    $st = $db->prepare('SELECT id, is_private, author_id, recipient_id, group_id FROM posts WHERE id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $p = $st->fetch();
    if (!$p || !can_see($p, $me)) fail('That comment no longer exists.', 404);
    $st = $db->prepare('SELECT pr.emoji, u.username, u.display_name, u.avatar_id FROM post_reactions pr JOIN users u ON u.id = pr.user_id
        WHERE pr.post_id = ? ORDER BY pr.emoji, u.display_name COLLATE NOCASE');
    $st->execute([$p['id']]);
    out(['ok' => true, 'reactions' => array_map(fn($r) => ['emoji' => $r['emoji'], 'username' => $r['username'],
        'display_name' => $r['display_name'], 'avatar' => file_url($r['avatar_id']), 'me' => $r['username'] === $me['username']], $st->fetchAll())]);
}

case 'react': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $st = $db->prepare('SELECT id, is_private, author_id, recipient_id, group_id FROM posts WHERE id = ?');
    $st->execute([(int)($in['id'] ?? 0)]);
    $p = $st->fetch();
    if (!$p || !can_see($p, $me)) fail('That comment no longer exists.', 404);
    $emoji = (string)($in['emoji'] ?? '');
    $db->prepare('DELETE FROM post_reactions WHERE post_id = ? AND user_id = ?')->execute([$p['id'], $u['id']]);
    if ($emoji !== '') {
        // Any single emoji (not text): short, and no letters, digits, punctuation or spaces.
        if (strlen($emoji) > 32 || preg_match('/[\p{L}\p{N}\p{P}\s<>]/u', $emoji) || !preg_match('/[\x{2190}-\x{2BFF}\x{1F000}-\x{1FAFF}]/u', $emoji)) {
            fail('Reactions are emoji only.');
        }
        $db->prepare('INSERT INTO post_reactions (post_id, user_id, emoji) VALUES (?, ?, ?)')->execute([$p['id'], $u['id'], $emoji]);
    }
    out(['ok' => true]);
}

case 'edit_post': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $body = trim((string)($in['body'] ?? ''));
    if ($body === '' || mb_strlen($body) > MAX_BODY) fail('Comments: 1-' . MAX_BODY . ' characters.');
    $st = $db->prepare('UPDATE posts SET body = ?, edited_at = ? WHERE id = ? AND author_id = ? AND created_at >= ? AND started_by IS NULL');
    $st->execute([$body, time(), (int)($in['id'] ?? 0), $u['id'], time() - EDIT_WINDOW]);
    if (!$st->rowCount()) fail('Comments can only be edited by their author, within 5 minutes.', 403);
    // Re-check @names: someone tagged by the edit is told; someone untagged loses the mention.
    // The reply stays under the comment it was posted on, and its author is still told.
    $id = (int)$in['id'];
    $p = $db->query('SELECT is_private FROM posts WHERE id = ' . $id)->fetch();
    if (!$p['is_private']) {
        $now = array_diff(mention_ids($db, $body), [(int)$u['id']]);
        $had = $db->prepare('SELECT user_id, kind FROM notifications WHERE post_id = ?');
        $had->execute([$id]);
        $had = $had->fetchAll(PDO::FETCH_KEY_PAIR);
        $del = $db->prepare("DELETE FROM notifications WHERE post_id = ? AND user_id = ? AND kind = 'mention'");
        foreach ($had as $uid => $kind) if ($kind === 'mention' && !in_array((int)$uid, $now, true)) $del->execute([$id, $uid]);
        $ins = $db->prepare("INSERT INTO notifications (user_id, post_id, kind, created_at) VALUES (?, ?, 'mention', ?)");
        foreach ($now as $uid) if (!isset($had[$uid])) $ins->execute([$uid, $id, time()]);
    }
    out(['ok' => true]);
}

case 'notifications': {
    $u = need_user($me);
    $st = $db->prepare("
        SELECT n.id, n.kind, n.created_at, n.read_at, p.id AS post_id, p.body, p.upload_id, p.is_private, p.group_id,
               a.display_name AS from_name, a.username AS from_user, a.avatar_id AS from_avatar, b.username AS board, b.display_name AS board_display,
               w.username AS with_user, w.display_name AS with_display
        FROM notifications n JOIN posts p ON p.id = n.post_id JOIN users a ON a.id = p.author_id JOIN users b ON b.id = p.board_user_id
        LEFT JOIN group_members gm ON gm.group_id = p.group_id AND gm.user_id = n.user_id LEFT JOIN users w ON w.id = gm.with_id
        WHERE n.user_id = ? ORDER BY n.id DESC LIMIT 40");
    $st->execute([$u['id']]);
    $items = array_map(fn($n) => [
        'id' => (int)$n['id'], 'kind' => $n['kind'], 'at' => (int)$n['created_at'], 'unread' => $n['read_at'] === null,
        'post_id' => (int)$n['post_id'], 'private' => (bool)$n['is_private'], 'board' => $n['board'], 'board_display' => $n['board_display'],
        'from' => ['display_name' => $n['from_name'], 'username' => $n['from_user'], 'avatar' => $n['from_avatar'] ? 'api.php?a=file&v=2&id=' . (int)$n['from_avatar'] : null],
        'snippet' => $n['body'] !== '' ? mb_substr($n['body'], 0, 120) : '📎 attachment',
        // A group's message: in your conversation with whoever started the group.
        'with' => $n['with_user'] ? ['username' => $n['with_user'], 'display_name' => $n['with_display']] : null,
        'group' => (bool)$n['group_id'],
    ], $st->fetchAll());
    $c = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $c->execute([$u['id']]);
    out(['ok' => true, 'unread' => (int)$c->fetchColumn(), 'items' => $items]);
}

case 'notifications_read': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    if (!empty($in['id'])) {
        $db->prepare('UPDATE notifications SET read_at = ? WHERE id = ? AND user_id = ? AND read_at IS NULL')->execute([time(), (int)$in['id'], $u['id']]);
    } else {
        $db->prepare('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL')->execute([time(), $u['id']]);
    }
    out(['ok' => true]);
}

// ---- CALLS: voice and video, phone to phone ------------------------------------------
// The call itself (sound and picture) goes straight between the two browsers (WebRTC): toffee
// only passes on how to reach each other: the caller's offer and the answer, each with all its
// network addresses (no trickling, so nothing arrives before the other side is ready). The other
// side is rung by its phone notification, and by ?a=latest (which the app polls). STUN (to find
// your address on the internet) is Cloudflare's unless TOFFEE_STUN says otherwise; a TURN relay,
// for networks that won't connect directly, is used when TOFFEE_TURN_URLS and
// TOFFEE_TURN_SECRET are set (coturn's use-auth-secret: short-lived passwords made here).
case 'call_config': {   // the servers that help the two phones reach each other
    $u = need_user($me);
    out(['ok' => true, 'ice' => ice_servers((int)$u['id'])]);
}
case 'call_start': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $st = $db->prepare('SELECT id, display_name FROM users WHERE username = ?');
    $st->execute([(string)($in['user'] ?? '')]);
    $to = $st->fetch();
    if (!$to) fail('Channel not found.', 404);
    if ((int)$to['id'] === (int)$u['id']) fail("You can't call yourself.");
    $offer = (string)($in['offer'] ?? '');
    if (!str_starts_with($offer, 'v=0') || strlen($offer) > 30000) fail('That call could not be set up. Try again.');
    if (call_busy($db, (int)$u['id'])) fail("You're in another call.", 409);
    if (call_busy($db, (int)$to['id'])) out(['ok' => false, 'busy' => true, 'error' => "{$to['display_name']} is in another call."], 409);
    $video = !empty($in['video']) ? 1 : 0;
    $db->prepare('INSERT INTO calls (caller_id, callee_id, video, offer, created_at) VALUES (?, ?, ?, ?, ?)')
       ->execute([$u['id'], $to['id'], $video, $offer, time()]);
    $id = (int)$db->lastInsertId();
    // Ring their phones (after this reply: starting a call never waits on a push service).
    $AFTER[] = fn() => ring_phones($db, $u, (int)$to['id'], $id, (bool)$video);
    out(['ok' => true, 'id' => $id]);
}
case 'call_ring': {   // still ringing (the caller's app, every 8 s): their phones ring again, a few times
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $c = my_call($db, (int)($in['id'] ?? 0), (int)$u['id']);
    if ((int)$c['caller_id'] !== (int)$u['id'] || $c['state'] !== 'ringing') out(['ok' => true, 'state' => $c['state']]);
    $AFTER[] = fn() => ring_phones($db, $u, (int)$c['callee_id'], (int)$c['id'], (bool)$c['video']);
    out(['ok' => true, 'state' => 'ringing']);
}
case 'call_poll': {   // where a call is at (and the other side's offer or answer)
    $u = need_user($me);
    $c = my_call($db, (int)($_GET['id'] ?? 0), (int)$u['id']);
    $mine = (int)$c['caller_id'] === (int)$u['id'];
    $st = $db->prepare('SELECT username, display_name, avatar_id FROM users WHERE id = ?');
    $st->execute([$mine ? $c['callee_id'] : $c['caller_id']]);
    $o = $st->fetch();
    out(['ok' => true, 'id' => (int)$c['id'], 'state' => $c['state'], 'video' => (bool)$c['video'], 'caller' => $mine,
        'with' => ['username' => $o['username'], 'display_name' => $o['display_name'], 'avatar' => file_url($o['avatar_id'])],
        'offer' => $mine ? null : $c['offer'], 'answer' => $mine ? $c['answer'] : null,
        'answered_at' => $c['answered_at'] ? (int)$c['answered_at'] : null, 'room' => $c['room_id'] ? (int)$c['room_id'] : null]);
}
case 'call_answer': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $c = my_call($db, (int)($in['id'] ?? 0), (int)$u['id']);
    $answer = (string)($in['answer'] ?? '');
    if ((int)$c['callee_id'] !== (int)$u['id']) fail('Only the person called can answer.', 403);
    if ($c['state'] !== 'ringing') fail($c['state'] === 'missed' ? 'That call has ended.' : 'That call is over.', 409);
    if (!str_starts_with($answer, 'v=0') || strlen($answer) > 30000) fail('That call could not be set up. Try again.');
    $st = $db->prepare("UPDATE calls SET state = 'accepted', answer = ?, answered_at = ?, alive_at = ? WHERE id = ? AND state = 'ringing'");
    $st->execute([$answer, time(), time(), $c['id']]);
    if ($st->rowCount() === 0) fail('You answered on another device.', 409);   // (two of your phones at once: the first one wins)
    out(['ok' => true]);
}
case 'call_alive': {   // still talking (every 15 s): otherwise the call ends by itself after ALIVE_SECONDS
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $c = my_call($db, (int)($in['id'] ?? 0), (int)$u['id']);
    if ($c['state'] === 'accepted') $db->prepare('UPDATE calls SET alive_at = ? WHERE id = ?')->execute([time(), $c['id']]);
    out(['ok' => true, 'state' => $c['state']]);
}
case 'call_end': {   // hang up, cancel, or decline; a note of it goes in the conversation
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $id = (int)($in['id'] ?? 0);
    $st = $db->prepare('SELECT * FROM calls WHERE id = ? AND (caller_id = ? OR callee_id = ?)');
    $st->execute([$id, $u['id'], $u['id']]);
    $c = $st->fetch();
    if (!$c) fail('Call not found.', 404);
    if ($c['ended_at']) out(['ok' => true, 'state' => $c['state']]);   // ended already (both hung up)
    $byCallee = (int)$c['callee_id'] === (int)$u['id'];
    $state = $c['state'] === 'accepted' ? 'ended' : ($byCallee ? 'declined' : 'missed');
    $st = $db->prepare('UPDATE calls SET state = ?, ended_at = ? WHERE id = ? AND ended_at IS NULL');
    $st->execute([$state, time(), $id]);
    if ($st->rowCount() === 0) out(['ok' => true, 'state' => $state]);   // the other side's hang-up got there first: one note only
    $kind = $c['video'] ? 'Video call' : 'Voice call';
    if ($state === 'ended') {
        $secs = max(0, min(time(), (int)$c['alive_at'] + 15) - (int)$c['answered_at']);
        $note = sprintf('📞 %s · %d:%02d', $kind, intdiv($secs, 60), $secs % 60);
    } else {
        $note = $state === 'declined' ? "📞 $kind declined" : '📞 Missed ' . strtolower($kind);
    }
    $db->prepare('INSERT INTO posts (board_user_id, author_id, is_private, recipient_id, body, created_at) VALUES (?, ?, 1, ?, ?, ?)')
       ->execute([$c['callee_id'], $c['caller_id'], $c['callee_id'], $note, time()]);
    if ($state === 'missed') {   // you missed a call: on your 🔒, and the ringing on your phone becomes "Missed call"
        $db->prepare("INSERT INTO notifications (user_id, post_id, kind, created_at) VALUES (?, ?, 'private', ?)")->execute([$c['callee_id'], (int)$db->lastInsertId(), time()]);
        $AFTER[] = function () use ($db, $c, $kind) {
            $st = $db->prepare('SELECT username, display_name FROM users WHERE id = ?');
            $st->execute([$c['caller_id']]);
            $from = $st->fetch();
            $subs = $db->prepare('SELECT endpoint, p256dh, auth FROM push_subs WHERE user_id = ?');
            $subs->execute([$c['callee_id']]);
            push_send($db, array_map(fn($s) => [$s, ['title' => "📞 Missed call from {$from['display_name']}", 'body' => $kind,
                'url' => '/?c=' . rawurlencode((string)$from['username']) . '&tab=private', 'tag' => "toffee-call-{$c['id']}"],
                ['ttl' => 3 * 86400, 'topic' => "call{$c['id']}"]], $subs->fetchAll()));   // (replaces a ring still waiting to be delivered)
        };
    }
    out(['ok' => true, 'state' => $state]);
}

// ---- ROOMS: group calls ---------------------------------------------------------------
// A group's members call together. Each phone connects straight to every other one (as in a
// call between two); whoever joins sends an offer to each person already in, and they answer.
// Fine for voice with about 5 people, video with 3 or 4 (every phone sends its picture to each).
case 'room_start': {   // call your group: or join its call if one is on
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $gid = (int)($in['group'] ?? 0);
    if (!group_member($gid, (int)$u['id'])) fail('That group no longer exists.', 404);
    if ($on = room_on($db, $gid)) out(['ok' => true, 'id' => (int)$on['id'], 'video' => (bool)$on['video'], 'joined' => true]);
    if (call_busy($db, (int)$u['id'])) fail("You're in another call.", 409);
    $video = !empty($in['video']) ? 1 : 0;
    $db->beginTransaction();
    $db->prepare('INSERT INTO rooms (group_id, started_by, video, created_at) VALUES (?, ?, ?, ?)')->execute([$gid, $u['id'], $video, time()]);
    $rid = (int)$db->lastInsertId();
    $st = $db->prepare('SELECT gm.user_id, w.username AS with_user FROM group_members gm JOIN users w ON w.id = gm.with_id WHERE gm.group_id = ?');
    $st->execute([$gid]);
    $members = $st->fetchAll();
    $add = $db->prepare('INSERT INTO room_people (room_id, user_id, state, joined_at, alive_at) VALUES (?, ?, ?, ?, ?)');
    foreach ($members as $m) {
        $mine = (int)$m['user_id'] === (int)$u['id'];
        $add->execute([$rid, $m['user_id'], $mine ? 'in' : 'ringing', $mine ? time() : null, $mine ? time() : null]);
    }
    $db->commit();
    // Ring everyone else's phones; each opens their conversation with the group's starter.
    $AFTER[] = function () use ($db, $u, $members, $rid, $video) {
        $subs = $db->prepare('SELECT endpoint, p256dh, auth FROM push_subs WHERE user_id = ?');
        $sends = [];
        foreach ($members as $m) {
            if ((int)$m['user_id'] === (int)$u['id']) continue;
            $subs->execute([$m['user_id']]);
            foreach ($subs->fetchAll() as $sub) $sends[] = [$sub, ['title' => ($video ? '🎥 ' : '📞 ') . "{$u['display_name']} is calling the group",
                'body' => ($video ? 'Group video call' : 'Group voice call') . ': tap to join', 'call' => true,
                'url' => '/?c=' . rawurlencode((string)$m['with_user']) . '&tab=private', 'tag' => "toffee-room-$rid"],
                ['urgency' => 'high', 'ttl' => RING_SECONDS, 'topic' => "room$rid"]];
        }
        push_send($db, $sends);
    };
    out(['ok' => true, 'id' => $rid, 'video' => (bool)$video, 'joined' => false]);
}
// Add someone to a call between two: it becomes a room (just for this call, no group) with the
// two of you in it; the other side's app moves over by itself (told by the call's data channel,
// or by ?a=call_poll).
case 'room_from_call': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $c = my_call($db, (int)($in['call'] ?? 0), (int)$u['id']);
    if ($c['state'] === 'moved' && $c['room_id']) out(['ok' => true, 'id' => (int)$c['room_id'], 'video' => (bool)$c['video']]);   // (moved already)
    if ($c['state'] !== 'accepted') fail('That call is over.', 409);
    $db->beginTransaction();
    $db->prepare('INSERT INTO rooms (started_by, video, created_at, call_id) VALUES (?, ?, ?, ?)')->execute([$u['id'], $c['video'], time(), $c['id']]);
    $rid = (int)$db->lastInsertId();
    // You're in; the other side is moving over: its app joins and sends you an offer (so the two
    // never send each other one at once).
    $other = (int)$c['caller_id'] === (int)$u['id'] ? (int)$c['callee_id'] : (int)$c['caller_id'];
    $db->prepare("INSERT INTO room_people (room_id, user_id, state, joined_at, alive_at) VALUES (?, ?, 'in', ?, ?)")->execute([$rid, $u['id'], time(), time()]);
    $db->prepare("INSERT INTO room_people (room_id, user_id, state, joined_at) VALUES (?, ?, 'moving', ?)")->execute([$rid, $other, time()]);
    $db->prepare("UPDATE calls SET state = 'moved', room_id = ?, ended_at = ? WHERE id = ?")->execute([$rid, time(), $c['id']]);
    $db->commit();
    out(['ok' => true, 'id' => $rid, 'video' => (bool)$c['video']]);
}
case 'room_add': {   // bring someone into a call (not a group's: its members are rung already)
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $r = my_room($db, (int)($in['id'] ?? 0), (int)$u['id']);
    if ($r['group_id']) fail("A group's call rings everyone in the group already.");
    $live = room_live($db, (int)$r['id']);
    if (!in_array((int)$u['id'], $live, true)) fail('Join the call first.', 409);
    $st = $db->prepare('SELECT id, display_name FROM users WHERE username = ?');
    $st->execute([(string)($in['user'] ?? '')]);
    $who = $st->fetch();
    if (!$who) fail('Channel not found.', 404);
    if (in_array((int)$who['id'], $live, true)) fail("{$who['display_name']} is in the call already.");
    if (call_busy($db, (int)$who['id'])) fail("{$who['display_name']} is in another call.", 409);
    if (count($live) >= ROOM_MAX) fail('That call is full (' . ROOM_MAX . ' people).', 409);
    // (joined_at: when they were rung, for how long it rings; set again when they join)
    $db->prepare("INSERT INTO room_people (room_id, user_id, state, joined_at, added_by) VALUES (?, ?, 'ringing', ?, ?)
        ON CONFLICT(room_id, user_id) DO UPDATE SET state = 'ringing', joined_at = excluded.joined_at, added_by = excluded.added_by")->execute([$r['id'], $who['id'], time(), $u['id']]);
    $AFTER[] = function () use ($db, $u, $who, $r) {
        $subs = $db->prepare('SELECT endpoint, p256dh, auth FROM push_subs WHERE user_id = ?');
        $subs->execute([$who['id']]);
        push_send($db, array_map(fn($sub) => [$sub, ['title' => ($r['video'] ? '🎥 ' : '📞 ') . "{$u['display_name']} is adding you to a call",
            'body' => 'Tap to join', 'call' => true, 'url' => '/?c=' . rawurlencode((string)$u['username']) . '&tab=private', 'tag' => "toffee-room-{$r['id']}"],
            ['urgency' => 'high', 'ttl' => RING_SECONDS, 'topic' => "room{$r['id']}"]], $subs->fetchAll()));
    };
    out(['ok' => true]);
}
case 'room_state': {   // who's in, and the offers and answers meant for you
    $u = need_user($me);
    $r = my_room($db, (int)($_GET['id'] ?? 0), (int)$u['id']);
    $live = room_live($db, (int)$r['id']);
    $over = $r['ended_at'] || (!$live && $r['created_at'] <= time() - 10);
    $st = $db->prepare('SELECT rp.user_id, rp.state, rp.alive_at, rp.joined_at, u.username, u.display_name, u.avatar_id FROM room_people rp JOIN users u ON u.id = rp.user_id WHERE rp.room_id = ?');
    $st->execute([$r['id']]);
    $people = array_map(fn($p) => ['id' => (int)$p['user_id'], 'username' => $p['username'], 'display_name' => $p['display_name'], 'avatar' => file_url($p['avatar_id']),
        'state' => in_array((int)$p['user_id'], $live, true) ? 'in' : ($p['state'] === 'ringing' && !$over && ($p['joined_at'] ?: $r['created_at']) > time() - RING_SECONDS ? 'ringing' : 'out')], $st->fetchAll());
    $st = $db->prepare('SELECT from_id, offer FROM room_links WHERE room_id = ? AND to_id = ? AND answer IS NULL');
    $st->execute([$r['id'], $u['id']]);
    $offers = array_map(fn($l) => ['from' => (int)$l['from_id'], 'offer' => $l['offer']], $st->fetchAll());
    $st = $db->prepare('SELECT to_id, answer FROM room_links WHERE room_id = ? AND from_id = ? AND answer IS NOT NULL');
    $st->execute([$r['id'], $u['id']]);
    $answers = array_map(fn($l) => ['to' => (int)$l['to_id'], 'answer' => $l['answer']], $st->fetchAll());
    out(['ok' => true, 'id' => (int)$r['id'], 'group' => $r['group_id'] ? (int)$r['group_id'] : null, 'video' => (bool)$r['video'], 'over' => (bool)$over, 'me' => (int)$u['id'],
        'offer_to' => in_array((int)$u['id'], $live, true) ? room_offer_to($db, (int)$r['id'], (int)$u['id'], $live) : [],
        'people' => $people, 'offers' => $offers, 'answers' => $answers]);
}
case 'room_join': {   // in: you'll send an offer to each person already talking
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $r = my_room($db, (int)($in['id'] ?? 0), (int)$u['id']);
    $live = array_values(array_diff(room_live($db, (int)$r['id']), [(int)$u['id']]));
    if ($r['ended_at'] || (!$live && $r['created_at'] <= time() - 10)) fail('That call has ended.', 409);
    if (in_room($db, (int)$u['id']) !== (int)$r['id'] && call_busy($db, (int)$u['id'])) fail("You're in another call.", 409);
    if (count($live) >= ROOM_MAX) fail('That call is full (' . ROOM_MAX . ' people).', 409);
    $wasIn = in_array((int)$u['id'], room_live($db, (int)$r['id']), true);
    if ($wasIn) {   // in already (you added someone to your call): still in, since when you were
        $db->prepare('UPDATE room_people SET alive_at = ? WHERE room_id = ? AND user_id = ?')->execute([time(), $r['id'], $u['id']]);
    } else {
        $db->prepare("INSERT INTO room_people (room_id, user_id, state, joined_at, alive_at) VALUES (?, ?, 'in', ?, ?)
            ON CONFLICT(room_id, user_id) DO UPDATE SET state = 'in', joined_at = excluded.joined_at, alive_at = excluded.alive_at")->execute([$r['id'], $u['id'], time(), time()]);
        $db->prepare('DELETE FROM room_links WHERE room_id = ? AND (from_id = ? OR to_id = ?)')->execute([$r['id'], $u['id'], $u['id']]);   // (rejoining: start fresh)
    }
    out(['ok' => true, 'talking' => room_offer_to($db, (int)$r['id'], (int)$u['id'], $live)]);
}
case 'room_offer': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $r = my_room($db, (int)($in['id'] ?? 0), (int)$u['id']);
    $to = (int)($in['to'] ?? 0);
    $offer = (string)($in['offer'] ?? '');
    if (!in_array($to, room_live($db, (int)$r['id']), true)) fail('They have left the call.', 409);
    if (!str_starts_with($offer, 'v=0') || strlen($offer) > 30000) fail('That call could not be set up. Try again.');
    $db->prepare('INSERT OR REPLACE INTO room_links (room_id, from_id, to_id, offer, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$r['id'], $u['id'], $to, $offer, time()]);
    out(['ok' => true]);
}
case 'room_answer': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $r = my_room($db, (int)($in['id'] ?? 0), (int)$u['id']);
    $answer = (string)($in['answer'] ?? '');
    if (!str_starts_with($answer, 'v=0') || strlen($answer) > 30000) fail('That call could not be set up. Try again.');
    $st = $db->prepare('UPDATE room_links SET answer = ? WHERE room_id = ? AND from_id = ? AND to_id = ? AND answer IS NULL');
    $st->execute([$answer, $r['id'], (int)($in['from'] ?? 0), $u['id']]);
    out(['ok' => $st->rowCount() > 0]);
}
case 'room_alive': {   // still in (every 15 s): otherwise you count as gone after ALIVE_SECONDS
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $r = my_room($db, (int)($in['id'] ?? 0), (int)$u['id']);
    $db->prepare("UPDATE room_people SET alive_at = ? WHERE room_id = ? AND user_id = ? AND state = 'in'")->execute([time(), $r['id'], $u['id']]);
    out(['ok' => true]);
}
case 'room_leave': {   // hang up, or decline: the last one out ends the call, with a note in the group
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $r = my_room($db, (int)($in['id'] ?? 0), (int)$u['id']);
    $db->prepare("UPDATE room_people SET state = ? WHERE room_id = ? AND user_id = ?")->execute([empty($in['decline']) ? 'left' : 'declined', $r['id'], $u['id']]);
    $db->prepare('DELETE FROM room_links WHERE room_id = ? AND (from_id = ? OR to_id = ?)')->execute([$r['id'], $u['id'], $u['id']]);
    if (!$r['ended_at'] && !room_live($db, (int)$r['id'])) {
        $st = $db->prepare('UPDATE rooms SET ended_at = ? WHERE id = ? AND ended_at IS NULL');
        $st->execute([time(), $r['id']]);
        if ($st->rowCount()) {
            $st = $db->prepare("SELECT COUNT(*), MIN(joined_at), MAX(alive_at) FROM room_people WHERE room_id = ? AND joined_at IS NOT NULL");
            $st->execute([$r['id']]);
            [$n, $from, $to] = $st->fetch(PDO::FETCH_NUM);
            $secs = max(0, min(time(), (int)$to + 15) - (int)$from);
            if ($r['group_id']) {
                $kind = $r['video'] ? 'Group video call' : 'Group voice call';
                $note = (int)$n > 1 ? sprintf('📞 %s · %d:%02d · %d people', $kind, intdiv($secs, 60), $secs % 60, $n) : "📞 $kind · nobody joined";
                $st = $db->prepare('SELECT board_user_id, author_id, recipient_id FROM posts WHERE id = ?');
                $st->execute([$r['group_id']]);
                $root = $st->fetch();
                if ($root) $db->prepare('INSERT INTO posts (board_user_id, author_id, parent_id, is_private, recipient_id, body, created_at, group_id) VALUES (?, ?, ?, 1, ?, ?, ?, ?)')
                    ->execute([$root['board_user_id'], $r['started_by'], $r['group_id'], $root['recipient_id'], $note, time(), $r['group_id']]);
            } else {
                // A call between two that grew: a note in the first two's conversation, and in each added
                // person's conversation with whoever brought them in. "📞 Voice call with Bob and Cat · 3:10"
                $st = $db->prepare('SELECT u.id, u.display_name, rp.added_by, rp.alive_at FROM room_people rp JOIN users u ON u.id = rp.user_id WHERE rp.room_id = ? ORDER BY rp.rowid');
                $st->execute([$r['id']]);
                $all = $st->fetchAll();
                $names = array_column(array_filter($all, fn($x) => $x['alive_at']), 'display_name', 'id');   // (who was in it)
                $kind = $r['video'] ? 'Video call' : 'Voice call';
                $note = function (int $a, int $b) use ($names, $kind, $secs): string {
                    $others = array_values(array_diff_key($names, [$a => 1, $b => 1]));
                    $with = $others ? ' with ' . (count($others) > 1 ? implode(', ', array_slice($others, 0, -1)) . ' and ' . end($others) : $others[0]) : '';
                    return sprintf('📞 %s%s · %d:%02d', $kind, $with, intdiv($secs, 60), $secs % 60);
                };
                $c = $db->prepare('SELECT caller_id, callee_id FROM calls WHERE id = ?');
                $c->execute([(int)$r['call_id']]);
                $pair = $c->fetch();
                $put = $db->prepare('INSERT INTO posts (board_user_id, author_id, is_private, recipient_id, body, created_at) VALUES (?, ?, 1, ?, ?, ?)');
                if ($pair) $put->execute([$pair['callee_id'], $pair['caller_id'], $pair['callee_id'], $note((int)$pair['caller_id'], (int)$pair['callee_id']), time()]);
                foreach ($all as $x) if ($x['added_by'] && $x['alive_at']) $put->execute([$x['id'], $x['added_by'], $x['id'], $note((int)$x['added_by'], (int)$x['id']), time()]);
            }
        }
    }
    out(['ok' => true]);
}

// You opened a conversation (?a=thread&tab=private): its private-message notifications are read.
case 'conversation_read': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $st = $db->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute([(string)($in['user'] ?? '')]);
    if (!($other = (int)$st->fetchColumn())) fail('Channel not found.', 404);
    [$pair, $pargs] = pair_sql((int)$u['id'], $other);
    $db->prepare("UPDATE notifications SET read_at = :now WHERE user_id = :u AND read_at IS NULL AND kind = 'private'
        AND post_id IN (SELECT id FROM posts WHERE is_private = 1 AND $pair)")->execute($pargs + [':now' => time()]);
    out(['ok' => true]);
}

// Clear your notifications (the history on the 🔔 page). Posts and messages aren't touched.
case 'notifications_clear': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $db->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$u['id']]);
    out(['ok' => true]);
}

case 'delete_post': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $id = (int)($in['id'] ?? 0);
    // What the comment and its replies had attached: freed below if nothing else uses it
    // (a private copy of the comment keeps its pictures).
    $st = $db->prepare('WITH RECURSIVE tree(id) AS (SELECT ? UNION ALL SELECT p.id FROM posts p JOIN tree t ON p.parent_id = t.id)
        SELECT upload_id FROM posts WHERE id IN (SELECT id FROM tree) AND upload_id IS NOT NULL
        UNION SELECT upload_id FROM post_uploads WHERE post_id IN (SELECT id FROM tree)');
    $st->execute([$id]);
    $freed = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $st = $db->prepare('DELETE FROM posts WHERE id = ? AND author_id = ? AND created_at >= ? AND started_by IS NULL');
    $st->execute([$id, $u['id'], time() - EDIT_WINDOW]);
    if (!$st->rowCount()) fail('Comments can only be deleted by their author, within 5 minutes.', 403);
    if ($freed) gc_uploads($db, $UPLOADS, 'id IN (' . implode(',', $freed) . ')', []);
    out(['ok' => true]);
}

// Polling, every few seconds: your unread count and, with ?board=NAME, that channel's
// fingerprint (see board_sig), so the page reloads a channel only when something changed.
// With ?feed=1, the home feed's (see feed_sig).
case 'latest': {
    $u = need_user($me);
    $c = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $c->execute([$u['id']]);
    $reply = ['ok' => true, 'unread' => (int)$c->fetchColumn()];
    if (isset($_GET['board']) || isset($_GET['conv'])) {
        $st = $db->prepare('SELECT id FROM users WHERE username = ?');
        $st->execute([(string)($_GET['board'] ?? $_GET['conv'])]);
        if ($id = (int)$st->fetchColumn()) $reply['sig'] = isset($_GET['conv']) ? conv_sig($db, (int)$u['id'], $id) : board_sig($db, $id, (int)$u['id']);
    }
    if (isset($_GET['feed'])) $reply['feed_sig'] = feed_sig($db);   // the feed column reloads ?a=channels only when it changes
    if ($ring = ringing_for($db, (int)$u['id'])) $reply['call'] = $ring;   // someone is calling you
    elseif ($ring = room_ring_for($db, (int)$u['id'])) $reply['room'] = $ring;   // a group you're in is calling
    out($reply);
}

// ---- uploads -------------------------------------------------------------------
case 'library': {
    $u = need_user($me);
    $st = $db->prepare("SELECT id, kind FROM uploads WHERE owner_id = ? AND library = 1 ORDER BY id DESC LIMIT 100");
    $st->execute([$u['id']]);
    out(['ok' => true, 'items' => array_map(fn($x) => ['id' => (int)$x['id'], 'kind' => $x['kind'],
        'url' => 'api.php?a=file&v=2&id=' . (int)$x['id']], $st->fetchAll())]);
}

case 'upload': {
    if (!$isPost) fail('POST only', 405);
    $u = need_user($me);
    $f = $_FILES['file'] ?? null;
    if ($f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) fail('That file is too big (videos up to 50 MB, documents up to 20 MB).', 413);
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Upload failed. Try again.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    $purpose = (string)($_POST['purpose'] ?? '');
    // A voice note that the file check can't name, but which is an MP4 file (it starts with an
    // "ftyp" box: iPhones' recordings sometimes do): kept as audio/mp4, which every phone plays.
    if ($purpose === 'voice' && in_array($mime, ['application/octet-stream', ''], true)) {
        $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 12);
        if (substr($head, 4, 4) === 'ftyp') $mime = 'audio/mp4';
    }
    $kind = null;
    if (in_array($purpose, ['sticker', 'gif'], true)) {
        if (in_array($mime, UPLOAD_KINDS[$purpose]['mimes'], true)) $kind = $purpose;
        else fail($purpose === 'sticker' ? 'Stickers are made from pictures.' : 'GIFs: a .gif file or a short video.');
    } else {
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (in_array($mime, BLOCKED_MIMES, true) || in_array($ext, BLOCKED_EXTS, true)) fail("Programs and scripts can't be shared on toffee.");
        $kinds = $purpose === 'voice' ? ['audio'] : ['image', 'video', 'audio'];
        foreach ($kinds as $k) {
            if (in_array($mime, UPLOAD_KINDS[$k]['mimes'], true)) { $kind = $k; break; }
        }
        if (!$kind && $purpose !== 'voice') $kind = 'file';   // a document, or any other file: as a download
    }
    if (!$kind) fail("That recording couldn't be read. Try again.");
    if ($f['size'] > UPLOAD_KINDS[$kind]['max']) fail('That file is too big (max ' . (UPLOAD_KINDS[$kind]['max'] >> 20) . ' MB).');
    // Your uploads from over a day ago that never made it into a comment (or were replaced or
    // deleted since) are cleared out, then what's left counts against your space.
    gc_uploads($db, $UPLOADS, 'owner_id = ? AND created_at < ?', [$u['id'], time() - 86400]);
    $st = $db->prepare('SELECT COALESCE(SUM(size), 0) FROM uploads WHERE owner_id = ?');
    $st->execute([$u['id']]);
    if ((int)$st->fetchColumn() + (int)$f['size'] > UPLOAD_QUOTA) fail('Your space for pictures and files is full (' . (UPLOAD_QUOTA >> 20) . ' MB).', 413);
    $original = mb_substr((string)preg_replace('/[^\w .()-]+/u', '_', mb_scrub(basename((string)$f['name']), 'UTF-8')), 0, 80);   // (broken characters in a name: replaced, not a crash)
    if ($S3) {   // streamed from PHP's temporary file to S3; only the key is kept here
        $name = "u/{$u['id']}/" . bin2hex(random_bytes(16));
        if (!s3_put_file($name, $f['tmp_name'], $mime, $kind, $original)) fail('Could not save the file. Try again.', 502);
    } else {
        $name = bin2hex(random_bytes(16));
        if (!move_uploaded_file($f['tmp_name'], "$UPLOADS/$name")) fail('Could not save the file.', 500);
    }
    // A voice note's or video's length, and a video's cover (a picture you uploaded just before), from the phone.
    $duration = null; $poster = null;
    if (in_array($kind, ['audio', 'video'], true)) {
        $d = (float)($_POST['duration'] ?? 0);
        if ($d > 0 && $d < 36000) $duration = $d;
    }
    // (a picture's small copy goes the same way: a profile picture's, for lists: see ?a=file&s=1)
    if (in_array($kind, ['video', 'image'], true) && ($pid = (int)($_POST['poster_id'] ?? 0))) {
        $st = $db->prepare("SELECT id FROM uploads WHERE id = ? AND owner_id = ? AND kind = 'image'");
        $st->execute([$pid, $u['id']]);
        $poster = $st->fetchColumn() ?: null;
    }
    $db->prepare('INSERT INTO uploads (owner_id, name, kind, mime, size, original, created_at, library, duration, poster_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$u['id'], $name, $kind, $mime, (int)$f['size'], $original, time(), in_array($kind, ['sticker', 'gif'], true) ? 1 : 0, $duration, $poster]);
    out(['ok' => true, 'id' => (int)$db->lastInsertId(), 'kind' => $kind]);
}

case 'file': {
    if (!$me) { http_response_code(403); exit; }   // sign in first
    $st = $db->prepare('SELECT id, owner_id, name, kind, mime, size, original, poster_id FROM uploads WHERE id = ?');
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $up = $st->fetch();
    if (!$up) { http_response_code(404); exit; }
    // Avatars and backgrounds are public. Attachments follow their post's visibility (a video's
    // cover picture: its video's). An upload not yet attached to anything is visible only to its owner.
    $st = $db->prepare('SELECT id FROM uploads WHERE poster_id = ? LIMIT 1');
    $st->execute([$up['id']]);
    $useOf = (int)($st->fetchColumn() ?: $up['id']);
    $st = $db->prepare('SELECT is_private, author_id, recipient_id, group_id FROM posts WHERE upload_id = ?
        UNION ALL SELECT p.is_private, p.author_id, p.recipient_id, p.group_id FROM post_uploads pu JOIN posts p ON p.id = pu.post_id WHERE pu.upload_id = ?');
    $st->execute([$useOf, $useOf]);
    $usedIn = $st->fetchAll();
    $post = $usedIn ? $usedIn[0] : null;
    foreach ($usedIn as $candidate) { if (can_see($candidate, $me)) { $post = $candidate; break; } }
    $st = $db->prepare('SELECT EXISTS (SELECT 1 FROM users WHERE avatar_id = :i) OR EXISTS (SELECT 1 FROM users WHERE bg_public_id = :i)
        OR EXISTS (SELECT 1 FROM users WHERE bg_private_id = :i) OR EXISTS (SELECT 1 FROM users WHERE bg_settings_id = :i) OR EXISTS (SELECT 1 FROM users WHERE bg_banner_id = :i)');   // (each with its index)
    $st->execute([':i' => $up['id']]);
    $isAvatar = (bool)$st->fetchColumn();
    $allowed = $isAvatar || ($post ? can_see($post, $me) : ($me && (int)$me['id'] === (int)$up['owner_id']));
    if (!$allowed) { http_response_code(403); exit; }
    // &s=1: its small copy, if it has one (a profile picture shown in a list: 160 px, not 640).
    // Checked above as the picture itself; then served as that copy (its own address, size and ETag).
    if (!empty($_GET['s']) && $up['kind'] === 'image' && $up['poster_id']) {
        $st = $db->prepare("SELECT id, owner_id, name, kind, mime, size, original, poster_id FROM uploads WHERE id = ? AND kind = 'image'");
        $st->execute([(int)$up['poster_id']]);
        if ($small = $st->fetch()) $up = $small;
    }
    $inS3 = str_contains($up['name'], '/');
    // In S3, and seen by everyone signed in (a profile picture, background, or a public post's
    // file): straight from storage, which Torii serves from any Pi. Private files stay here.
    if ($inS3 && getenv('TORII_FILES_URL') && ($isAvatar || array_filter($usedIn, fn($p) => !$p['is_private']))) {
        header('Location: ' . rtrim((string)getenv('TORII_FILES_URL'), '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $up['name']))), true, 302);
        header('Cache-Control: private, max-age=3600');
        exit;
    }
    // An upload never changes (a new file is a new upload), so its id and size are its ETag.
    $etag = '"u' . (int)$up['id'] . '-' . (int)$up['size'] . '"';
    // "Keep for a year" only goes with a file that is really there: a cached "not found"
    // would stay broken for a year, even after the file is back (Torii move, 2026-10-24).
    $cache = function () use ($etag) {
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=31536000, immutable');
    };
    if (in_array($etag, array_map(fn($t) => preg_replace('#^W/#', '', trim($t)), explode(',', $_SERVER['HTTP_IF_NONE_MATCH'] ?? '')), true)) {
        $cache();
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . $up['mime']);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    $disp = $up['kind'] === 'file' ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $up['original'] ?: 'file') . '"');
    // Phones fetch audio and video in parts (Range requests): iPhones won't play them otherwise,
    // and it lets you jump around in a long video without downloading it all.
    header('Accept-Ranges: bytes');
    // Done with the database: a long download (a video) doesn't keep it open. On a read copy
    // Torii swaps /data/toffee.db for a fresh copy every ~2 s; each request opens its own.
    $db = null;
    // Videos and voice notes go out in pieces of at most 1 MB (the player asks for the next one as
    // it plays): a phone on a slow connection never holds one of the few PHP workers for minutes,
    // which held up everyone's pages (measured 2026-10-25: over 15 s with one chat of videos open).
    $piece = in_array($up['kind'], ['audio', 'video', 'gif'], true) ? MEDIA_PIECE : 0;
    $askRange = $_SERVER['HTTP_RANGE'] ?? '';
    if ($piece && preg_match('/^bytes=(\d+)-(\d*)$/', $askRange, $m) && ($m[2] === '' || (int)$m[2] - (int)$m[1] >= $piece)) {
        $askRange = 'bytes=' . $m[1] . '-' . ((int)$m[1] + $piece - 1);
    }
    if ($inS3) {   // private: passed on from S3 as it comes (parts too)
        $range = preg_match('/^bytes=\d*-\d*$/', $askRange) ? ['Range: ' . $askRange] : [];
        [$status, $h] = s3_call($_SERVER['REQUEST_METHOD'] === 'HEAD' ? 'HEAD' : 'GET', $up['name'], $range, null,
            function (int $status, array $h) use ($cache) {
                http_response_code($status);
                if ($status >= 200 && $status < 300) $cache();
                foreach (['Content-Length' => 'content-length', 'Content-Range' => 'content-range'] as $n => $k) if (isset($h[$k])) header("$n: {$h[$k]}");
            },
            function (string $chunk) { echo $chunk; });
        if ($status === 416) { http_response_code(416); if (isset($h['content-range'])) header("Content-Range: {$h['content-range']}"); }
        elseif ($status < 200 || $status >= 300) { header_remove('Content-Type'); header('Cache-Control: no-store'); http_response_code($status === 404 ? 404 : 502); }   // (never kept: it may be back soon)
        exit;
    }
    $path = "$UPLOADS/{$up['name']}";
    if (!is_file($path)) { header_remove('Content-Type'); header('Cache-Control: no-store'); http_response_code(404); exit; }   // lost from the disk
    $cache();
    $size = filesize($path);
    $from = 0; $to = $size - 1;
    if (preg_match('/^bytes=(\d*)-(\d*)$/', $askRange, $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $from = max(0, $size - (int)$m[2]); }         // the last N bytes
        else { $from = (int)$m[1]; if ($m[2] !== '') $to = min($to, (int)$m[2]); }
        if ($from > $to || $from >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $from-$to/$size");
    }
    header('Content-Length: ' . ($to - $from + 1));
    $fh = fopen($path, 'rb');
    fseek($fh, $from);
    for ($left = $to - $from + 1; $left > 0 && !feof($fh); $left -= 65536) echo fread($fh, min(65536, $left));
    fclose($fh);
    exit;
}

default:
    fail('Unknown action.', 404);
}
