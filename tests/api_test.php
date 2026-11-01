<?php
// Toffee API tests. Run from the repo:  php tests/api_test.php
// Starts its own `php -S` on a free port with an empty data folder (deleted afterwards), then
// walks through the rules in api.php: who may post what where, private copies, notifications,
// edit/delete windows, paging, polling, uploads and rate limits. No libraries needed.

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never runnable from the web

$root = dirname(__DIR__);
$data = sys_get_temp_dir() . '/toffee-test-' . bin2hex(random_bytes(4));
mkdir($data, 0700, true);
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, "$root/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$data/server.log", 'w']],
    $pipes, $root, ['TOFFEE_DATA' => $data] + getenv());
register_shutdown_function(function () use ($server, $data) {
    proc_terminate($server);
    exec('rm -rf ' . escapeshellarg($data));
});
$base = "http://127.0.0.1:$port/api.php";
for ($i = 0; $i < 50 && !@file_get_contents("$base?a=me"); $i++) usleep(100000);

require __DIR__ . '/lib.php';   // Client, check(), section(), people()

function ids(array $thread): array { return array_column($thread['posts'], 'id'); }

[$alice, $bob, $carol] = people($base, 'alice', 'bob', 'carol');

section('Speed');
$t = microtime(true);
for ($i = 0; $i < 20; $i++) $alice->get('latest');
$ms = (microtime(true) - $t) * 50;
check($ms < 60, sprintf('a polling request takes %.1f ms on average (no password hashing per request)', $ms));

section('Sessions');
check(!empty($alice->get('me')['user']), 'signed in after registering');
$anon = new Client($base);
check($anon->get('latest')['ok'] === false && $anon->status === 401, 'polling needs a profile (no counts leaked to guests)');
check($anon->get('channels')['ok'] === false, 'the channel list needs a profile');

section('Posting rules');
$r = $alice->post('post', ['board' => 'alice', 'body' => 'Hello **world** @bob']);
check($r['ok'], 'owner posts publicly on their own channel', $r);
$hello = $r['id'];
$r = $bob->post('post', ['board' => 'alice', 'body' => 'hi']);
check(!$r['ok'] && $bob->status === 403, "others can't start public posts on someone's channel", $r);
$r = $bob->post('post', ['board' => 'alice', 'body' => 'nice', 'parent_id' => $hello]);
check($r['ok'] && !$r['private'], 'others reply publicly', $r);
$bobReply = $r['id'];
$r = $bob->post('post', ['board' => 'help', 'body' => 'found a bug']);
check(!$r['ok'], 'only Help itself posts publicly on Help & Feedback', $r);
$r = $bob->post('post', ['board' => 'help', 'body' => 'found a bug', 'private' => true]);
check($r['ok'] && $r['private'], 'feedback goes privately to Help', $r);
$r = $bob->post('profile', ['display_name' => 'alice']);
check(!$r['ok'], "a display name can't copy someone else's name", $r);

section('Private replies');
$r = $bob->post('post', ['board' => 'alice', 'body' => 'psst', 'parent_id' => $hello, 'private' => true]);
check($r['ok'] && $r['private'], 'a private reply to a public post', $r);
$psst = $r['id'];
$pub = $bob->get('thread', ['user' => 'alice', 'tab' => 'public']);
check(!in_array($psst, ids($pub), true), 'the private reply is not in the public thread');
$priv = $bob->get('thread', ['user' => 'alice', 'tab' => 'private']);
$copy = array_values(array_filter($priv['posts'], fn($p) => $p['copied'] === 'Bob'))[0] ?? null;
check($copy && $copy['body'] === 'Hello **world** @bob' && !$copy['parent_id'], 'it threads under a private copy of the post', $priv['posts']);
check(in_array($psst, ids($priv), true), 'and shows on the Private tab for Bob');
check(in_array($psst, ids($alice->get('thread', ['user' => 'bob', 'tab' => 'private'])), true), '... and for Alice (in her conversation with Bob)');
check(!in_array($psst, ids($carol->get('thread', ['user' => 'alice', 'tab' => 'private'])), true), '... but not for Carol');
$r = $bob->post('post', ['board' => 'alice', 'body' => 'again', 'parent_id' => $hello, 'private' => true]);
$priv = $bob->get('thread', ['user' => 'alice', 'tab' => 'private']);
check(count(array_filter($priv['posts'], fn($p) => !$p['parent_id'])) === 1, 'a second private reply reuses the same copy');
$r = $alice->post('post', ['board' => 'alice', 'body' => 'x', 'parent_id' => $hello, 'private' => true]);
check(!$r['ok'], "you can't reply privately to your own post", $r);

section('One conversation per pair, from both channels');
$r = $alice->post('post', ['board' => 'bob', 'body' => 'hi from my side', 'private' => true]);   // written on Bob's channel
$aliceSide = $r['id'];
$bobView = ids($bob->get('thread', ['user' => 'alice', 'tab' => 'private']));
$aliceView = ids($alice->get('thread', ['user' => 'bob', 'tab' => 'private']));
check(in_array($aliceSide, $bobView, true) && in_array($psst, $bobView, true), 'Bob sees both halves on Alice\'s channel', $bobView);
sort($bobView); sort($aliceView);
check($bobView === $aliceView, "and it's the same conversation on Bob's channel", [$bobView, $aliceView]);
$conv = $bob->get('thread', ['user' => 'alice', 'tab' => 'private']);
check($conv['conversation'] === true, 'the Private tab is a conversation (flat, like a chat)');
$r = $alice->post('post', ['board' => 'bob', 'body' => 'answering psst', 'parent_id' => $psst]);   // psst lives on Alice's channel
check($r['ok'] && $r['private'], 'a reply works from either channel', $r);
$convs = $bob->get('conversations')['conversations'];
$withAlice = array_values(array_filter($convs, fn($c) => $c['with']['username'] === 'alice'));
check(count($withAlice) === 1 && $withAlice[0]['latest']['body'] === 'answering psst', "Bob's inbox: one card for Alice, with the latest message", $withAlice);
check($withAlice[0]['unread'] >= 1, 'with how many are unread', $withAlice[0]['unread'] ?? null);
$q = array_values(array_filter($bob->get('thread', ['user' => 'alice', 'tab' => 'private'])['posts'], fn($p) => $p['body'] === 'answering psst'))[0] ?? null;
check($q && $q['quote'] && $q['quote']['text'] === 'psst', 'and quotes what it answers', $q);
$unread = fn() => array_values(array_filter($bob->get('conversations')['conversations'], fn($c) => $c['with']['username'] === 'alice'))[0]['unread'];
$before = $unread();
$opened = $bob->get('thread', ['user' => 'alice', 'tab' => 'private']);
check($opened['unread'] === $before && $unread() === $before, 'opening the conversation says how many are new, and changes nothing (a GET)', [$opened['unread'], $before]);
check($bob->post('conversation_read', ['user' => 'alice'])['ok'] && $unread() === 0, '... then the page marks them read (a POST)');
$sig = $bob->get('latest', ['conv' => 'alice'])['sig'];
$alice->post('post', ['board' => 'alice', 'body' => 'note to self', 'private' => true]);
check($bob->get('latest', ['conv' => 'alice'])['sig'] === $sig, "Alice's note to herself isn't part of it");
$alice->post('post', ['board' => 'alice', 'body' => 'from my own channel', 'private' => true, 'parent_id' => $psst]);
check($bob->get('latest', ['conv' => 'alice'])['sig'] !== $sig, 'a new message from either side changes its fingerprint');
$notes = array_values(array_filter($alice->get('conversations')['conversations'], fn($c) => $c['notes']));
check(count($notes) === 1 && $notes[0]['latest']['body'] === 'note to self', 'your notes to yourself are one card too', $notes);
check(in_array('note to self', array_column($alice->get('thread', ['user' => 'alice', 'tab' => 'private'])['posts'], 'body'), true), 'and open as your own conversation');

section('Notifications');
$n = $alice->get('notifications');
$kinds = array_column($n['items'], 'kind');
check(in_array('reply', $kinds, true) && in_array('private', $kinds, true), 'Alice is told about the reply and the private reply', $kinds);
check(in_array('mention', array_column($bob->get('notifications')['items'], 'kind'), true), 'Bob is told he was @mentioned');
check($alice->get('latest')['unread'] === $n['unread'], 'the poll carries the same unread count');
$alice->post('notifications_read', []);
check($alice->get('latest')['unread'] === 0, 'mark all as read');

section('Who reacted');
$carol->post('react', ['id' => $hello, 'emoji' => '🔥']);
$bob->post('react', ['id' => $hello, 'emoji' => '👍']);
$who = $alice->get('reactions', ['id' => $hello]);
check($who['ok'] && count($who['reactions']) === 2, 'everyone who reacted, and with what', $who);
check(in_array('carol', array_column($who['reactions'], 'username'), true), 'with their names');
check(!$carol->get('reactions', ['id' => $psst])['ok'], "not for a private message you're not in");

