<?php
// Gzips the site's text files once, when the app is built (see .torii.Dockerfile), into .gz/,
// where router.php finds them. So no request ever writes a cache file. Run: php tools/precompress.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // (only when the app is built, never from the web)
$root = dirname(__DIR__);
require "$root/gz_name.php";
$n = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = $f->getPathname();
    if (preg_match('#/(\.|tests/|docs/|tools/)#', substr($path, strlen($root))) || !preg_match('/\.(svg|js|css|json|txt)$/i', $path)) continue;
    $gz = gz_name($root, $path);
    if (!is_dir(dirname($gz))) mkdir(dirname($gz), 0755, true);
    file_put_contents($gz, gzencode((string)file_get_contents($path), 9));
    $n++;
}
echo "precompressed $n files\n";
