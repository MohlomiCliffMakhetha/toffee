<?php
// Copies the files in /data/uploads into S3 and points the database at them. Run it once on
// the writer, with the TORII_S3_* settings, when Torii turns S3 on for toffee:
//     php tools/move-uploads-to-s3.php
// Safe to run again: files already moved are skipped. The old files are left where they are
// (delete /data/uploads by hand once toffee works from S3). See DATA.md.

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/s3.php';
if (!s3_on()) { fwrite(STDERR, "TORII_S3_ENDPOINT isn't set.\n"); exit(1); }
if (getenv('TORII_ROLE') === 'reader') { fwrite(STDERR, "Run this on the writer.\n"); exit(1); }

$data = getenv('TOFFEE_DATA') ?: '/data';
$db = new PDO("sqlite:$data/toffee.db");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout=5000');
$rows = $db->query("SELECT id, owner_id, name, kind, mime, original FROM uploads WHERE name NOT LIKE '%/%'")->fetchAll(PDO::FETCH_ASSOC);
$moved = $missing = $failed = 0;
$set = $db->prepare('UPDATE uploads SET name = ? WHERE id = ? AND name = ?');
foreach ($rows as $up) {
    $file = "$data/uploads/{$up['name']}";
    if (!is_file($file)) { $missing++; echo "missing: upload {$up['id']} ($file)\n"; continue; }
    $key = "u/{$up['owner_id']}/{$up['name']}";   // the name is already random
    if (!s3_put_file($key, $file, $up['mime'], $up['kind'], $up['original'])) { $failed++; echo "failed: upload {$up['id']}\n"; continue; }
    $set->execute([$key, $up['id'], $up['name']]);
    $moved++;
}
echo "moved $moved, missing $missing, failed $failed (of " . count($rows) . ")\n";
exit($failed ? 1 : 0);
