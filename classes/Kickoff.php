<?php
namespace Grav\Plugin\MigrateGrav;

use RuntimeException;

/**
 * Stages the Grav 2.0 release alongside the existing site and drops the
 * standalone wizard at webroot. Performs no Grav-side bootstrap of 2.0;
 * the wizard runs in a fresh PHP process started by the user.
 *
 * The wizard is owned by THIS plugin (wizard/migrate.php) and copied to
 * webroot — not extracted from the Grav 2.0 zip. That way we can iterate
 * on the migration flow without re-releasing Grav.
 */
class Kickoff
{
    private const MIGRATE_FILE = 'migrate.php';
    private const FLAG_FILE = '.migrating';
    private const ZIP_NAME = 'grav-2.0-staged.zip';

    /** @var string */
    private $webroot;
    /** @var array */
    private $config;

    public function __construct(string $webroot, array $config)
    {
        $this->webroot = rtrim($webroot, DIRECTORY_SEPARATOR);
        $this->config = $config;
    }

    /**
     * Run the kickoff. Returns metadata describing the resulting state
     * (token, paths, next-step URL/CLI hint).
     *
     * @param array $context Optional triggering context (admin user, source, etc.)
     */
    public function run(array $context = []): array
    {
        $this->assertWebrootWritable();
        $this->assertNotAlreadyStaged();

        $zipPath = $this->obtainZip();
        $this->placeWizard();
        $this->placeStagedZip($zipPath);

        $token = bin2hex(random_bytes(16));
        $stageDir = $this->config['stage_dir'] ?: 'grav-2';

        $payload = [
            'token' => $token,
            'created' => time(),
            'step' => 'staged',
            'source' => [
                'grav_version' => $context['grav_version'] ?? null,
                'root' => $this->webroot,
                'admin_user' => $context['admin_user'] ?? null,
                'trigger' => $context['trigger'] ?? 'cli',
            ],
            'stage_dir' => $stageDir,
            'staged_zip' => 'tmp/' . self::ZIP_NAME,
            'wizard_url' => '/' . self::MIGRATE_FILE . '?token=' . $token,
        ];

        $this->writeFlag($payload);

        return $payload;
    }

