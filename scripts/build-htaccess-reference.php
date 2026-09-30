<?php
/**
 * Regenerate the .htaccess reference data the wizard and HtaccessSecurity carry.
 *
 *   php scripts/build-htaccess-reference.php /path/to/grav [ref]
 *
 * Reads a Grav core checkout's git history (every branch) and rewrites the
 * generated blocks in wizard/migrate-wizard.php and classes/HtaccessSecurity.php:
 *
 * - every line Grav has shipped in its root .htaccess, or written into one from
 *   an upgrade script, so the custom-rule detector never mistakes Grav's own
 *   rules from any 1.x or 2.x release for the operator's (issue #22);
 * - the sha256 of every copy Grav has shipped or written of the .htaccess files
 *   under user/, so a copy the site never edited can be replaced;
 * - the current user/ .htaccess files at `ref` (default origin/develop), which
 *   the staged 2.0 install needs because the grav-update zip ships no user/;
 * - the env-aware user/env/.htaccess core's upgrade writes over a Grav-written
 *   deny-all one, taken from tests/fake/htaccess-user-env.txt at `ref` (the
 *   fixture core's test compares that upgrade against, byte for byte) rather than
 *   read out of the update script, whose heredoc is indented and would need
 *   unpicking. Its hash also joins the known copies.
 *
 * Rerun it whenever Grav core changes any of these files.
 */

if ($argc < 2 || !is_dir($argv[1] . '/.git')) {
    fwrite(STDERR, "Usage: php scripts/build-htaccess-reference.php /path/to/grav [ref]\n");
    exit(1);
}
$grav = realpath($argv[1]);
$ref = $argv[2] ?? 'origin/develop';
$plugin = dirname(__DIR__);

function git(string $grav, string $args): string
{
    return (string) shell_exec('git -C ' . escapeshellarg($grav) . ' ' . $args . ' 2>/dev/null');
}

/** Every committed version of every file matching the pathspecs, on any branch. */
function versions(string $grav, array $pathspecs): array
{
    $specs = implode(' ', array_map('escapeshellarg', $pathspecs));
    $out = [];
    $log = git($grav, "log --all --format=%H --name-only -- {$specs}");
    $commit = null;
    foreach (preg_split('/\R/', $log) as $line) {
        if (preg_match('/^[0-9a-f]{40}$/', $line)) {
            $commit = $line;
        } elseif ($line !== '' && $commit) {
            $content = git($grav, 'show ' . escapeshellarg("{$commit}:{$line}"));
            if ($content !== '') {
                $out[$line][hash('sha256', $content)] = $content;
            }
        }
    }

    return $out;
}

/** The same normalisation core's upgrade scripts hash with. */
function normalized_hash(string $content): string
{
    return hash('sha256', rtrim(str_replace("\r\n", "\n", $content)) . "\n");
}

/** Lines of text inside a PHP file's string literals and heredocs. */
function string_lines(string $php): array
{
    $lines = [];
    foreach (@token_get_all($php) as $token) {
        if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }
        $text = $token[1];
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $text = $text[0] === '"' ? stripcslashes(substr($text, 1, -1)) : strtr(substr($text, 1, -1), ["\\'" => "'", '\\\\' => '\\']);
        }
        foreach (preg_split('/\R/', $text) as $line) {
            $lines[] = $line;
        }
    }

    return $lines;
}

$directive = '/^(#|<\/?(IfModule|IfVersion|Files|FilesMatch)\b|Rewrite|Redirect|Require|Order|Deny|Allow|Header|Options|DirectoryIndex|Expires|AddType|AddOutputFilter|SetEnv|FileETag|ErrorDocument)/';

// Stock root .htaccess lines: the shipped files plus what upgrade scripts write.
$stock = [];
foreach (versions($grav, ['.htaccess', 'webserver-configs/htaccess.txt']) as $copies) {
    foreach ($copies as $content) {
        foreach (preg_split('/\R/', $content) as $line) {
            $stock[trim($line)] = true;
        }
    }
}
$scripts = versions($grav, ['system/src/Grav/Installer/updates', 'system/src/Grav/Installer/Install.php', 'system/install.php']);
foreach ($scripts as $copies) {
    foreach ($copies as $content) {
        foreach (string_lines($content) as $line) {
            $line = trim($line);
            if (preg_match($directive, $line)) {
                $stock[$line] = true;
            }
        }
    }
}
unset($stock['']);
$stock = array_keys($stock);
sort($stock);

