<?php
// Several copies of toffee, as Torii will run it (DATA.md), on this computer: a writer and a
// reader, with files in a local Garage (brew install garage). Run from the repo:
//     php tests/replica_test.php
// 1. Today's mode: files go to /data/uploads. 2. tools/move-uploads-to-s3.php moves them to
// Garage. 3. The writer with S3: new files go to Garage only. 4. A reader on a read-only copy of
// the database: every page works, files come from Garage (public ones straight, private ones
// through toffee, which checks who's asking), and nothing is written. 5. Deleting a post
// deletes its file from Garage. Everything it made is removed at the end.

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
$root = dirname(__DIR__);
$garage = trim((string)shell_exec('command -v garage'));
if ($garage === '') { echo "Garage isn't installed (brew install garage): skipped.\n"; exit(0); }

$tmp = sys_get_temp_dir() . '/toffee-replica-' . bin2hex(random_bytes(4));
$wdata = "$tmp/writer";
$rdata = "$tmp/reader";
foreach (["$tmp/garage", $wdata, $rdata] as $d) mkdir($d, 0700, true);
$procs = [];
register_shutdown_function(function () use (&$procs, $tmp) {
    foreach ($procs as $p) proc_terminate($p);
    exec('chmod -R u+w ' . escapeshellarg($tmp) . ' && rm -rf ' . escapeshellarg($tmp));
});

// ---- a one-node Garage, a bucket for toffee, a key for that bucket ---------------------
[$s3port, $rpc, $web, $adm] = [free_port(), free_port(), free_port(), free_port()];
$conf = "$tmp/garage/garage.toml";
file_put_contents($conf, "metadata_dir = \"$tmp/garage/meta\"\ndata_dir = \"$tmp/garage/data\"\ndb_engine = \"sqlite\"\nreplication_factor = 1\n"
    . "rpc_bind_addr = \"127.0.0.1:$rpc\"\nrpc_public_addr = \"127.0.0.1:$rpc\"\nrpc_secret = \"" . bin2hex(random_bytes(32)) . "\"\n"
    . "[s3_api]\ns3_region = \"garage\"\napi_bind_addr = \"127.0.0.1:$s3port\"\nroot_domain = \".s3.garage.localhost\"\n"
    . "[s3_web]\nbind_addr = \"127.0.0.1:$web\"\nroot_domain = \".web.garage.localhost\"\n[admin]\napi_bind_addr = \"127.0.0.1:$adm\"\n");
$procs[] = proc_open([$garage, '-c', $conf, 'server'], [1 => ['file', "$tmp/garage/log", 'w'], 2 => ['file', "$tmp/garage/log", 'a']], $pipes);
$g = fn(string $args) => (string)shell_exec(escapeshellarg($garage) . ' -c ' . escapeshellarg($conf) . " $args 2>&1");
for ($i = 0; $i < 50 && !str_contains($g('status'), 'HEALTHY'); $i++) usleep(200000);
$node = explode('@', trim($g('node id -q')))[0];
$g("layout assign -z dc1 -c 1G $node");
$g('layout apply --version 1');
$g('bucket create toffee-test');
$key = $g('key create toffee-test-key');
$g('bucket allow --read --write --owner toffee-test --key toffee-test-key');
$g('bucket website --allow toffee-test');
preg_match('/Key ID:\s*(\S+)/', $key, $id);
preg_match('/Secret key:\s*(\S+)/', $key, $secret);
$S3 = ['TORII_S3_ENDPOINT' => "http://127.0.0.1:$s3port", 'TORII_S3_REGION' => 'garage', 'TORII_S3_BUCKET' => 'toffee-test',
    'TORII_S3_ACCESS_KEY_ID' => $id[1] ?? '', 'TORII_S3_SECRET_ACCESS_KEY' => $secret[1] ?? '',
    'TORII_FILES_URL' => "http://toffee-test.web.garage.localhost:$web"];
foreach ($S3 as $k => $v) putenv("$k=$v");
require "$root/s3.php";
$fetch = function (string $url, array $headers = []): array {   // [status, headers, body], without following redirects
    $ch = curl_init($url);
    $h = '';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$h) { $h .= $line; return strlen($line); }]);
    $body = (string)curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $h, $body];
};
$keyOf = function (int $upload) use ($wdata): string {
    $db = new PDO("sqlite:$wdata/toffee.db");
    $st = $db->prepare('SELECT name FROM uploads WHERE id = ?');
    $st->execute([$upload]);
    return (string)$st->fetchColumn();
};
$img = "$root/toffee.png";
$pic = file_get_contents($img);