$before = count($bob->get('notifications')['items']);
check($bob->post('notifications_clear')['ok'] && count($bob->get('notifications')['items']) === 0 && $before > 0, 'clear all notifications');

section('Edit and delete (5 minutes, author only)');
check(!$carol->post('edit_post', ['id' => $hello, 'body' => 'hacked'])['ok'], "others can't edit");
check($alice->post('edit_post', ['id' => $hello, 'body' => 'Hello again'])['ok'], 'the author edits');
check(!$alice->post('edit_post', ['id' => $copy['id'], 'body' => 'x'])['ok'], "a private copy can't be edited");
check(!$carol->post('delete_post', ['id' => $bobReply])['ok'], "others can't delete");
check($bob->post('delete_post', ['id' => $bobReply])['ok'], 'the author deletes');
check(!in_array($bobReply, ids($alice->get('thread', ['user' => 'alice', 'tab' => 'public'])), true), 'and it is gone');

section('Reactions');
check($carol->post('react', ['id' => $hello, 'emoji' => '🔥'])['ok'], 'react with an emoji');
check(!$carol->post('react', ['id' => $hello, 'emoji' => 'lol'])['ok'], 'text is not a reaction');
check(!$carol->post('react', ['id' => $psst, 'emoji' => '🔥'])['ok'], "can't react to a private post you're not in");

section('Polling fingerprint');
$sig = $carol->get('latest', ['board' => 'alice'])['sig'];
check($sig === $carol->get('thread', ['user' => 'alice', 'tab' => 'public'])['sig'], 'the poll and the thread agree');
check($sig === $carol->get('latest', ['board' => 'alice'])['sig'], 'unchanged when nothing happens');
$alice->post('post', ['board' => 'alice', 'body' => 'news']);
check(($s2 = $carol->get('latest', ['board' => 'alice'])['sig']) !== $sig, 'changes with a new post');
$carol->post('react', ['id' => $hello, 'emoji' => '❤️']);
check(($s3 = $carol->get('latest', ['board' => 'alice'])['sig']) !== $s2, 'changes when a reaction changes');
$bob->post('post', ['board' => 'bob', 'body' => 'elsewhere']);
check($carol->get('latest', ['board' => 'alice'])['sig'] === $s3, "doesn't change for posts on other channels");
$bob->post('post', ['board' => 'alice', 'body' => 'private to alice', 'private' => true]);
check($carol->get('latest', ['board' => 'alice'])['sig'] === $s3, "doesn't change for private posts you can't see");

section('Home feed');
$carol->post('react', ['id' => array_column($carol->get('channels')['channels'], null, 'username')['alice']['latest']['id'], 'emoji' => '❤️']);
$home = array_column($carol->get('channels')['channels'], null, 'username');
$al = $home['alice']['latest'];
check($al['author_user']['username'] === 'alice' && !$al['mine'], "each latest post says who wrote it (and that it isn't Carol's)", $al['author_user']);
check(($al['reactions']['❤️'] ?? 0) === 1 && $al['my_reaction'] === '❤️', "with its reactions, and Carol's own", $al['reactions']);
$top = $home['carol']['latest'] ?? null;
$r = $alice->post('post', ['board' => 'alice', 'body' => 'fresh']);
$fresh = $r['id'];
$alice->post('post', ['board' => 'alice', 'body' => 'r1', 'parent_id' => $fresh]);
$r2 = $bob->post('post', ['board' => 'alice', 'body' => 'r2', 'parent_id' => $fresh]);
$bob->post('post', ['board' => 'alice', 'body' => 'r3', 'parent_id' => $r2['id']]);
$bob->post('post', ['board' => 'alice', 'body' => 'secret', 'parent_id' => $fresh, 'private' => true]);
$al = array_column($carol->get('channels')['channels'], null, 'username')['alice']['latest'];
check($al['id'] === $fresh && $al['replies'] === 3, 'the reply count includes replies of replies, not private ones', $al['replies']);

section('Feed fingerprint');
$fsig = fn() => $bob->get('latest', ['feed' => 1])['feed_sig'];
$f0 = $fsig();
check(is_string($f0) && $f0 === $fsig(), 'unchanged when nothing happens', $f0);
$alice->post('post', ['board' => 'alice', 'body' => 'private aside', 'private' => true]);
check($fsig() === $f0, "doesn't change for private posts");
$r = $alice->post('post', ['board' => 'alice', 'body' => 'for the feed']);
check(($f1 = $fsig()) !== $f0, 'changes with a new public post');
$carol->post('post', ['board' => 'alice', 'body' => 'a reply', 'parent_id' => $r['id']]);
check(($f2 = $fsig()) !== $f1, 'and with a reply (the feed counts them)');
$carol->post('react', ['id' => $r['id'], 'emoji' => '🔥']);
check(($f3 = $fsig()) !== $f2, 'and with a reaction');
$alice->post('edit_post', ['id' => $r['id'], 'body' => 'for the feed (edited)']);
check(($f4 = $fsig()) !== $f3, 'and with an edit');
$carol->post('profile', ['display_name' => 'Carol', 'bio' => 'new bio']);
check(($f5 = $fsig()) !== $f4, 'and when someone changes their profile');
people($base, 'newcomer');
check($fsig() !== $f5, 'and when someone new joins');
check(!isset($bob->get('latest')['feed_sig']), 'only when asked for');

section('Paging');
for ($i = 1; $i <= 35; $i++) $carol->post('post', ['board' => 'carol', 'body' => "post $i"]);
$t1 = $bob->get('thread', ['user' => 'carol', 'tab' => 'public']);
check($t1['roots'] === 30 && $t1['has_more'], 'the newest 30 top posts first, with more to load', [$t1['roots'], $t1['has_more']]);
check($t1['posts'][0]['body'] === 'post 6', 'oldest loaded is post 6', $t1['posts'][0]['body']);
$t2 = $bob->get('thread', ['user' => 'carol', 'tab' => 'public', 'roots' => 60]);
check($t2['roots'] === 35 && !$t2['has_more'], 'Show older loads the rest');
$first = $t2['posts'][0]['id'];
$r = $bob->post('post', ['board' => 'carol', 'body' => 'old reply', 'parent_id' => $first]);
$t3 = $alice->get('thread', ['user' => 'carol', 'tab' => 'public', 'include' => $r['id']]);
check(in_array($r['id'], ids($t3), true) && $t3['roots'] === 35, 'a notification for an old reply loads back far enough to show it');

section('Uploads');
$img = "$root/toffee.png";
$r = $alice->post('upload', [], $img);
check($r['ok'] && $r['kind'] === 'image', 'upload a picture', $r);
$up = $r['id'];
$file = fn(Client $c) => $c->raw('file', ['id' => $up])[0];
check($file($alice) === 200 && $file($bob) === 403, 'an unused upload is seen only by its owner');
$r = $alice->post('post', ['board' => 'alice', 'body' => 'just for bob?', 'upload_ids' => [$up], 'private' => true]);
check($r['ok'], 'attach it to a private post to yourself', $r);
check($file($bob) === 403, 'a private attachment stays private');
$bob->headers = ['If-None-Match: "u' . $up . '-' . filesize($img) . '"'];
check($file($bob) === 403, 'even when asked for with its ETag');
$bob->headers = [];
$r = $alice->post('upload', [], $img);
$up2 = $r['id'];
$r = $alice->post('post', ['board' => 'alice', 'body' => 'look', 'upload_ids' => [$up2]]);
$photoPost = $r['id'];
check($bob->raw('file', ['id' => $up2])[0] === 200, 'a public attachment is seen by everyone');
$files = fn() => count(glob("$data/uploads/*"));
$before = $files();
check($alice->post('delete_post', ['id' => $photoPost])['ok'], 'delete the post with the photo');
check($files() === $before - 1 && $alice->raw('file', ['id' => $up2])[0] === 404, 'its photo is removed from disk too');
check(!$alice->post('post', ['board' => 'alice', 'body' => 'reuse', 'upload_ids' => [$up]])['ok'], "an attachment can't be reused in another post");