    private function assertWebrootWritable(): void
    {
        if (!is_dir($this->webroot) || !is_writable($this->webroot)) {
            throw new RuntimeException("Webroot is not writable: {$this->webroot}");
        }

        $tmp = $this->webroot . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
            throw new RuntimeException("Could not create tmp dir: {$tmp}");
        }
        if (!is_writable($tmp)) {
            throw new RuntimeException("tmp/ is not writable: {$tmp}");
        }
    }

    private function assertNotAlreadyStaged(): void
    {
        $flag = $this->webroot . DIRECTORY_SEPARATOR . self::FLAG_FILE;
        if (file_exists($flag)) {
            throw new RuntimeException(
                "A migration is already staged ({$flag}). " .
                "Use Restart Wizard or Reset Migration on the Migrate Grav admin page, " .
                "or visit /" . self::MIGRATE_FILE . " to resume."
            );
        }

        $stage = $this->webroot . DIRECTORY_SEPARATOR . ($this->config['stage_dir'] ?: 'grav-2');
        if (is_dir($stage)) {
            throw new RuntimeException(
                "Stage directory already exists: {$stage}. " .
                "Use Reset Migration on the Migrate Grav admin page to clear it."
            );
        }
    }

    private function obtainZip(): string
    {
        $local = trim((string)($this->config['source_local_zip'] ?? ''));
        if ($local !== '') {
            if (!is_file($local)) {
                throw new RuntimeException("source_local_zip not found: {$local}");
            }
            return $local;
        }

        $url = (string)($this->config['source_url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('No source_url configured for Grav 2.0 release.');
        }

        // Honor the site's GPM channel: if the user runs on the testing
        // channel (system.gpm.releases: testing) and the configured source_url
        // is plain (no query string), append `?testing` so the kickoff pulls
        // the same release the rest of the admin would advertise as available.
        // If source_url already carries a query string, the user has been
        // explicit — leave it alone.
        $channel = (string)($this->config['gpm_channel'] ?? 'stable');
        if ($channel === 'testing' && !str_contains($url, '?')) {
            $url .= '?testing';
        }

        $dest = $this->webroot . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . self::ZIP_NAME;
        $this->downloadTo($url, $dest);

        if (!is_file($dest) || filesize($dest) < 1024) {
            throw new RuntimeException("Downloaded zip looks invalid: {$dest}");
        }

        return $dest;
    }

    private function downloadTo(string $url, string $dest): void
    {
        $in = @fopen($url, 'rb');
        if (!$in) {
            throw new RuntimeException("Failed to open source URL: {$url}");
        }
        $out = @fopen($dest, 'wb');
        if (!$out) {
            fclose($in);
            throw new RuntimeException("Failed to open destination for write: {$dest}");
        }
        try {
            while (!feof($in)) {
                $chunk = fread($in, 1 << 16);
                if ($chunk === false) {
                    throw new RuntimeException("Read error during download from {$url}");
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Copy the plugin's canonical wizard (wizard/migrate.php) to webroot.
     *
     * The wizard intentionally lives in this plugin rather than in the Grav
     * 2.0 release zip, so the migration flow can be iterated without Grav
     * core releases. Each kickoff overwrites any previous wizard copy.
     */
    private function placeWizard(): void
    {
        $src = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'wizard' . DIRECTORY_SEPARATOR . self::MIGRATE_FILE;
        if (!is_file($src)) {
            throw new RuntimeException("Plugin wizard source missing: {$src}");
        }

        $dest = $this->webroot . DIRECTORY_SEPARATOR . self::MIGRATE_FILE;
        if (!@copy($src, $dest)) {
            throw new RuntimeException("Failed to copy wizard to {$dest}");
        }
        @chmod($dest, 0644);
    }

    private function placeStagedZip(string $zipPath): void
    {
        $dest = $this->webroot . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . self::ZIP_NAME;
        if (realpath($zipPath) === realpath($dest)) {
            return;
        }
        if (!@copy($zipPath, $dest)) {
            throw new RuntimeException("Failed to copy staged zip to {$dest}");
        }
    }

    private function writeFlag(array $payload): void
    {
        $flag = $this->webroot . DIRECTORY_SEPARATOR . self::FLAG_FILE;
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($flag, $json) === false) {
            throw new RuntimeException("Failed to write flag file: {$flag}");
        }
        @chmod($flag, 0600);
    }

    /**
     * Reset migration state. Two modes:
     *
     *   'full'    — delete .migrating, migrate.php, the staged zip, the stage
     *               directory, and restore .htaccess. Next kickoff starts from
     *               scratch (re-download, re-stage).
     *
     *   'restart' — keep .migrating (rewound to step='staged'), keep migrate.php
     *               and the staged zip, restore .htaccess, drop only the stage
     *               directory and any transient run state. Lets the user re-run
     *               the wizard without re-downloading Grav 2.0.
     *
     * Safe to call even when nothing is staged.
     */
    public function reset(string $mode = 'full'): array
    {
        if (!in_array($mode, ['full', 'restart'], true)) {
            throw new RuntimeException("Unknown reset mode: {$mode}");
        }

        $removed = [];
        $errors  = [];
        $stageDir = trim((string)($this->config['stage_dir'] ?? 'grav-2'), '/');

        // Both modes restore .htaccess and drop the stage directory.
        $this->restoreHtaccess();

        if ($stageDir !== '') {
            $stagePath = $this->webroot . DIRECTORY_SEPARATOR . $stageDir;
            if (is_dir($stagePath)) {
                if ($this->removeDirectory($stagePath)) {
                    $removed[] = $stageDir . '/';
                } else {
                    $errors[] = "Could not fully remove {$stageDir}/";
                }
            }
        }

        if ($mode === 'restart') {
            // Rewrite .migrating with only the kickoff-time keys, step rewound
            // to 'staged'. Strips wizard-side run state (plugins_themes,
            // accounts, content, _prev_options, staged_zip_version, etc.) so
            // the wizard restarts cleanly from the staged release.
            $existing = $this->readFlag();
            if ($existing !== null) {
                $minimal = array_filter([
                    'token'      => $existing['token']      ?? null,
                    'created'    => $existing['created']    ?? time(),
                    'step'       => 'staged',
                    'source'     => $existing['source']     ?? null,
                    'stage_dir'  => $existing['stage_dir']  ?? ($this->config['stage_dir'] ?: 'grav-2'),
                    'staged_zip' => $existing['staged_zip'] ?? 'tmp/' . self::ZIP_NAME,
                    'wizard_url' => $existing['wizard_url'] ?? null,
                ], static fn($v) => $v !== null);
                $this->writeFlag($minimal);
                $removed[] = '.migrating (rewound to staged)';
            }
            return ['removed' => $removed, 'errors' => $errors, 'mode' => 'restart'];
        }

        // mode === 'full'
        $candidates = [
            self::FLAG_FILE,
            self::MIGRATE_FILE,
            'tmp/' . self::ZIP_NAME,
        ];
        foreach ($candidates as $rel) {
            $path = $this->webroot . DIRECTORY_SEPARATOR . $rel;
            if (is_file($path)) {
                if (@unlink($path)) {
                    $removed[] = $rel;
                } else {
                    $errors[] = "Could not remove {$rel}";
                }
            }
        }

        return ['removed' => $removed, 'errors' => $errors, 'mode' => 'full'];
    }

    /**
     * Parse the .migrating flag file, or null if none is present/corrupt.
     */
    public function readFlag(): ?array
    {
        $flag = $this->webroot . DIRECTORY_SEPARATOR . self::FLAG_FILE;
        if (!is_file($flag)) {
            return null;
        }
        $raw = @file_get_contents($flag);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * If the wizard's Test step patched .htaccess (with a backup), restore it.
     * Idempotent: no-op when no backup exists and no marker is present.
     */
    private function restoreHtaccess(): void
    {
        $ht = $this->webroot . DIRECTORY_SEPARATOR . '.htaccess';
        $bk = $ht . '.migrate-grav-backup';
        if (is_file($bk)) {
            @copy($bk, $ht);
            @unlink($bk);
            return;
        }
        if (is_file($ht)) {
            $cur = (string) @file_get_contents($ht);
            if (str_contains($cur, '# migrate-grav stage exclusion')) {
                $stripped = preg_replace(
                    '/^[ \t]*# migrate-grav stage exclusion.*\n[ \t]*(?:RewriteCond|RewriteBase)[^\n]*\n/m',
                    '',
                    $cur
                );
                if (is_string($stripped)) @file_put_contents($ht, $stripped);
            }
        }
    }

    /**
     * Recursively delete a directory tree.
     *
     * Symlinks are unlinked, never traversed — critical when the wizard's
     * staged tree contains symlinked plugin clones (a developer convenience
     * during iteration). Following the symlinks would attempt to delete real
     * source files outside the staged tree.
     */
    private function removeDirectory(string $path): bool
    {
        if (is_link($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return true;
        }
        $items = @scandir($path);
        if ($items === false) {
            return false;
        }
        $ok = true;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $sub = $path . DIRECTORY_SEPARATOR . $item;
            if (is_link($sub)) {
                $ok = @unlink($sub) && $ok;
            } elseif (is_dir($sub)) {
                $ok = $this->removeDirectory($sub) && $ok;
            } else {
                $ok = @unlink($sub) && $ok;
            }
        }
        return @rmdir($path) && $ok;
    }
}
