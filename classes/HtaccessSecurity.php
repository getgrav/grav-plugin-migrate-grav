<?php
namespace Grav\Plugin\MigrateGrav;

/**
 * Detects and remediates direct web access to the sensitive `user/` folders
 * (accounts, config, data, env).
 *
 * Older Grav installs ship a site root `.htaccess` that only blocks a fixed
 * list of file extensions under `user/`. Files stored under `user/data` with
 * an unlisted extension (certificates, keys, tokens, sqlite databases, logs)
 * could therefore be downloaded directly over HTTP. Grav 2.0 blocks these
 * folders outright; this helper brings an existing install up to the same
 * protection, and warns when the webserver is not Apache and the fix has to
 * be applied to the server config by hand.
 */
class HtaccessSecurity
{
    /** Folders under user/ that must never be web-served. */
    public const SENSITIVE = ['accounts', 'config', 'data', 'env'];

    /** Signature of the folder-block rule in a patched root .htaccess. */
    private const RULE_SIGNATURE = '^(user)/(accounts|config|data|env)/';

    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    private function denyHtaccess(): string
    {
        return <<<HTACCESS
# Deny all direct web access to this folder and everything beneath it.
# Grav reads these files server-side; they must never be served over HTTP.
# This is a defense-in-depth backup for the rules in the site root .htaccess.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

HTACCESS;
    }

    public function serverSoftware(): string
    {
        return strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
    }

    /**
     * LiteSpeed honours .htaccess too, so it counts as Apache-compatible here.
     */
    public function isApache(): bool
    {
        $s = $this->serverSoftware();
        return $s === '' ? false : (str_contains($s, 'apache') || str_contains($s, 'litespeed'));
    }

    public function rootHtaccessPath(): string
    {
        return $this->root . '/.htaccess';
    }

    public function hasRootRule(): bool
    {
        $f = $this->rootHtaccessPath();
        return is_file($f) && str_contains((string) @file_get_contents($f), self::RULE_SIGNATURE);
    }

    /**
     * Folders that exist but do not yet carry a backup deny-all .htaccess.
     *
     * @return string[]
     */
    public function unprotectedDirs(): array
    {
        $missing = [];
        foreach (self::SENSITIVE as $folder) {
            $dir = $this->root . '/user/' . $folder;
            if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
                $missing[] = $folder;
            }
        }
        return $missing;
    }

    /**
     * @return array{protected: bool, apache: bool, server: string, root_rule: bool, can_autofix: bool, unprotected: string[]}
     */
    public function status(): array
    {
        $apache       = $this->isApache();
        $rootRule     = $this->hasRootRule();
        $unprotected  = $this->unprotectedDirs();
        $perDirCovers = $unprotected === [];

        // On Apache the install is protected once either the root rule is in
        // place or every sensitive folder has its own deny file. On any other
        // server, .htaccess is ignored entirely, so we cannot self-protect and
        // must defer to a manual server-config change.
        $protected = $apache && ($rootRule || $perDirCovers);

        // We can only safely auto-fix when Apache is serving the site and the
        // root .htaccess (if present) is writable.
        $rootWritable = !is_file($this->rootHtaccessPath()) || is_writable($this->rootHtaccessPath());
        $canAutofix   = $apache && !$protected && $rootWritable;

        return [
            'protected'   => $protected,
            'apache'      => $apache,
            'server'      => $this->serverSoftware(),
            'root_rule'   => $rootRule,
            'can_autofix' => $canAutofix,
            'unprotected' => $unprotected,
        ];
    }

    /**
     * Patch the root .htaccess and drop per-folder deny files.
     *
     * @return array{patched: bool, created: string[], errors: string[]}
     */
    public function applyFix(): array
    {
        $created = [];
        $errors  = [];

        foreach (self::SENSITIVE as $folder) {
            $dir = $this->root . '/user/' . $folder;
            if (!is_dir($dir)) {
                continue;
            }
            $file = $dir . '/.htaccess';
            if (is_file($file)) {
                continue;
            }
            if (!is_writable($dir)) {
                $errors[] = "user/$folder is not writable";
                continue;
            }
            if (@file_put_contents($file, $this->denyHtaccess()) !== false) {
                $created[] = "user/$folder/.htaccess";
            } else {
                $errors[] = "could not write user/$folder/.htaccess";
            }
        }

        $patched = false;
        $root    = $this->rootHtaccessPath();
        if (is_file($root)) {
            if (!is_writable($root)) {
                $errors[] = '.htaccess is not writable';
            } elseif (!$this->hasRootRule()) {
                $contents = (string) @file_get_contents($root);
                $rule = "# Block all direct access to these sensitive user folders, whatever the file type\n"
                    . "RewriteRule ^(user)/(accounts|config|data|env)/(.*) error [F]\n";
                $count = 0;
                $new = preg_replace(
                    '/^(RewriteRule \^\(\\\\\.git\|cache\|bin\|logs\|backup\|webserver-configs\|tests\)\/\(\.\*\) error \[F\]\n)/m',
                    '$1' . $rule,
                    $contents,
                    1,
                    $count
                );
                if ($count > 0 && is_string($new) && $new !== $contents) {
                    if (@file_put_contents($root, $new) !== false) {
                        $patched = true;
                    } else {
                        $errors[] = 'could not write patched .htaccess';
                    }
                } else {
                    $errors[] = 'could not locate the Grav security block in .htaccess to patch';
                }
            }
        }

        return ['patched' => $patched, 'created' => $created, 'errors' => $errors];
    }

    /**
     * The rules an operator must add by hand when the site is not on Apache.
     */
    public function manualSnippet(): string
    {
        $s = $this->serverSoftware();
        if (str_contains($s, 'nginx')) {
            return "location ~* /user/(accounts|config|data|env)/.*$ { return 403; }";
        }
        if (str_contains($s, 'iis') || str_contains($s, 'microsoft')) {
            return '<rule name="user_sensitive_folders" stopProcessing="true">' . "\n"
                . '    <match url="^user/(accounts|config|data|env)/(.*)" ignoreCase="false" />' . "\n"
                . '    <action type="Redirect" url="error" redirectType="Permanent" />' . "\n"
                . '</rule>';
        }
        if (str_contains($s, 'caddy')) {
            return "rewrite /user/(accounts|config|data|env)/.* /403";
        }
        if (str_contains($s, 'lighttpd')) {
            return '$HTTP["url"] =~ "^/user/(accounts|config|data|env)/(.*)" { url.access-deny = ("") }';
        }
        return "RewriteRule ^(user)/(accounts|config|data|env)/(.*) error [F]";
    }
}