section('Videos, documents, anything but programs');
$tmp = fn($name, $bytes) => (function () use ($name, $bytes, $data) { $p = "$data/$name"; file_put_contents($p, $bytes); return $p; })();
$mp4 = $tmp('clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x01", 5000));
$r = $alice->post('upload', [], $mp4);
check($r['ok'] && $r['kind'] === 'video', 'a video upload is a video', $r);
$vid = $r['id'];
$alice->post('post', ['board' => 'alice', 'body' => 'my clip', 'upload_ids' => [$vid]]);
[$code, $part] = (function () use ($base, $alice, $vid) {
    $alice->headers = ['Range: bytes=10-19'];
    $res = $alice->raw('file', ['id' => $vid]);
    $alice->headers = [];
    return $res;
})();
check($code === 206 && strlen($part) === 10, 'videos can be fetched in parts (iPhones need it to play them)', [$code, strlen((string)$part)]);
$head = $alice->head('file', ['id' => $vid]);
preg_match('/^ETag: (".+")/mi', $head, $etag);
check(!empty($etag[1]) && stripos($head, 'immutable') !== false, 'uploads come with an ETag, cached for good (they never change)', substr($head, 0, 400));
$asked = function (Client $c, array $headers) use ($vid) { $c->headers = $headers; $res = $c->raw('file', ['id' => $vid]); $c->headers = []; return $res; };
[$code, $again] = $asked($alice, ["If-None-Match: {$etag[1]}"]);
check($code === 304 && $again === '', 'asked again with it: 304, nothing re-sent', [$code, strlen((string)$again)]);
check($asked($alice, ['If-None-Match: "u0-0"', 'Range: bytes=10-19'])[0] === 206, 'a different ETag still gets the file, in parts too');
check($asked(new Client($base), ["If-None-Match: {$etag[1]}"])[0] === 403, "the ETag doesn't get past the sign-in check");
$homeAtt = array_column($bob->get('channels')['channels'], null, 'username')['alice']['latest']['attachments'] ?? [];
check(count($homeAtt) === 1 && $homeAtt[0]['kind'] === 'video', 'a video in the latest post shows on home too', $homeAtt);
$r = $alice->post('upload', [], $mp4, ['purpose' => 'voice']);
check($r['ok'] && $r['kind'] === 'audio', 'a recorded voice note in an MP4 box is still a voice note', $r);
$r = $alice->post('upload', [], $tmp('notes.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"));
check($r['ok'] && $r['kind'] === 'file', 'a PDF is a document', $r);
$r = $alice->post('upload', [], $tmp('budget.csv', "a,b\n1,2\n"));
check($r['ok'] && $r['kind'] === 'file', 'so is a spreadsheet, or any other file', $r);
$r = $alice->post('upload', [], $tmp('page.html', '<html><script>alert(1)</script></html>'));
$html = $r['id'] ?? 0;
$alice->post('post', ['board' => 'alice', 'body' => 'page', 'upload_ids' => [$html]]);
$head = $bob->head('file', ['id' => $html]);
check($r['ok'] && str_contains($head, ' 200 ') && stripos($head, 'Content-Disposition: attachment') !== false && stripos($head, 'sandbox') !== false,
    'other files are only ever downloaded, never opened inside toffee', substr($head, 0, 400));
$r = $alice->post('upload', [], $tmp('setup.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff" . str_repeat("\x00", 200)));
check(!$r['ok'], 'programs are refused', $r);
$r = $alice->post('upload', [], $tmp('notes.txt.sh', "#!/bin/sh\necho hi\n"));
check(!$r['ok'], 'so are scripts', $r);
$r = $alice->post('upload', [], $tmp('photo.jpg', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff" . str_repeat("\x00", 200)));
check(!$r['ok'], 'a program with a harmless name is still refused (by its contents)', $r);

section('Recovery keys');
$d = new Client($base);
$d->headers = ['X-Forwarded-For: 198.51.100.250'];
$r = $d->post('register', ['username' => 'dave', 'password' => 'password1']);
$key = $r['recovery_key'] ?? '';
check((bool)preg_match('/^TOFF(-[0-9A-HJKMNP-TV-Z]{4}){4}$/', $key), 'a new profile gets a recovery key', $key);
check($d->get('me')['user']['has_recovery'] === true, 'and the app knows it has one');
$r = $d->post('login', ['username' => 'dave', 'password' => 'password1']);
check(!isset($r['recovery_key']), "signing in again doesn't make a new key");
$other = new Client($base);   // dave's other phone
$other->post('login', ['username' => 'dave', 'password' => 'password1']);
$thief = new Client($base);
$r = $thief->post('recover', ['username' => 'dave', 'key' => 'TOFF-0000-0000-0000-0000', 'password' => 'stolen123']);
check(!$r['ok'], 'a wrong key is refused', $r);
$r = $thief->post('recover', ['username' => 'alice', 'key' => $key, 'password' => 'stolen123']);
check(!$r['ok'], "a key only works for its own profile");
$typed = strtolower(str_replace('-', ' ', $key));   // as someone might type it: lower case, spaces
$r = $d->post('recover', ['username' => 'dave', 'key' => $typed, 'password' => 'newpassword']);
check($r['ok'] && !empty($r['recovery_key']) && $r['recovery_key'] !== $key, 'the key sets a new password (typed loosely) and gives a new key', $r);
$newKey = $r['recovery_key'] ?? '';
check(!empty($d->get('me')['user']), 'and signs you in');
check(empty($other->get('me')['user']), 'every other device is signed out');
check(!$d->post('recover', ['username' => 'dave', 'key' => $key, 'password' => 'again1234'])['ok'], 'the old key no longer works');
$x = new Client($base);
check(!$x->post('login', ['username' => 'dave', 'password' => 'password1'])['ok'], 'the old password no longer works');
check($x->post('login', ['username' => 'dave', 'password' => 'newpassword'])['ok'], 'the new one does');
check(!$d->post('recovery_new', ['password' => 'wrong'])['ok'], 'a new key from Edit profile needs your password');
$r = $d->post('recovery_new', ['password' => 'newpassword']);
check($r['ok'] && $r['recovery_key'] !== $newKey, 'and makes one, replacing the last', $r);
check(!$d->post('recover', ['username' => 'help', 'key' => '', 'password' => 'password1'])['ok'], "Help can't be recovered without its key");

section('Phone notifications');
$k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$ec = openssl_pkey_get_details($k)['ec'];
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$sub = fn($n) => ['endpoint' => "https://push.invalid/$n", 'keys' => ['p256dh' => $b64("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)), 'auth' => $b64(random_bytes(16))]];
$s1 = $sub('one');
check($bob->post('push_subscribe', $s1)['ok'], 'turn notifications on');
check(!$bob->post('push_subscribe', ['endpoint' => 'http://x', 'keys' => []])['ok'], 'a broken subscription is refused');
$inside = $sub('x'); $inside['endpoint'] = 'https://10.20.30.40/admin';
check(!$bob->post('push_subscribe', $inside)['ok'], "a 'subscription' pointing inside the network is refused (the Pi only talks to the push services)");
$sw = new Client($base);   // the service worker: no session, no CSRF token
$s2 = $sub('two');
$r = $sw->post('push_resubscribe', ['old_endpoint' => $s1['endpoint'], 'old_auth' => 'nope', 'sub' => $s2]);
check(!$r['ok'], "a replacement without the old subscription's secret is refused");
$r = $sw->post('push_resubscribe', ['old_endpoint' => $s1['endpoint'], 'old_auth' => $s1['keys']['auth'], 'sub' => $s2]);
check($r['ok'], 'the service worker swaps in a new subscription', $r);
$db = new PDO("sqlite:$data/toffee.db");
$rows = $db->query("SELECT endpoint, user_id FROM push_subs")->fetchAll(PDO::FETCH_KEY_PAIR);
$bobId = (int)$db->query("SELECT id FROM users WHERE username = 'bob'")->fetchColumn();
check(!isset($rows[$s1['endpoint']]) && ($rows[$s2['endpoint']] ?? 0) === $bobId, 'still for Bob, old one gone', $rows);
$r = $bob->post('push_test');
check($r['ok'] && $r['devices'] === 1 && $r['sent'] === 0, 'Send me a test reports what was delivered', $r);
check(!$carol->post('push_test')['ok'], 'Send me a test with notifications off says so');

section('Help & Feedback');
$db = new PDO("sqlite:$data/toffee.db");
// The admin signs in as 'help' (the real password is only in HELP_PASS_HASH; here a test one).
$db->prepare("UPDATE users SET pass = ? WHERE username = 'help'")->execute([password_hash('help-test-pass', PASSWORD_DEFAULT)]);
$admin = new Client($base);
$admin->headers = ['X-Forwarded-For: 198.51.100.240'];
$r = $admin->post('login', ['username' => 'help', 'password' => 'help-test-pass']);
check($r['ok'] && !empty($r['recovery_key']), 'the admin signs in as help, and gets its recovery key', $r);
check($admin->get('me')['user']['pinned'] === true, 'Help is pinned at the top');
$r = $admin->post('post', ['board' => 'help', 'body' => 'New: replies from home!']);
check($r['ok'], 'the admin posts an announcement', $r);
$news = $r['id'];
$who = $db->query("SELECT u.username FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.post_id = $news AND n.kind = 'announcement'")->fetchAll(PDO::FETCH_COLUMN);
check(in_array('carol', $who, true) && in_array('dave', $who, true) && !in_array('help', $who, true), 'everyone is told about it (but not Help itself)', $who);
check($carol->post('post', ['board' => 'help', 'body' => 'love it', 'parent_id' => $news])['ok'], 'everyone replies publicly');
$fromBob = array_values(array_filter($admin->get('conversations')['conversations'], fn($c) => $c['with']['username'] === 'bob'))[0] ?? null;
check($fromBob && $fromBob['latest']['body'] === 'found a bug', "the admin finds Bob's feedback in Help's inbox", $fromBob);

// A database from before: feedback posted publicly on Help moves to its Private tab.
$helpId = (int)$db->query("SELECT id FROM users WHERE username = 'help'")->fetchColumn();
$ids = [];
foreach (['bob' => 'old bug report', 'carol' => 'old idea'] as $name => $body) {
    $uid = (int)$db->query("SELECT id FROM users WHERE username = '$name'")->fetchColumn();
    $db->prepare('INSERT INTO posts (board_user_id, author_id, body, created_at) VALUES (?, ?, ?, ?)')->execute([$helpId, $uid, $body, time()]);
    $ids[$name] = (int)$db->lastInsertId();
}
$db->prepare('INSERT INTO posts (board_user_id, author_id, parent_id, body, created_at) VALUES (?, ?, ?, ?, ?)')
   ->execute([$helpId, $helpId, $ids['bob'], 'thanks, fixing it', time()]);
$db->exec('PRAGMA user_version = 2');
$carol->get('me');   // the next request upgrades it
check((int)$db->query('PRAGMA user_version')->fetchColumn() === 11, 'the upgrade runs once');
$idx = $db->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(PDO::FETCH_COLUMN);
check(!array_diff(['posts_author', 'posts_recipient', 'notifications_unread', 'push_subs_user', 'posts_board_roots'], $idx), 'and adds the version 4 indexes', $idx);
$pub = array_column($d->get('thread', ['user' => 'help', 'tab' => 'public'])['posts'] ?? [], 'body');
check(!in_array('old bug report', $pub, true) && !in_array('old idea', $pub, true), 'old feedback is no longer public', $pub);
$bobPriv = array_column($bob->get('thread', ['user' => 'help', 'tab' => 'private'])['posts'], 'body');
check(in_array('old bug report', $bobPriv, true) && in_array('thanks, fixing it', $bobPriv, true) && !in_array('old idea', $bobPriv, true),
    "Bob's old report and Help's reply are now private between them", $bobPriv);
check(empty($admin->get('me')['user']), 'the upgrade signs out old Help sessions (new password)');
check(!(new Client($base))->post('login', ['username' => 'help', 'password' => 'help-test-pass'])['ok'], 'and the Help password is the one in HELP_PASS_HASH');

section('Deleting a profile');
[$gone, $friend] = people($base, 'goner', 'friend');
$r = $gone->post('upload', [], "$root/toffee.png");
$pic = $r['id'];
$picFile = $db->query("SELECT name FROM uploads WHERE id = $pic")->fetchColumn();
$gone->post('post', ['board' => 'goner', 'body' => 'my post', 'upload_ids' => [$pic]]);
$myPost = (int)$db->query("SELECT MAX(id) FROM posts WHERE body = 'my post'")->fetchColumn();
$r = $friend->post('upload', [], "$root/toffee.png");
$friendsPic = $r['id'];
$friendsPicFile = $db->query("SELECT name FROM uploads WHERE id = $friendsPic")->fetchColumn();
$friend->post('post', ['board' => 'goner', 'body' => 'nice!', 'parent_id' => $myPost, 'upload_ids' => [$friendsPic]]);
$friend->post('post', ['board' => 'goner', 'body' => 'just between us', 'private' => true]);
$gone->post('post', ['board' => 'friend', 'body' => 'reply in private', 'private' => true]);
$gone->post('react', ['id' => (int)$db->query("SELECT id FROM posts WHERE body = 'nice!'")->fetchColumn(), 'emoji' => '👍']);
$friend->post('post', ['board' => 'friend', 'body' => 'friend keeps this']);
$gid = (int)$db->query("SELECT id FROM users WHERE username = 'goner'")->fetchColumn();
check(!$gone->post('delete_account', ['password' => 'wrong'])['ok'], 'deleting needs your password');
$r = $gone->post('delete_account', ['password' => 'password1']);
check($r['ok'], 'delete my profile', $r);
check(!(int)$db->query("SELECT COUNT(*) FROM users WHERE id = $gid")->fetchColumn(), 'the profile is gone');
check(!(int)$db->query("SELECT COUNT(*) FROM posts WHERE author_id = $gid OR recipient_id = $gid OR board_user_id = $gid")->fetchColumn(), 'and every post and message to or from it');
check(!(int)$db->query("SELECT COUNT(*) FROM posts WHERE body = 'nice!'")->fetchColumn(), "and the replies others left on its posts");
check(!(int)$db->query("SELECT COUNT(*) FROM post_reactions WHERE user_id = $gid")->fetchColumn() && !(int)$db->query("SELECT COUNT(*) FROM uploads WHERE owner_id = $gid")->fetchColumn(), 'and its reactions and uploads');
check(!is_file("$data/uploads/$picFile"), "its photo is removed from the disk");
check(!is_file("$data/uploads/$friendsPicFile"), "and so is the photo in the reply that went with it");
check((int)$db->query("SELECT COUNT(*) FROM posts WHERE body = 'friend keeps this'")->fetchColumn() === 1, "everyone else's posts stay");
check(empty($gone->get('me')['user']), 'signed out');
check(!(new Client($base))->post('login', ['username' => 'goner', 'password' => 'password1'])['ok'], "and can't sign in again");
check(!array_filter($friend->get('conversations')['conversations'], fn($c) => $c['with']['username'] === 'goner'), 'the conversation is gone for the other person too');
$db->prepare("UPDATE users SET pass = ? WHERE username = 'help'")->execute([password_hash('help-test-pass', PASSWORD_DEFAULT)]);
$admin = new Client($base);
$admin->headers = ['X-Forwarded-For: 198.51.100.241'];
$admin->post('login', ['username' => 'help', 'password' => 'help-test-pass']);
check(!empty($admin->get('me')['user']), 'the admin is signed in');
$adminDel = $admin->post('delete_account', ['password' => 'help-test-pass']);
check(!$adminDel['ok'], "toffee's own Help channel can't be deleted", $adminDel);

section('Rate limits (behind a pretend Traefik)');
$x = new Client($base);
$codes = [];
for ($i = 0; $i < 12; $i++) {
    // The visitor makes up a new address each time; the proxy adds the real one at the end.
    $x->headers = ["X-Forwarded-For: 10.0.0.$i, 203.0.113.7"];
    $x->post('login', ['username' => 'alice', 'password' => 'wrong']);
    $codes[] = $x->status;
}
check($codes[9] === 400 && $codes[10] === 429, 'the 11th wrong password in 10 minutes is refused, made-up addresses or not', $codes);
$y = new Client($base);
$y->headers = ['X-Forwarded-For: 203.0.113.99'];
$codes = [];
for ($i = 0; $i < 6; $i++) { $y->post('register', ['username' => "spam$i", 'password' => 'password1']); $codes[] = $y->status; $y->post('logout'); }
check($codes[4] === 200 && $codes[5] === 429, 'at most 5 new profiles an hour from one address', $codes);

section('Static files (router.php)');
$site = "http://127.0.0.1:$port";
$fetch = function (string $path, array $headers = []) use ($site): array {   // [status, headers, body]
    $ch = curl_init($site . $path);
    $h = '';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$h) { $h .= $line; return strlen($line); }]);
    $body = (string)curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $h, $body];
};
$hiddenCodes = array_map(fn($p) => $fetch($p)[0], ['/.git/config', '/.git/HEAD', '/tests/api_test.php', '/docs/circles.md', '/.playwright-mcp/', '/router.php',
    '/.torii.yml', '/%2egit/config', '/vendor/../.git/config']);
check($hiddenCodes === array_fill(0, count($hiddenCodes), 404), "what isn't part of the site is not found (.git, tests, docs...)", $hiddenCodes);
[$code, $h] = $fetch('/toffee.png');
check($code === 200 && preg_match('/^ETag: (".+")/mi', $h, $etag) && stripos($h, 'Cache-Control: public, max-age=86400') !== false, 'pictures come with an ETag and a day of caching', $h);
check($fetch('/toffee.png', ["If-None-Match: {$etag[1]}"])[0] === 304, "asked again with its ETag: 304, nothing re-sent");
[$code, $h, $body] = $fetch('/vendor/emojibase-data-17.0.0/en/messages.json', ['Accept-Encoding: gzip']);
check($code === 200 && stripos($h, 'immutable') !== false && stripos($h, 'Content-Encoding: gzip') !== false
    && json_decode((string)gzdecode($body), true) !== null, 'vendor files are cached for good, and gzipped', $h);
preg_match('/^ETag: (".+")/mi', $h, $etag);
check($fetch('/vendor/emojibase-data-17.0.0/en/messages.json', ['Accept-Encoding: gzip', "If-None-Match: {$etag[1]}"])[0] === 304, 'and revalidate with a 304');
check(stripos($fetch('/sw.js')[1], 'Cache-Control: no-cache') !== false, 'the service worker is always checked for a new version');
[$code, $h, $body] = $fetch('/', ['Accept-Encoding: gzip']);
check($code === 200 && stripos($h, 'Content-Encoding: gzip') !== false && str_contains((string)gzdecode($body), '<title>toffee</title>'), 'the page is gzipped', substr($h, 0, 300));
$alice->headers = ['Accept-Encoding: gzip'];
[$code, $body] = $alice->raw('channels');
$plain = $alice->raw('file', ['id' => $up])[1];
$alice->headers = [];
check($code === 200 && (json_decode((string)@gzdecode($body), true)['ok'] ?? false), 'so are big API replies', substr($body, 0, 40));
check($plain === file_get_contents($img), 'but never files (they are sent as they are, in parts if asked)');

section('Several copies (DATA.md): reads write nothing, sign-ins need no files');
check(trim((string)@file_get_contents("http://127.0.0.1:$port/healthz")) === 'ok', '/healthz says ok');
$snap = fn() => [md5_file("$data/toffee.db"), @filesize("$data/toffee.db-wal"), @filemtime("$data/toffee.db-wal")];
$alice->post('post', ['board' => 'alice', 'body' => 'before the quiet part']);
clearstatcache();
$was = $snap();
foreach (['me', 'latest', 'channels', 'conversations', 'notifications', 'library', 'push_key'] as $act) $alice->get($act);
$alice->get('latest', ['board' => 'bob', 'feed' => 1]);
$alice->get('thread', ['user' => 'bob', 'tab' => 'private']);
$alice->get('thread', ['user' => 'bob', 'tab' => 'public']);
$alice->raw('file', ['id' => $up]);
(new Client($base))->get('me');   // someone new
clearstatcache();
check($snap() === $was, 'a dozen kinds of GET leave the database exactly as it was', [$was, $snap()]);
check(!is_dir("$data/sessions") && !is_file("$data/vapid.json"), 'no session files, and the phone-notification keys are in the database');
[$a1, $a2] = [new Client($base), new Client($base)];
$a1->post('login', ['username' => 'alice', 'password' => 'password1']);
$a2->post('login', ['username' => 'alice', 'password' => 'password1']);
$kept = $a1->cookies();
check($a1->post('logout')['ok'] && empty($a1->get('me')['user']), 'Sign out signs this device out');
check(($a2->get('me')['user']['username'] ?? '') === 'alice', '... and not your others');
$copy = new Client($base);
$copy->cookies($kept);
check(empty($copy->get('me')['user']), "... and a copy of its old cookie doesn't sign anyone in");
$forged = new Client($base);
$forged->cookies(['tf_auth' => preg_replace('/^\d+/', (string)$db->query("SELECT id FROM users WHERE username = 'bob'")->fetchColumn(), $kept['tf_auth'])]);
check(empty($forged->get('me')['user']), "a cookie changed by hand doesn't sign anyone in");
// Signed in before this version (a PHP session file): still signed in, moved to the new cookie.
mkdir("$data/sessions", 0700);
$sid = bin2hex(random_bytes(13));
$old = str_repeat('ab', 32);
file_put_contents("$data/sessions/sess_$sid", 'csrf|s:64:"' . $old . '";uid|i:' . $db->query("SELECT id FROM users WHERE username = 'carol'")->fetchColumn()
    . ';gen|i:' . $db->query("SELECT session_gen FROM users WHERE username = 'carol'")->fetchColumn() . ';renewed|i:1;');   // (her own number: new profiles start at a random one)
$legacy = new Client($base);
$legacy->cookies(['tf_session' => $sid]);
$r = $legacy->get('latest');
check($r['ok'] && !empty($r['csrf']) && !empty($legacy->cookies()['tf_auth']) && ($legacy->cookies()['tf_session'] ?? '') !== $sid, 'signed in before this version: still signed in, with the new cookie', $r);
check(($legacy->get('me')['user']['username'] ?? '') === 'carol' && $legacy->post('post', ['board' => 'carol', 'body' => 'still here'])['ok'], '... and can still post');
exec('rm -rf ' . escapeshellarg("$data/sessions"));

section('How phone notifications are sent (RFC 8030)');
require_once "$root/webpush.php";
check(webpush_headers([]) === ['TTL: 86400', 'Urgency: normal'], 'ordinary: kept a day, normal urgency', webpush_headers([]));
check(webpush_headers(['urgency' => 'high', 'ttl' => 45, 'topic' => 'call12']) === ['TTL: 45', 'Urgency: high', 'Topic: call12'],
    'a ring: urgent (wakes a sleeping phone), gone after 45 s, and replaced by the next one', webpush_headers(['urgency' => 'high', 'ttl' => 45, 'topic' => 'call12']));
check(webpush_headers(['urgency' => 'shout', 'topic' => str_repeat('a/b', 20)]) === ['TTL: 86400', 'Urgency: normal', 'Topic: ' . substr(str_repeat('ab', 20), 0, 32)],
    'odd values are made safe (known urgencies only, topic: letters and digits, 32 at most)');
$src = file_get_contents("$root/api.php");
check(preg_match("/'urgency' => 'high', 'ttl' => RING_SECONDS, 'topic' => \"call\\\$id\"/", $src) && str_contains($src, "\$kind === 'private' ? ['urgency' => 'high']"),
    'rings and private messages are sent urgent; rings only while they ring');

section('Calls (only the setup goes through toffee)');
$sdp = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\ns=-\r\n";
check(!empty($alice->get('call_config')['ice'][0]['urls']), 'the app gets the STUN server(s) to use');
check(str_contains(json_encode($alice->get('call_config')), 'stun.cloudflare.com'), "... Cloudflare's by default (not Google's)");
check(!$alice->post('call_start', ['user' => 'alice', 'offer' => $sdp])['ok'], "you can't call yourself");
check(!$alice->post('call_start', ['user' => 'bob', 'offer' => 'nonsense'])['ok'], 'a call needs a real offer');
$r = $alice->post('call_start', ['user' => 'bob', 'video' => true, 'offer' => $sdp]);
check($r['ok'] && $r['id'] > 0, 'Alice calls Bob', $r);
$call = $r['id'];
$ring = $bob->get('latest')['call'] ?? null;
check($ring && $ring['id'] === $call && $ring['video'] && $ring['from']['username'] === 'alice', 'Bob is rung (?a=latest), a video call from Alice', $ring);
check(!isset($alice->get('latest')['call']) && !isset($carol->get('latest')['call']), '... and nobody else is');
$seen = $bob->get('call_poll', ['id' => $call]);
check($seen['state'] === 'ringing' && $seen['offer'] === $sdp && $seen['answer'] === null, 'Bob sees the offer', $seen);
check($alice->get('call_poll', ['id' => $call])['offer'] === null, "... Alice doesn't get her own back");
check(!$carol->get('call_poll', ['id' => $call])['ok'] && $carol->status === 404, "someone else can't look at the call");
check(!$carol->post('call_start', ['user' => 'bob', 'offer' => $sdp])['ok'] && $carol->status === 409, 'calling someone already in a call: busy');
check(!$alice->post('call_answer', ['id' => $call, 'answer' => $sdp])['ok'], "the caller can't answer her own call");
check($bob->post('call_answer', ['id' => $call, 'answer' => $sdp])['ok'], 'Bob answers');
$now = $alice->get('call_poll', ['id' => $call]);
check($now['state'] === 'accepted' && $now['answer'] === $sdp, 'Alice gets the answer', $now);
check(!isset($bob->get('latest')['call']), "Bob isn't rung any more");
check($bob->post('call_alive', ['id' => $call])['state'] === 'accepted', 'still talking (the app says so every 15 s)');
check($alice->post('call_end', ['id' => $call])['state'] === 'ended' && $bob->post('call_end', ['id' => $call])['ok'], 'Alice hangs up (and Bob too: fine)');
$note = array_values(array_filter($bob->get('thread', ['user' => 'alice', 'tab' => 'private'])['posts'], fn($p) => str_starts_with($p['body'], '📞')));
check(count($note) === 1 && preg_match('/^📞 Video call · \d+:\d\d$/', $note[0]['body']), 'one note in their conversation: "📞 Video call · 0:00"', array_column($note, 'body'));
$r = $alice->post('call_start', ['user' => 'bob', 'offer' => $sdp]);
check($bob->post('call_end', ['id' => $r['id']])['state'] === 'declined', 'Bob declines a voice call');
$before = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE kind = 'private' AND read_at IS NULL AND user_id = (SELECT id FROM users WHERE username = 'bob')")->fetchColumn();
$r = $alice->post('call_start', ['user' => 'bob', 'offer' => $sdp]);
check($alice->post('call_end', ['id' => $r['id']])['state'] === 'missed', 'Alice gives up before Bob answers: missed');
$after = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE kind = 'private' AND read_at IS NULL AND user_id = (SELECT id FROM users WHERE username = 'bob')")->fetchColumn();
check($after === $before + 1, "Bob is told he missed it (his 🔒)", [$before, $after]);
$notes = array_column(array_filter($bob->get('thread', ['user' => 'alice', 'tab' => 'private'])['posts'], fn($p) => str_starts_with($p['body'], '📞')), 'body');
check(in_array('📞 Voice call declined', $notes, true) && in_array('📞 Missed voice call', $notes, true), '... and both are noted in the conversation', $notes);
$r = $alice->post('call_start', ['user' => 'bob', 'offer' => $sdp]);
$db->prepare('UPDATE calls SET created_at = created_at - 60 WHERE id = ?')->execute([$r['id']]);
check($bob->get('call_poll', ['id' => $r['id']])['state'] === 'missed' && !isset($bob->get('latest')['call']), 'unanswered for 45 s: missed (worked out on read, nothing written)');
check($alice->post('call_start', ['user' => 'bob', 'offer' => $sdp])['ok'], '... and Bob can be called again');
$r = $alice->get('latest'); $ring = $bob->get('latest')['call']['id'];
$bob2 = new Client($base);
$bob2->post('login', ['username' => 'bob', 'password' => 'password1']);
$first = $bob->post('call_answer', ['id' => $ring, 'answer' => $sdp]);
$second = $bob2->post('call_answer', ['id' => $ring, 'answer' => $sdp]);
check($first['ok'] && !$second['ok'] && $bob2->status === 409, "answered on two of Bob's phones at once: the first one wins", [$first, $second]);
$alice->post('call_end', ['id' => $ring]);
$r2 = $alice->post('call_start', ['user' => 'bob', 'offer' => $sdp]); $ring = $r2['id'];
check($alice->post('call_ring', ['id' => $ring])['state'] === 'ringing', 'still ringing: Bob\'s phones are rung again');
$notes = fn() => count(array_filter($bob->get('thread', ['user' => 'alice', 'tab' => 'private'])['posts'], fn($p) => str_starts_with($p['body'], '📞')));
$had = $notes();
check($bob->post('call_ring', ['id' => $ring])['ok'] && $bob->post('call_end', ['id' => $ring])['ok'] && $alice->post('call_ring', ['id' => $ring])['state'] === 'declined', '... only by the caller, and only while it rings');
check($alice->post('call_end', ['id' => $ring])['ok'] && $notes() === $had + 1, 'both hanging up at once: still one note per call', [$had, $notes()]);

section('Broken characters (B1)');
// A post sent as a form (not JSON) with bytes that aren't UTF-8: saved cleaned, and home still works for everyone.
$ch = curl_init("$base?a=post");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => ['board' => 'carol', 'body' => "broken \xff\xfe text"],
    CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $carol->get('me')['csrf'], 'Cookie: ' . http_build_query($carol->cookies(), '', '; ')]]);
$r = json_decode((string)curl_exec($ch), true);
check(!empty($r['ok']), 'a post with broken characters is taken (cleaned)', $r);
$home = $bob->get('channels');
check($home['ok'] && $bob->status === 200, "... and home still works for everyone else", $bob->status);
$carolsRow = array_values(array_filter($home['channels'], fn($c) => $c['username'] === 'carol'))[0] ?? null;
check($carolsRow && mb_check_encoding((string)$carolsRow['latest']['text'], 'UTF-8') && str_contains($carolsRow['latest']['text'], 'text'), '... with the post shown, the broken bytes replaced', $carolsRow['latest']['text'] ?? null);
$odd = sys_get_temp_dir() . '/' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($odd, 'hello');
$ch = curl_init("$base?a=upload");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => ['file' => new CURLFile($odd, 'text/plain', "note \xff\xfe.txt")],
    CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $carol->get('me')['csrf'], 'Cookie: ' . http_build_query($carol->cookies(), '', '; ')]]);
