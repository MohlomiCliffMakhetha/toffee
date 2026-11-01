<?php
// Router for `php -S` (see .torii.Dockerfile): hides what isn't part of the site, and serves the
// static files itself, with cache headers (ETag, Last-Modified, Cache-Control) and gzip for text.
// PHP files (index.php, api.php) are left to php -S: `return false` means "serve it as usual".

// The path is everything before ?, as sent: parse_url would read "//tools/x.php" as a host
// ("tools") and a path "/x.php", which got past the hiding below. Repeated slashes count as one.
$path = rawurldecode(explode('?', (string)($_SERVER['REQUEST_URI'] ?? '/'), 2)[0]);
$path = '/' . ltrim((string)preg_replace('#/{2,}#', '/', str_replace('\\', '/', $path)), '/');
// (only the address itself: a link in ?url= may have //)
if (strpos(explode('?', (string)($_SERVER['REQUEST_URI'] ?? '/'), 2)[0], '//') !== false) { http_response_code(404); header('Content-Type: text/plain'); echo "Not found\n"; return true; }
// Torii checks every copy with this. It touches nothing.
if ($path === '/healthz') { header('Content-Type: text/plain'); header('Cache-Control: no-store'); echo "ok\n"; return true; }
$hidden = (bool)preg_match('#^/(tests|docs|tools)(/|$)|^/(router|gz_name|s3|webpush)\.php$#i', $path);
foreach (explode('/', str_replace('\\', '/', $path)) as $seg) $hidden = $hidden || ($seg !== '' && $seg[0] === '.');   // .git, .torii.yml...
if ($hidden) { http_response_code(404); header('Content-Type: text/plain'); echo "Not found\n"; return true; }

$TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
    'svg' => 'image/svg+xml', 'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'json' => 'application/json',
    'woff2' => 'font/woff2', 'woff' => 'font/woff', 'txt' => 'text/plain; charset=utf-8'];
$GZIP = ['svg', 'js', 'css', 'json', 'txt'];
$file = realpath(__DIR__ . $path);
$ext = strtolower(pathinfo((string)$file, PATHINFO_EXTENSION));
if ($file === false || !str_starts_with($file, __DIR__ . '/') || !is_file($file) || !isset($TYPES[$ext])) return false;

$size = filesize($file);
$mtime = filemtime($file);
$gzip = in_array($ext, $GZIP, true) && extension_loaded('zlib') && preg_match('/\bgzip\b/i', $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
$etag = '"' . dechex($size) . '-' . dechex($mtime) . ($gzip ? '-gz' : '') . '"';
// Versioned folders (vendor/picmo-5.8.5...) and ?v= links never change: a new version is a new address.
if (str_starts_with($path, '/vendor/') || isset($_GET['v'])) $cache = 'public, max-age=31536000, immutable';
elseif ($path === '/sw.js') $cache = 'no-cache';
else $cache = 'public, max-age=86400';
header('Content-Type: ' . $TYPES[$ext]);
header('Cache-Control: ' . $cache);
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if (in_array($ext, $GZIP, true)) header('Vary: Accept-Encoding');

$match = isset($_SERVER['HTTP_IF_NONE_MATCH'])
    ? in_array($etag, array_map(fn($t) => preg_replace('#^W/#', '', trim($t)), explode(',', $_SERVER['HTTP_IF_NONE_MATCH'])), true) || trim($_SERVER['HTTP_IF_NONE_MATCH']) === '*'
    : isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime;
if ($match) { http_response_code(304); return true; }

if ($gzip) {
    // Compressed once per version of the file, when the app is built (tools/precompress.php): the
    // Pi shouldn't gzip 775 KB of emoji data per visitor. Not built (on a laptop): made now, and
    // kept in the temp folder when this is the only copy of toffee (TORII_ROLE unset).
    require __DIR__ . '/gz_name.php';
    $gz = gz_name(__DIR__, $file);
    $body = is_file($gz) ? file_get_contents($gz) : false;
    if ($body === false) {
        $tmp = sys_get_temp_dir() . '/toffee-gz/' . basename($gz);
        $body = is_file($tmp) ? file_get_contents($tmp) : false;
        if ($body === false) {
            $body = gzencode((string)file_get_contents($file), 9);
            if (getenv('TORII_ROLE') === false) {
                @mkdir(dirname($tmp), 0700, true);
                @file_put_contents("$tmp." . getmypid(), $body) && @rename("$tmp." . getmypid(), $tmp);
            }
        }
    }
    header('Content-Encoding: gzip');
    header('Content-Length: ' . strlen($body));
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo $body;
    return true;
}
header('Content-Length: ' . $size);
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') readfile($file);
return true;