// The env-aware user/env/.htaccess core's upgrade writes over a Grav-written deny-all one. Grav
// never ships user/env, so it is only ever used to replace an existing file, never to create one.
$envCopy = git($grav, 'show ' . escapeshellarg("{$ref}:tests/fake/htaccess-user-env.txt"));
if ($envCopy === '') {
    fwrite(STDERR, "Missing tests/fake/htaccess-user-env.txt at {$ref}\n");
    exit(1);
}

// Known user/ .htaccess copies: every committed version, plus every hash an
// upgrade script lists for copies that only ever existed on disk.
$known = [];
foreach (versions($grav, [':(glob)user/**/.htaccess', 'user/.htaccess']) as $copies) {
    foreach ($copies as $content) {
        $known[normalized_hash($content)] = true;
    }
}
foreach ($scripts as $path => $copies) {
    if (!str_contains($path, '/updates/')) {
        continue;
    }
    foreach ($copies as $content) {
        preg_match_all('/[\'"]([0-9a-f]{64})[\'"]/', $content, $m);
        foreach ($m[1] as $hash) {
            $known[$hash] = true;
        }
    }
}
// A later run must see the env-aware copy as Grav-written too.
$known[normalized_hash($envCopy)] = true;
$known = array_keys($known);
sort($known);

// The current copies. user/env is not among them: Grav ships none, so it has its own constant.
$current = [];
foreach (['user/.htaccess', 'user/accounts/.htaccess', 'user/config/.htaccess', 'user/data/.htaccess'] as $path) {
    $content = git($grav, 'show ' . escapeshellarg("{$ref}:{$path}"));
    if ($content === '') {
        fwrite(STDERR, "Missing {$path} at {$ref}\n");
        exit(1);
    }
    $current[$path] = $content;
}

$source = trim(git($grav, 'rev-parse --short ' . escapeshellarg($ref)));
$header = "// BEGIN generated by scripts/build-htaccess-reference.php from grav {$ref} ({$source}). Do not edit by hand.\n";
$footer = "// END generated\n";

function replace_block(string $file, string $block): void
{
    $contents = file_get_contents($file);
    $pattern = '~^[ \t]*// BEGIN generated by scripts/build-htaccess-reference\.php.*?// END generated\n~ms';
    if (!preg_match($pattern, $contents)) {
        fwrite(STDERR, "No generated block in {$file}\n");
        exit(1);
    }
    $contents = preg_replace_callback($pattern, static fn() => $block, $contents, 1);
    file_put_contents($file, $contents);
}

$wizard = $header
    . "// Every line Grav has shipped in, or written into, a site root .htaccess.\n"
    . 'const MG_HTACCESS_STOCK_LINES = ' . var_export($stock, true) . ";\n"
    . "// sha256 (CRLF folded, trimmed, one final newline) of every copy of a user/ .htaccess Grav has shipped or written.\n"
    . 'const MG_USER_HTACCESS_KNOWN = ' . var_export($known, true) . ";\n"
    . "// The user/ .htaccess files a Grav 2 install should have.\n"
    . 'const MG_USER_HTACCESS_CURRENT = ' . var_export($current, true) . ";\n"
    . "// What replaces a Grav-written deny-all user/env/.htaccess (never created where none exists, getgrav/grav#4335).\n"
    . 'const MG_USER_ENV_HTACCESS = ' . var_export($envCopy, true) . ";\n"
    . $footer;
replace_block($plugin . '/wizard/migrate-wizard.php', $wizard);

$security = '    ' . $header
    . "    /** The Grav 2 .htaccess file for each sensitive user/ folder. */\n"
    // Not re-indented: the strings span lines, and indenting would change the files.
    . '    private const CURRENT = ' . var_export([
        'accounts' => $current['user/accounts/.htaccess'],
        'config' => $current['user/config/.htaccess'],
        'data' => $current['user/data/.htaccess'],
    ], true) . ";\n"
    . '    ' . $footer;
replace_block($plugin . '/classes/HtaccessSecurity.php', $security);

printf("%d stock lines, %d known user/ copies, from %s (%s)\n", count($stock), count($known), $ref, $source);