$r = json_decode((string)curl_exec($ch), true);
check(!empty($r['ok']), 'a file whose name has broken characters uploads fine', $r);
unlink($odd);
$big = curl_init("$base?a=post");
curl_setopt_array($big, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => json_encode(['board' => 'carol', 'body' => str_repeat('x', 300000)]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $carol->get('me')['csrf'], 'Cookie: ' . http_build_query($carol->cookies(), '', '; ')]]);
curl_exec($big);
check(curl_getinfo($big, CURLINFO_RESPONSE_CODE) === 413, 'a huge JSON message is refused before it is read');

section('My notes');
$pub = $carol->post('post', ['board' => 'carol', 'body' => 'my public thought']);
$priv = $carol->post('post', ['board' => 'carol', 'body' => 'a private note on it', 'parent_id' => $pub['id'], 'private' => true]);
check(!$priv['ok'] && str_contains($priv['error'], 'your own comment'), 'a private reply to your own public post: not allowed (today\'s rule)', $priv);
$note = $carol->post('post', ['board' => 'carol', 'body' => 'a note to myself', 'private' => true]);
$mine = array_values(array_filter($carol->get('conversations')['conversations'], fn($c) => $c['notes']))[0] ?? null;
check($note['ok'] && $mine && $mine['latest']['body'] === 'a note to myself', 'a note written on your own Private tab: in My notes', $mine['latest']['body'] ?? null);
check(!str_contains(json_encode($bob->get('conversations')), 'a note to myself') && !in_array('a note to myself', array_column($bob->get('thread', ['user' => 'carol', 'tab' => 'public'])['posts'], 'body'), true),
    "... and nobody else sees it");

