<?php
namespace Grav\Plugin\MigrateToTwo;

use RuntimeException;
use ZipArchive;

/**
 * Stages the Grav 2.0 release alongside the existing site and drops the
 * standalone wizard at webroot. Performs no Grav-side bootstrap of 2.0;
 * the wizard runs in a fresh PHP process started by the user.
 */
class Kickoff
{
    private const MIGRATE_FILE = 'migrate.php';
    private const FLAG_FILE = '.migrating';
    private const ZIP_NAME = 'grav-2.0-staged.zip';
    private const WIZARD_PATH_IN_ZIP = 'system/migrate/migrate.php';

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
        $this->extractWizard($zipPath);
        $this->placeStagedZip($zipPath);

        $token = bin2hex(random_bytes(16));
        $stageDir = $this->config['stage_dir'] ?: 'grav-2';

        $payload = [
            'token' => $token,
            'created' => time(),
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
                "A migration is already staged ({$flag}). Remove it to restart, " .
                "or visit /" . self::MIGRATE_FILE . " to resume."
            );
        }

        $stage = $this->webroot . DIRECTORY_SEPARATOR . ($this->config['stage_dir'] ?: 'grav-2');
        if (is_dir($stage)) {
            throw new RuntimeException(
                "Stage directory already exists: {$stage}. Remove it to restart."
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

    private function extractWizard(string $zipPath): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new RuntimeException("Could not open zip ({$zipPath}): code {$opened}");
        }

        $wizard = $zip->getFromName(self::WIZARD_PATH_IN_ZIP);

        // Some release zips wrap content in a top-level directory (e.g. grav-admin/...).
        // Try to detect that prefix if direct lookup failed.
        if ($wizard === false) {
            for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name && substr($name, -strlen('/' . self::WIZARD_PATH_IN_ZIP)) === '/' . self::WIZARD_PATH_IN_ZIP) {
                    $wizard = $zip->getFromName($name);
                    break;
                }
            }
        }
        $zip->close();

        if ($wizard === false || $wizard === '') {
            throw new RuntimeException(
                'Wizard file not found in release zip at ' . self::WIZARD_PATH_IN_ZIP .
                '. This release may not contain the migration payload.'
            );
        }

        $dest = $this->webroot . DIRECTORY_SEPARATOR . self::MIGRATE_FILE;
        if (file_put_contents($dest, $wizard) === false) {
            throw new RuntimeException("Failed to write wizard to {$dest}");
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
}