section('Today: one copy, files in /data/uploads');
check(!empty($id[1]) && !empty($secret[1]), 'Garage is up, with a bucket and a key for it', $key);
[$p, $base] = start_toffee($root, $wdata, array_fill_keys(array_keys($S3), ''), "$tmp/today.log");   // no S3 settings
$procs[] = $p;
[$alice, $bob, $carol] = people($base, 'alice', 'bob', 'carol');
$public = $alice->post('upload', [], $img)['id'];
$alice->post('post', ['board' => 'alice', 'body' => 'a public picture', 'upload_ids' => [$public]]);
$private = $bob->post('upload', [], $img)['id'];
check($bob->post('post', ['board' => 'alice', 'body' => 'a private picture', 'private' => true, 'upload_ids' => [$private]])['ok'], 'a public and a private picture');
check(count(glob("$wdata/uploads/*")) === 2, 'both in /data/uploads');
proc_terminate($p);
usleep(300000);

section('Moving the files to Garage (tools/move-uploads-to-s3.php)');
putenv("TOFFEE_DATA=$wdata");
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tools/move-uploads-to-s3.php") . ' 2>&1', $said, $code);
check($code === 0 && str_contains(implode("\n", $said), 'moved 2, missing 0, failed 0'), 'both moved', $said);
check(str_starts_with($keyOf($public), 'u/') && s3_call('HEAD', $keyOf($public))[0] === 200, 'the database points at Garage, and the file is there');
$said = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tools/move-uploads-to-s3.php") . ' 2>&1', $said);
check(str_contains(implode("\n", $said), 'moved 0, missing 0, failed 0 (of 0)'), 'running it again moves nothing', $said);

section('The writer, with S3');
[$p, $base] = start_toffee($root, $wdata, ['TORII_ROLE' => 'writer'] + $S3, "$tmp/writer.log");
$procs[] = $p;
$cookies = fn(Client $c, string $to) => tap(new Client($to), fn($n) => $n->cookies($c->cookies()));
function tap($x, callable $f) { $f($x); return $x; }
[$alice, $bob, $carol] = [$cookies($alice, $base), $cookies($bob, $base), $cookies($carol, $base)];
check(($alice->get('me')['user']['username'] ?? '') === 'alice', 'still signed in after the restart');
$doc = tempnam($tmp, 'doc');
file_put_contents($doc, '<html><script>alert(1)</script></html>');
$docId = $alice->post('upload', [], $doc, [])['id'] ?? 0;
$gone = $alice->post('upload', [], $img)['id'] ?? 0;
$alice->post('post', ['board' => 'alice', 'body' => 'a document', 'upload_ids' => [$docId]]);
$goneRes = $alice->post('post', ['board' => 'alice', 'body' => 'deleted soon', 'upload_ids' => [$gone]]);
check($docId && $gone && count(glob("$wdata/uploads/*")) === 2, 'new files go to Garage only (nothing new in /data/uploads)');
check(s3_call('HEAD', $keyOf($docId))[0] === 200, 'they are in Garage');

section('A reader, on a read-only copy of the database');
$w = new PDO("sqlite:$wdata/toffee.db");
$w->exec("VACUUM INTO '$rdata/toffee.db'");
(new PDO("sqlite:$rdata/toffee.db"))->exec('PRAGMA journal_mode=WAL');
chmod("$rdata/toffee.db", 0444);
clearstatcache();
$before = [md5_file("$rdata/toffee.db"), filemtime("$rdata/toffee.db")];
[$p, $rbase] = start_toffee($root, $rdata, ['TORII_ROLE' => 'reader'] + $S3, "$tmp/reader.log");
$procs[] = $p;
[$ra, $rb, $rc] = [$cookies($alice, $rbase), $cookies($bob, $rbase), $cookies($carol, $rbase)];
$pages = ['me' => [], 'latest' => ['feed' => 1, 'board' => 'alice'], 'channels' => [], 'conversations' => [], 'notifications' => [], 'library' => [],
    'push_key' => [], 'thread' => ['user' => 'alice', 'tab' => 'public'], 'thread ' => ['user' => 'bob', 'tab' => 'private']];
$bad = [];
foreach ($pages as $act => $q) {
    $r = $ra->get(trim($act), $q);
    if (!$r['ok']) $bad[trim($act)] = $r;
}
$r = $rb->get('thread', ['user' => 'alice', 'tab' => 'private']);
if (!$r['ok']) $bad['bob private'] = $r;
check(!$bad && ($ra->get('me')['user']['username'] ?? '') === 'alice', 'every page works on the reader, signed in with the same cookie', $bad);
$home = $fetch(str_replace('/api.php', '/', $rbase));
check($home[0] === 200 && str_contains($home[2], 'toffee'), 'the page itself too');
$thread = $ra->get('thread', ['user' => 'alice', 'tab' => 'public']);
check(count(array_filter($thread['posts'], fn($p) => $p['body'] === 'a public picture')) === 1, 'with the posts from before');