section('Pictures: only a real file is kept for a year (b8eb7e9)');
$h = $alice->head('file', ['id' => $up]);
check(preg_match('/^ETag: "u\d+-\d+"/mi', $h) && stripos($h, 'max-age=31536000, immutable') !== false, 'a file that is there: ETag, kept for a year', $h);
check(str_contains(json_encode($alice->get('channels')), 'a=file&v=2&id='), 'picture links carry v=2 (phones fetch again what they saved as missing)');
$gone = (string)$db->query("SELECT name FROM uploads WHERE id = $up")->fetchColumn();
rename("$data/uploads/$gone", "$data/uploads/$gone.away");
$h = $alice->head('file', ['id' => $up]);
check(str_contains($h, ' 404') && stripos($h, 'no-store') !== false && stripos($h, 'ETag:') === false && stripos($h, 'immutable') === false,
    'a file missing from the disk: 404, never kept, no ETag', $h);
rename("$data/uploads/$gone.away", "$data/uploads/$gone");
check(stripos($alice->head('file', ['id' => $up]), 'immutable') !== false, '... back on the disk: served (and kept) again');

section('Links');
$bad = array_map(fn($u) => $alice->get('resolve_link', ['url' => $u])['ok'], ['https://example.com/x', 'http://vm.tiktok.com/abc/',
    'https://vm.tiktok.com.evil.test/abc', 'https://www.tiktok.com/@a/video/1', 'file:///etc/passwd', 'https://127.0.0.1/']);
