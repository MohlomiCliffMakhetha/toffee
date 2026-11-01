<?php
// Where the gzipped copy of a static file is kept: named by its path, size and date, so a
// changed file gets a new copy. Shared by router.php and tools/precompress.php.
function gz_name(string $root, string $file): string {
    return "$root/.gz/" . md5($file) . '-' . filesize($file) . '-' . filemtime($file) . '.gz';
}
