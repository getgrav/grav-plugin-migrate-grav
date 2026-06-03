<?php
/**
 * Grav 2.0 migration backup-zip repair tool — standalone.
 *
 * Use this if you ran the migration wizard on Windows with a version prior
 * to 1.0.0-rc.3, your promote failed partway, and now the backup zip won't
 * extract correctly — every file ends up in the zip's root with literal
 * backslashes in its name, no directory tree.
 *
 * Cause: PHP's ZipArchive on Windows stored entry names with native '\\'
 * separators, but the zip spec requires '/' as the path separator. Non-
 * strict extractors (7-zip on Windows, macOS Archive Utility, Windows
 * Explorer's in-place viewer) treat the backslashes as literal filename
 * characters instead of path separators.
 *
 * This script reads the broken zip, normalizes every entry name's
 * separators to '/', and writes a fresh zip alongside it. The fixed zip
 * can then be extracted with any standard tool (Right-click → Extract All
 * on Windows, double-click on macOS, `unzip` on Linux).
 *
 * Usage (Windows / macOS / Linux, anywhere PHP 8.1+ is installed):
 *
 *   php mg-repair-backup.php <broken-zip>
 *       → writes <broken-zip>.fixed.zip next to the original
 *
 *   php mg-repair-backup.php <broken-zip> <output-zip>
 *       → writes to a specific path
 *
 * Standalone — no Grav, no Composer autoloader, no plugin context needed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is CLI-only. Run it from a terminal.";
    exit(1);
}

if (!extension_loaded('zip')) {
    fwrite(STDERR, "PHP zip extension is required. Install php-zip and try again.\n");
    exit(2);
}

$src = $argv[1] ?? null;
$dst = $argv[2] ?? null;

if ($src === null) {
    fwrite(STDERR, "usage: php " . basename(__FILE__) . " <broken-zip> [<output-zip>]\n");
    exit(2);
}

if (!is_file($src)) {
    fwrite(STDERR, "Input zip not found: {$src}\n");
    exit(2);
}

if ($dst === null) {
    // Default: write next to the source with .fixed.zip suffix, so the
    // user can compare or roll back if anything looks off.
    if (substr($src, -4) === '.zip') {
        $dst = substr($src, 0, -4) . '.fixed.zip';
    } else {
        $dst = $src . '.fixed.zip';
    }
}

if (realpath($src) !== false && realpath(dirname($dst)) !== false
    && realpath($src) === realpath($dst)) {
    fwrite(STDERR, "Refusing to write output to the same path as input.\n");
    exit(2);
}

$in = new ZipArchive();
$rc = $in->open($src);
if ($rc !== true) {
    fwrite(STDERR, "Could not open input zip (ZipArchive code {$rc}): {$src}\n");
    exit(2);
}

@unlink($dst);
$out = new ZipArchive();
$rc = $out->open($dst, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($rc !== true) {
    $in->close();
    fwrite(STDERR, "Could not create output zip (ZipArchive code {$rc}): {$dst}\n");
    exit(2);
}

$total      = $in->numFiles;
$normalized = 0;
$copied     = 0;
$failed     = [];

for ($i = 0; $i < $total; $i++) {
    $name = $in->getNameIndex($i);
    if ($name === false) {
        $failed[] = "(index {$i}: getNameIndex failed)";
        continue;
    }

    $fixed = str_replace('\\', '/', $name);
    if ($fixed !== $name) $normalized++;

    $isDir = substr($fixed, -1) === '/';
    if ($isDir) {
        $out->addEmptyDir(rtrim($fixed, '/'));
        $copied++;
        continue;
    }

    $bytes = $in->getFromIndex($i);
    if ($bytes === false) {
        $failed[] = $name;
        continue;
    }
    if (!$out->addFromString($fixed, $bytes)) {
        $failed[] = $name;
        continue;
    }
    $copied++;
}

$in->close();
$closeOk = $out->close();

echo "── repair summary ──\n";
echo "input:           {$src}\n";
echo "output:          {$dst}\n";
echo "entries scanned: {$total}\n";
echo "entries fixed:   {$normalized} (had backslashes)\n";
echo "entries copied:  {$copied}\n";
echo "close ok:        " . ($closeOk ? 'yes' : 'NO') . "\n";

if ($failed !== []) {
    echo "FAILED entries:  " . count($failed) . "\n";
    foreach ($failed as $f) echo "  - {$f}\n";
}

if ($normalized === 0 && $failed === []) {
    echo "\nNote: input zip already had all forward-slashed entry names — it\n";
    echo "      was already valid. If you're still seeing flat extraction,\n";
    echo "      the problem is with your extractor, not the zip.\n";
}

echo "\nNext step: extract {$dst} as you normally would.\n";
echo "  Windows:  right-click → Extract All…\n";
echo "  macOS:    double-click, or unzip in Terminal\n";
echo "  Linux:    unzip " . escapeshellarg($dst) . "\n";

exit($failed !== [] || !$closeOk ? 1 : 0);