check($bad === array_fill(0, count($bad), false), 'resolving a link only works for TikTok short links (nothing else is fetched)', $bad);
check($anon->get('resolve_link', ['url' => 'https://vm.tiktok.com/abc/'])['ok'] === false && $anon->status === 401, '... and needs a profile');

section('One-off groups (a private message that @names others)');
// Sam writes privately to Ann and names Ben and Cat: all four see it and every reply, each in their
// conversation with Sam (Sam in his with Ann). Dee joins when named in a reply. Xan, never named, sees nothing.
[$sam, $ann, $ben, $cat, $dee, $xan] = people($base, 'gsam', 'gann', 'gben', 'gcat', 'gdee', 'gxan');
$gpic = $sam->post('upload', [], $img)['id'];
$g = $sam->post('post', ['board' => 'gann', 'private' => true, 'body' => 'Picnic Saturday? @gben @gcat', 'upload_ids' => [$gpic]]);
check($g['ok'] && $g['group'] === $g['id'] && $g['joined'] === 2, 'a private message naming two people starts a group', $g);
$sees = fn(Client $c, string $with, int $id) => in_array($id, ids($c->get('thread', ['user' => $with, 'tab' => 'private'])), true);
check($sees($ann, 'gsam', $g['id']) && $sees($ben, 'gsam', $g['id']) && $sees($cat, 'gsam', $g['id']) && $sees($sam, 'gann', $g['id']),
    'Ann, Ben and Cat see it in their conversation with Sam; Sam in his with Ann');