[$code, $h] = $fetch("$rbase?a=file&id=$public", ['Cookie: ' . http_build_query($ra->cookies(), '', '; ')]);
check($code === 302 && preg_match('/^Location: (\S+)/mi', $h, $loc), 'a public picture: sent straight to Garage', $h);
[$code, $h, $body] = $fetch(trim($loc[1] ?? ''));
check($code === 200 && $body === $pic && stripos($h, 'immutable') !== false, '... which has it (cached for good)', substr($h, 0, 300));
$asBob = ['Cookie: ' . http_build_query($rb->cookies(), '', '; ')];
[$code, $h, $body] = $fetch("$rbase?a=file&id=$private", ['Cookie: ' . http_build_query($ra->cookies(), '', '; ')]);
check($code === 200 && $body === $pic && stripos($h, 'sandbox') !== false, 'a private picture: through toffee, from Garage', $code);
[$code, $h, $body] = $fetch("$rbase?a=file&id=$private", array_merge($asBob, ['Range: bytes=0-9']));
check($code === 206 && $body === substr($pic, 0, 10) && preg_match('#Content-Range: bytes 0-9/' . strlen($pic) . '#i', $h), '... in parts too (Range)', [$code, $h]);
[$code] = $fetch("$rbase?a=file&id=$private", ['Cookie: ' . http_build_query($rc->cookies(), '', '; ')]);
check($code === 403, "... and not for someone else", $code);
check(!str_contains(json_encode($ra->get('thread', ['user' => 'bob', 'tab' => 'private'])), $keyOf($private)), "its address in Garage is never shown");
[$code, $h] = $fetch("$rbase?a=file&id=$docId", ['Cookie: ' . http_build_query($ra->cookies(), '', '; ')]);
preg_match('/^Location: (\S+)/mi', $h, $loc);
[$code, $h] = $fetch(trim($loc[1] ?? ''));
check($code === 200 && stripos($h, 'content-type: application/octet-stream') !== false && stripos($h, 'content-disposition: attachment') !== false,
    'a document from Garage is a download, never a page', substr($h, 0, 400));
check(!$ra->post('post', ['board' => 'alice', 'body' => 'from the reader'])['ok'] && $ra->status === 503, 'a POST that reaches the reader is refused (503)');
clearstatcache();
check([md5_file("$rdata/toffee.db"), filemtime("$rdata/toffee.db")] === $before, "the reader's database wasn't touched");
$made = array_diff(scandir($rdata), ['.', '..', 'toffee.db', 'toffee.db-shm', 'toffee.db-wal']);
check(!$made, 'and the reader made no files (sessions, uploads, keys...)', array_values($made));

section('As Torii routes it: cookie-less GETs to any copy, everything else to the writer');
// A first visit (no cookies yet) lands on a read copy; signing in then goes to the writer.
$guest = new Client($rbase);
$g = $guest->get('me');
check($g['ok'] && !empty($g['csrf']) && !empty($guest->cookies()['tf_csrf']), 'a first visit on a read copy: works, gets its token and cookie', $g);
$onWriter = new Client($base);
$onWriter->cookies($guest->cookies());
$onWriter->useCsrf($g['csrf']);
$r = $onWriter->post('login', ['username' => 'alice', 'password' => 'password1']);
check(!empty($r['ok']), "... then signing in on the writer works with the read copy's token (same key in the database)", $r);

// Torii also copies the rest of /data between Pis (every 5 s); files in Garage don't need
// even that. Here only the database comes along, so this shows the Garage files alone are enough.
section('The writer moves to another Pi: files in Garage need nothing else');
$moved = "$tmp/writer-elsewhere";
mkdir($moved, 0700);
(new PDO("sqlite:$wdata/toffee.db"))->exec("VACUUM INTO '$moved/toffee.db'");   // as Litestream brings it back: no /data/uploads
[$p2, $mbase] = start_toffee($root, $moved, ['TORII_ROLE' => 'writer'] + $S3, "$tmp/moved.log");
$procs[] = $p2;
$ma = $cookies($alice, $mbase);
check(($ma->get('me')['user']['username'] ?? '') === 'alice', 'still signed in on the new Pi');
$bad = [];
foreach ([$public, $private, $docId] as $fid) {
    [$code, $h] = $fetch("$mbase?a=file&id=$fid", ['Cookie: ' . http_build_query($ma->cookies(), '', '; ')]);
    if ($code === 302 && preg_match('/^Location: (\S+)/mi', $h, $loc)) $code = $fetch(trim($loc[1]))[0];
    if ($code !== 200) $bad[$fid] = $code;
}
check(!$bad, 'every file still loads there (they are in Garage, not on the old Pi)', $bad);
check(!is_dir("$moved/uploads"), '... and nothing was written to /data/uploads on the new Pi');

section('Deleting, on the writer');
$k = $keyOf($gone);
check($alice->post('delete_post', ['id' => $goneRes['id'] ?? 0])['ok'], 'a post with a picture deleted');
for ($i = 0; $i < 20 && s3_call('HEAD', $k)[0] === 200; $i++) usleep(100000);
check(s3_call('HEAD', $k)[0] === 404, '... and its file is gone from Garage');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