check(!$sees($sam, 'gben', $g['id']) && !$sees($ben, 'gann', $g['id']) && !$sees($ben, 'gcat', $g['id']), '... and nowhere else (not between other members)');
check(!$sees($xan, 'gsam', $g['id']) && !$sees($xan, 'gann', $g['id']), 'someone not named sees nothing of it');
$t = $ben->get('thread', ['user' => 'gsam', 'tab' => 'private']);
$gp = array_values(array_filter($t['posts'], fn($p) => $p['id'] === $g['id']))[0] ?? [];
check(($gp['group'] ?? null) === $g['id'] && count($t['groups'][$g['id']] ?? []) === 4, 'it says it is a group, with its 4 members', $t['groups'] ?? null);
$r1 = $ben->post('post', ['board' => 'gsam', 'private' => true, 'parent_id' => $g['id'], 'body' => 'Yes! I bring juice']);
check($r1['ok'] && $r1['group'] === $g['id'], 'a member replies', $r1);
check($sees($ann, 'gsam', $r1['id']) && $sees($cat, 'gsam', $r1['id']) && $sees($sam, 'gann', $r1['id']) && !$sees($sam, 'gben', $r1['id']),
    'everyone sees the reply in the group (Sam too, in his conversation with Ann, not with Ben)');
check(!$xan->post('post', ['board' => 'gsam', 'private' => true, 'parent_id' => $g['id'], 'body' => 'let me in'])['ok'], 'someone not in it cannot reply');
check(!$xan->post('react', ['id' => $g['id'], 'emoji' => '👍'])['ok'] && !$xan->get('reactions', ['id' => $g['id']])['ok'], '... nor react or see who reacted');
check($cat->raw('file', ['id' => $gpic])[0] === 200 && $xan->raw('file', ['id' => $gpic])[0] === 403, "its photo: members see it, others don't");
check($cat->post('react', ['id' => $r1['id'], 'emoji' => '❤️'])['ok'], 'members react');
$nb = array_values(array_filter($ann->get('notifications')['items'], fn($n) => $n['post_id'] === $r1['id']));
check(($nb[0]['with']['username'] ?? '') === 'gsam' && ($nb[0]['kind'] ?? '') === 'private', "Ann is told, and it opens her conversation with Sam (who started it)", $nb);
$r2 = $cat->post('post', ['board' => 'gsam', 'private' => true, 'parent_id' => $r1['id'], 'body' => '@gdee come too!']);
check($r2['ok'] && $r2['joined'] === 1, 'naming someone in a reply brings them in', $r2);
check($sees($dee, 'gsam', $g['id']) && $sees($dee, 'gsam', $r1['id']) && $sees($dee, 'gsam', $r2['id']), '... and they see the whole thread, from the start');
$convs = array_column(array_map(fn($c) => [$c['with']['username'], $c], $dee->get('conversations')['conversations']), 1, 0);
check(!isset($convs['gsam']), "groups aren't on the All list (only conversations between two): they're on the Groups list", array_keys($convs));
check((bool)array_filter($dee->get('my_groups')['groups'], fn($x) => $x['id'] === $g['id'] && $x['unread'] >= 1), "... where Dee's is, unread");
$sig = $ann->get('latest', ['conv' => 'gsam'])['sig'];
$ben->post('post', ['board' => 'gsam', 'private' => true, 'parent_id' => $g['id'], 'body' => 'see you']);
check($ann->get('latest', ['conv' => 'gsam'])['sig'] !== $sig, "a member's reply changes the conversation's fingerprint (it shows without reloading)");
// Between two, @names add nobody: a conversation between two stays between two.
$one = $ann->post('post', ['board' => 'gben', 'private' => true, 'body' => 'just us']);
check($one['ok'] && $one['group'] === null, 'a private message naming nobody is not a group');
$r3 = $ben->post('post', ['board' => 'gann', 'private' => true, 'parent_id' => $one['id'], 'body' => 'hey @gxan look']);
check($r3['ok'] && $r3['group'] === null && !$sees($xan, 'gann', $one['id']) && !$sees($xan, 'gben', $r3['id']), "naming someone in a reply between two adds nobody (they see none of it)");
$ann->post('conversation_read', ['user' => 'gsam']);
check(!(bool)array_filter($ann->get('my_groups')['groups'], fn($x) => $x['id'] === $g['id'] && $x['unread'] > 0), "opening the conversation with Sam marks the group's messages read");

section('Group calls (each phone to every other; here only who is in, offers and answers)');
// Sam's group (Sam, Ann, Ben, Cat, Dee from above). Xan is not in it.
$sdp = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\ns=-\r\n";
check(!$xan->post('room_start', ['group' => $g['id']])['ok'] && $xan->status === 404, 'someone not in the group cannot call it');
$room = $sam->post('room_start', ['group' => $g['id'], 'video' => false]);
check($room['ok'] && !$room['joined'], 'a member calls the group', $room);
$ring = $ben->get('latest');
check(($ring['room']['room'] ?? 0) === $room['id'] && ($ring['room']['from']['username'] ?? '') === 'gsam', "everyone else's phone rings (Ben)", $ring);
check(!isset($xan->get('latest')['room']), "... not Xan's");
check(!$xan->get('room_state', ['id' => $room['id']])['ok'] && $xan->status === 404, "Xan can't see who's in it");
$again = $ann->post('room_start', ['group' => $g['id']]);
check($again['ok'] && $again['id'] === $room['id'] && $again['joined'], "calling the group while its call is on joins that call (no second one)");
$j = $ben->post('room_join', ['id' => $room['id']]);
check($j['ok'] && $j['talking'] === [(int)$sam->get('room_state', ['id' => $room['id']])['me']], 'Ben joins: Sam is talking, so Ben will send him an offer', $j);
$samId = $j['talking'][0];
check($ben->post('room_offer', ['id' => $room['id'], 'to' => $samId, 'offer' => $sdp])['ok'], "Ben's offer to Sam");
$st = $sam->get('room_state', ['id' => $room['id']]);
check(count($st['offers']) === 1 && $st['offers'][0]['offer'] === $sdp, 'Sam gets it', $st['offers']);
check($sam->post('room_answer', ['id' => $room['id'], 'from' => $st['offers'][0]['from'], 'answer' => $sdp])['ok'], '... and answers');
$bs = $ben->get('room_state', ['id' => $room['id']]);
check(count($bs['answers']) === 1 && $bs['answers'][0]['to'] === $samId, 'Ben gets the answer');
$in = array_column(array_filter($bs['people'], fn($p) => $p['state'] === 'in'), 'username');
sort($in);
check($in === ['gben', 'gsam'], "who's in: Sam and Ben", $bs['people']);
check(!$ben->post('call_start', ['user' => 'gcat', 'offer' => $sdp])['ok'], "while in a group call, you can't start another call");
check($cat->post('room_leave', ['id' => $room['id'], 'decline' => true])['ok'] && !isset($cat->get('latest')['room']), 'Cat declines: it stops ringing for her');
$ben->post('room_leave', ['id' => $room['id']]);
$sam->post('room_leave', ['id' => $room['id']]);
check($sam->get('room_state', ['id' => $room['id']])['over'] === true, 'the last one out ends it');
$note = array_values(array_filter($ann->get('thread', ['user' => 'gsam', 'tab' => 'private'])['posts'], fn($p) => str_starts_with($p['body'], '📞 Group voice call')));
check(count($note) === 1 && str_contains($note[0]['body'], '2 people') && $note[0]['group'] === $g['id'], 'a note of it goes in the group (seen by everyone in it)', $note[0]['body'] ?? null);
check(!$ann->post('room_join', ['id' => $room['id']])['ok'], "an ended call can't be joined");

section('Adding someone to a call between two (no group)');
$c1 = $ann->post('call_start', ['user' => 'gben', 'offer' => $sdp]);
check($c1['ok'] && $ben->post('call_answer', ['id' => $c1['id'], 'answer' => $sdp])['ok'], 'Ann calls Ben, Ben answers');
check(!$xan->post('room_from_call', ['call' => $c1['id']])['ok'], "someone else can't turn their call into a group call");
$rm = $ann->post('room_from_call', ['call' => $c1['id']]);
check($rm['ok'], 'Ann adds someone: the call becomes a room, just for this call', $rm);
$poll = $ben->get('call_poll', ['id' => $c1['id']]);
check($poll['state'] === 'moved' && $poll['room'] === $rm['id'], "Ben's app learns where it moved (and moves too)", $poll);
check(!isset($ben->get('latest')['call']), "the call between two isn't ringing any more");
$aj = $ann->post('room_join', ['id' => $rm['id']]);
$bj = $ben->post('room_join', ['id' => $rm['id']]);
check($aj['ok'] && $aj['talking'] === [] && $bj['ok'] && count($bj['talking']) === 1, 'Ann is in first; Ben joins and sends her the offer (never both at once)', [$aj, $bj]);
check($ann->post('room_add', ['id' => $rm['id'], 'user' => 'gxan'])['ok'], 'Ann adds Xan (in no group with them)');
$xr = $xan->get('latest')['room'] ?? null;
check(($xr['room'] ?? 0) === $rm['id'] && ($xr['from']['username'] ?? '') === 'gann' && $xr['group'] === null, "Xan's phone rings: Ann is adding him to a call", $xr);
check(!$cat->get('room_state', ['id' => $rm['id']])['ok'], "someone not brought in can't see the call");
check(!$cat->post('room_join', ['id' => $rm['id']])['ok'], '... nor join it');
check($xan->post('room_join', ['id' => $rm['id']])['ok'] && count($xan->get('room_state', ['id' => $rm['id']])['people']) === 3, 'Xan joins: three in the call');
check(!$ben->post('room_add', ['id' => $room['id'], 'user' => 'gdee'])['ok'], "a group's call rings its members; nobody is added to it this way");
foreach ([$xan, $ben, $ann] as $who) $who->post('room_leave', ['id' => $rm['id']]);
$noteAB = array_values(array_filter($ben->get('thread', ['user' => 'gann', 'tab' => 'private'])['posts'], fn($p) => str_starts_with($p['body'], '📞 Voice call with')));
$noteAX = array_values(array_filter($xan->get('thread', ['user' => 'gann', 'tab' => 'private'])['posts'], fn($p) => str_starts_with($p['body'], '📞 Voice call with')));
check(($noteAB[0]['body'] ?? '') !== '' && str_contains($noteAB[0]['body'], 'with Gxan'), "a note in Ann and Ben's conversation: 'Voice call with Gxan'", $noteAB[0]['body'] ?? null);
check(($noteAX[0]['body'] ?? '') !== '' && str_contains($noteAX[0]['body'], 'with Gben'), "... and in Xan's with Ann, who added him: 'Voice call with Gben'", $noteAX[0]['body'] ?? null);
$gn = array_values(array_filter($cat->get('notifications')['items'], fn($n) => $n['post_id'] === $r1['id'] || $n['post_id'] === $g['id']));
check(($gn[0]['group'] ?? null) === true, "a group's messages say so on the notifications list", $gn[0] ?? null);

section('Your groups (the Groups filter on your Private tab)');
$mine = $dee->get('my_groups');
$it = array_values(array_filter($mine['groups'], fn($x) => $x['id'] === $g['id']))[0] ?? null;
check($mine['ok'] && $it && $it['with']['username'] === 'gsam' && $it['started_by'] === 'Gsam' && count($it['members']) === 5,
    "Dee's groups: the one Sam started (5 people), found in her conversation with Sam", $it);
check(str_starts_with($it['first'], 'Picnic Saturday?') && $it['messages'] >= 4, '... with how it started and how many messages', $it);
check(!array_filter($xan->get('my_groups')['groups'], fn($x) => $x['id'] === $g['id']), "someone not in it doesn't get it");
$samList = $sam->get('my_groups')['groups'];
check(($samList[0]['started_by'] ?? '') === 'You' || array_filter($samList, fn($x) => $x['id'] === $g['id'] && $x['started_by'] === 'You'), 'the one you started says You');

check(count($dee->get('group_thread', ['id' => $g['id']])['posts'] ?? []) >= 4 && $dee->get('group_thread', ['id' => $g['id']])['posts'][0]['id'] === $g['id'],
    "a group's thread, opened on the Groups list: all its messages, oldest first");
check(!$xan->get('group_thread', ['id' => $g['id']])['ok'] && $xan->status === 404, "... and only for people in it");

section('Security review fixes (2026-10-31)');
// 1. A deleted profile's id can go to the next new one: an old cookie of the deleted person must not sign in as them.
[$gone2] = people($base, 'goneagain');
$oldCookie = $gone2->cookies();
$gone2->post('delete_account', ['password' => 'password1']);
[$next] = people($base, 'nextperson');
$ghost = new Client($base);
$ghost->cookies($oldCookie);
check(($ghost->get('me')['user']['username'] ?? null) === null, "a deleted profile's old cookie signs nobody in (not even a new profile with the same id)");
// 2. Double slashes don't get past the hiding (parse_url read //tools/x.php as a host)
$dbl = array_map(fn($p) => $fetch($p)[0], ['//.git/HEAD', '//tools/precompress.php', '//tests/lib.php', '//.torii.yml', '/.//.git/HEAD', '/%2F%2Ftools/precompress.php']);
check(!array_diff($dbl, [404]), 'paths with // are refused (nothing hidden is shown or run)', $dbl);
check($fetch('/api.php?a=me&x=https://example.com/')[0] === 200, '... but a // inside ?... is fine (links)');
// 3. A fake push key (65 bytes, not a real point) is refused: it used to break pushes for everyone in its batch
$fake = ['endpoint' => 'https://push.invalid/fake', 'keys' => ['p256dh' => $b64("\x04" . str_repeat("\x01", 64)), 'auth' => $b64(random_bytes(16))]];
check(!$bob->post('push_subscribe', $fake)['ok'], 'a fake phone-notification key is refused');

// Profile pictures with a small copy: lists get it with &s=1 (the same check as the picture itself).
$smallPic = $ann->post('upload', [], $img)['id'];
$bigPic = $ann->post('upload', [], $img, ['poster_id' => $smallPic])['id'];
$ann->post('profile', ['display_name' => 'Gann', 'avatar_id' => $bigPic]);
[$codeS, $bodyS] = $xan->raw('file', ['id' => $bigPic, 's' => 1]);
[$codeB, $bodyB] = $xan->raw('file', ['id' => $bigPic]);
check($codeS === 200 && $codeB === 200, "a profile picture and its small copy are seen by everyone signed in", [$codeS, $codeB]);
[$codeX] = $xan->raw('file', ['id' => $gpic, 's' => 1]);
check($codeX === 403, "&s=1 doesn't open a private file to someone not allowed");

section('A group started from a private reply to a public post');
$pub = $sam->post('post', ['board' => 'gsam', 'body' => 'Who wants to go hiking?']);
$pr = $ann->post('post', ['board' => 'gsam', 'private' => true, 'parent_id' => $pub['id'], 'body' => 'Me! @gcat come too']);
check($pr['ok'] && $pr['group'] && $pr['joined'] === 1, 'a private reply to a public post that @names someone starts a group', $pr);
$catView = $cat->get('thread', ['user' => 'gann', 'tab' => 'private']);
$ids = array_column($catView['posts'], 'id');
check(in_array($pr['group'], $ids, true) && in_array($pr['id'], $ids, true), 'Cat sees the post (its copy) and the reply, in her conversation with Ann, who started it');
check(in_array($pr['id'], ids($sam->get('thread', ['user' => 'gann', 'tab' => 'private'])), true), '... and Sam (whose post it is) sees it with Ann');
check(!in_array($pr['id'], ids($xan->get('thread', ['user' => 'gann', 'tab' => 'private'])), true), '... nobody else');
// Talked privately under a post already: the group gets a copy of its own
$pub2 = $sam->post('post', ['board' => 'gsam', 'body' => 'Book club?']);
$two = $ben->post('post', ['board' => 'gsam', 'private' => true, 'parent_id' => $pub2['id'], 'body' => 'just between us: yes']);
$grp = $ben->post('post', ['board' => 'gsam', 'private' => true, 'parent_id' => $pub2['id'], 'body' => 'and @gdee too?']);
$deeIds = ids($dee->get('thread', ['user' => 'gben', 'tab' => 'private']));
check($grp['group'] && in_array($grp['id'], $deeIds, true) && !in_array($two['id'], $deeIds, true),
    "if you'd already talked privately under the post, the group gets its own copy: the earlier two-person talk stays private");

section('Voice notes from iPhones');
// iPhones' recordings can come as QuickTime: they used to be refused as voice notes.
$vq = $ann->post('upload', [], "$root/tests/voice-quicktime.mov", ['purpose' => 'voice', 'duration' => '1']);
check(($vq['ok'] ?? false) && $vq['kind'] === 'audio', 'a QuickTime-style recording is taken as a voice note', $vq);
check($ann->post('post', ['board' => 'gben', 'private' => true, 'body' => '', 'upload_ids' => [$vq['id']]])['ok'], '... and sent');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
