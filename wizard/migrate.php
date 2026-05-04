<?php
/**
 * Grav 2.0 Migration Wizard — standalone.
 *
 * Lives in the migrate-grav plugin at wizard/migrate.php and is COPIED to
 * webroot as /migrate.php by Kickoff. User visits /migrate.php and the wizard
 * runs in a fresh PHP process with no Grav 1.x code loaded.
 *
 * Owning the wizard here (rather than shipping it inside the Grav 2.0 zip)
 * means we can iterate on the migration flow without re-releasing Grav.
 *
 * State machine (persisted in .migrating["step"]):
 *   staged     — kickoff complete, zip + wizard at webroot
 *   extracted  — staged zip expanded into stage_dir, grav-admin/ prefix stripped
 *   imported   — source user/* content merged into stage_dir/user (NOT YET)
 *   promoted   — stage_dir swapped in as live install (NOT YET)
 */

declare(strict_types=1);

const MG_STEPS = ['staged', 'extracted', 'plugins_done', 'accounts_done', 'content_done', 'test_done', 'promoted'];

// Import-step allow lists — declared up top so they resolve at function-call
// time regardless of where in this file render_wizard() reaches them. (PHP
// top-level `const` statements execute in order, unlike function definitions.)
const MG_IMPORT_DEFAULT  = ['pages', 'accounts', 'data', 'config', 'env', 'languages'];
const MG_IMPORT_OPTIONAL = ['plugins', 'themes'];
const MG_COMPAT_URL      = 'https://getgrav.org/gpm/compatibility/v1/_all';
const MG_COMPAT_TARGET   = '2.0';
const MG_COMPAT_TTL      = 900;
// GPM catalog endpoints. The full URL is built per-fetch by mg_fetch_gpm_index()
// so we can pass v= (target Grav version) and php= (current PHP version) — the
// getgrav.org backend uses those to filter each plugin's catalog entry to the
// newest version whose blueprint compat actually fits. Without v=/php= the
// endpoint falls back to a "no-constraint" default that returns badly stale
// versions for plugins that have multiple major lines (e.g. page-toc 1.1.2
// instead of the 2.0-compatible 4.0.0-beta.3 on testing).
//
// Channel is testing — during 2.0 migration we WANT betas. Testing is a
// superset of stable, so this catches both.
const MG_GPM_PLUGINS_BASE = 'https://getgrav.org/downloads/plugins.json';
const MG_GPM_THEMES_BASE  = 'https://getgrav.org/downloads/themes.json';
const MG_HTACCESS_MARKER = '# migrate-grav stage exclusion';

// Compat handling modes for Step 2. Affects how the wizard treats plugins
// that aren't on the curated 2.0 list and don't declare 2.0 in their
// blueprint. See mg_effective_status() for the per-mode rules.
const MG_MODE_STRICT     = 'strict';
const MG_MODE_PERMISSIVE = 'permissive';
const MG_MODE_TEST       = 'test';
const MG_MODES_ALL       = [MG_MODE_STRICT, MG_MODE_PERMISSIVE, MG_MODE_TEST];

// Inline rocket SVG (bootstrap-icons rocket-takeoff). Used in the hero so the
// wizard matches the admin migrate-grav page (which uses the same asset).
// Kept inline because the wizard is standalone — no asset pipeline available.
const MG_ROCKET_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="mg-rocket"><path d="M9.752 6.193c.599.6 1.73.437 2.528-.362s.96-1.932.362-2.531c-.599-.6-1.73-.438-2.528.361-.798.8-.96 1.933-.362 2.532"/><path d="M15.811 3.312c-.363 1.534-1.334 3.626-3.64 6.218l-.24 2.408a2.56 2.56 0 0 1-.732 1.526L8.817 15.85a.51.51 0 0 1-.867-.434l.27-1.899c.04-.28-.013-.593-.131-.956a9 9 0 0 0-.249-.657l-.082-.202c-.815-.197-1.578-.662-2.191-1.277-.614-.615-1.079-1.379-1.275-2.195l-.203-.083a10 10 0 0 0-.655-.248c-.363-.119-.675-.172-.955-.132l-1.896.27A.51.51 0 0 1 .15 7.17l2.382-2.386c.41-.41.947-.67 1.524-.734h.006l2.4-.238C9.005 1.55 11.087.582 12.623.208c.89-.217 1.59-.232 2.08-.188.244.023.435.06.57.093q.1.026.16.045c.184.06.279.13.351.295l.029.073a3.5 3.5 0 0 1 .157.721c.055.485.051 1.178-.159 2.065m-4.828 7.475.04-.04-.107 1.081a1.54 1.54 0 0 1-.44.913l-1.298 1.3.054-.38c.072-.506-.034-.993-.172-1.418a9 9 0 0 0-.164-.45c.738-.065 1.462-.38 2.087-1.006M5.205 5c-.625.626-.94 1.351-1.004 2.09a9 9 0 0 0-.45-.164c-.424-.138-.91-.244-1.416-.172l-.38.054 1.3-1.3c.245-.246.566-.401.91-.44l1.08-.107zm9.406-3.961c-.38-.034-.967-.027-1.746.163-1.558.38-3.917 1.496-6.937 4.521-.62.62-.799 1.34-.687 2.051.107.676.483 1.362 1.048 1.928.564.565 1.25.941 1.924 1.049.71.112 1.429-.067 2.048-.688 3.079-3.083 4.192-5.444 4.556-6.987.183-.771.18-1.345.138-1.713a3 3 0 0 0-.045-.283 3 3 0 0 0-.3-.041Z"/><path d="M7.009 12.139a7.6 7.6 0 0 1-1.804-1.352A7.6 7.6 0 0 1 3.794 8.86c-1.102.992-1.965 5.054-1.839 5.18.125.126 3.936-.896 5.054-1.902Z"/></svg>';

$webroot  = __DIR__;
$flagPath = $webroot . '/.migrating';
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action   = (string) ($_POST['action'] ?? '');
$token    = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

// CLI is intentionally not supported for the wizard itself. The multi-step
// flow (extract / plugins+themes / accounts / content / promote) needs the
// browser UI for compat review, policy choices, and progress streams.
// `bin/plugin migrate-grav init` is still the right CLI entry point — it
// stages the files and prints the URL to open.
if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "The Grav 2.0 migration wizard is browser-only.\n");
    if (is_file($flagPath)) {
        $f = json_decode((string) file_get_contents($flagPath), true);
        if (is_array($f) && !empty($f['wizard_url'])) {
            fwrite(STDERR, "Open in your browser: {$f['wizard_url']}\n");
        }
    } else {
        fwrite(STDERR, "Run `bin/plugin migrate-grav init` first to stage the migration.\n");
    }
    exit(2);
}

// Common validation for any POST action
if ($method === 'POST') {
    $flagForAuth = load_flag($flagPath);
    $expected    = $flagForAuth['token'] ?? '';
    if ($expected === '' || $expected !== $token) {
        http_response_code(403);
        render_error_page('Invalid token', 'Action requires a matching migration token.');
        exit(1);
    }

    switch ($action) {
        case 'reset':
            wizard_reset($webroot, (string) ($flagForAuth['stage_dir'] ?? 'grav-2'));
            header('Location: ' . base_path_from_script(), true, 302);
            exit(0);

        case 'restart':
            // Light reset: keep flag (rewound), keep zip + wizard, drop stage
            // dir and restore .htaccess. Send the user back to the wizard
            // landing so they can re-run from step 1.
            wizard_restart($webroot, $flagPath, $flagForAuth);
            redirect_self($token, ['flash' => 'restarted', 'msg' => 'Wizard restarted. The staged Grav 2.0 directory was cleared — re-run from step 1.']);

        case 'extract':
            // Long-running. Stream progress instead of blocking then redirecting.
            stream_extract_page($webroot, $flagForAuth, $token);
            exit(0);

        case 'plugins_themes':
            $reqMode = (string) ($_POST['mode'] ?? MG_MODE_STRICT);
            $mode    = in_array($reqMode, MG_MODES_ALL, true) ? $reqMode : MG_MODE_STRICT;
            // Form's "upgrade_plugins" checkbox is positive-framed (checked
            // means run gpm update). Internally we keep the legacy
            // skip_update flag so the rest of the pipeline stays untouched.
            $options = [
                'mode'        => $mode,
                'policy'      => (($_POST['policy'] ?? '') === 'skip') ? 'skip' : 'disable',
                'skip_update' => !isset($_POST['upgrade_plugins']),
            ];
            stream_plugins_themes_page($webroot, $flagForAuth, $token, $options);
            exit(0);

        case 'accounts':
            $options = ['migrate_perms' => !isset($_POST['skip_perms'])];
            stream_accounts_page($webroot, $flagForAuth, $token, $options);
            exit(0);

        case 'content':
            stream_content_page($webroot, $flagForAuth, $token);
            exit(0);

        case 'test_continue':
            // No long work — just advance state. Reload the step view.
            $flagForAuth['step'] = 'test_done';
            save_flag($flagPath, $flagForAuth);
            redirect_self($token, ['flash' => 'test_done', 'msg' => 'Ready to promote.']);

        case 'promote':
            stream_promote_page($webroot, $flagForAuth, $token);
            exit(0);

        case 'rerun_step':
            $target = (string) ($_POST['target'] ?? '');
            $rewindLabels = [
                'extracted'     => ['Step 2: Copy & Migrate', 'plugins_done'],
                'plugins_done'  => ['Step 3: Accounts',       'accounts_done'],
                'accounts_done' => ['Step 4: Content',        'content_done'],
            ];
            if (!isset($rewindLabels[$target])) {
                http_response_code(400);
                render_error_page('Invalid re-run target', 'Unknown rewind target: ' . htmlspecialchars($target));
                exit(1);
            }
            // Must have actually completed the step we're rewinding past.
            $required = $rewindLabels[$target][1];
            $currentStep = (string) ($flagForAuth['step'] ?? '');
            $stepOrder = MG_STEPS;
            $currentIdx = array_search($currentStep, $stepOrder, true);
            $requiredIdx = array_search($required, $stepOrder, true);
            if ($currentIdx === false || $requiredIdx === false || $currentIdx < $requiredIdx) {
                http_response_code(409);
                render_error_page('Cannot re-run step',
                    "You must have completed {$rewindLabels[$target][0]} before re-running it. Current step: <code>" . htmlspecialchars($currentStep) . '</code>');
                exit(1);
            }
            mg_rewind_to($webroot, $flagForAuth, $target);
            $msg = 'Rewound to "' . $target . '". Adjust options and re-run.';
            redirect_self($token, ['flash' => 'rewound', 'msg' => $msg]);

        default:
            http_response_code(400);
            render_error_page('Unknown action', 'The action "' . htmlspecialchars($action) . '" is not recognised.');
            exit(1);
    }
}

// ── GET: render the wizard ──────────────────────────────────────────────────

$flag = load_flag($flagPath);
if ($flag === null) {
    http_response_code(409);
    render_error_page('No migration staged', "No <code>.migrating</code> flag present at <code>{$webroot}</code>. Run the kickoff from the Grav admin or CLI first.");
    exit(1);
}

$expected = (string) ($flag['token'] ?? '');
if ($expected === '' || $token !== $expected) {
    http_response_code(403);
    render_error_page('Invalid token', 'This wizard was staged for a specific token. Start it via the link shown after kickoff, or reset to start over.');
    exit(1);
}

$step      = (string) ($flag['step'] ?? 'staged');
$stageDir  = (string) ($flag['stage_dir'] ?? 'grav-2');
$stagedZip = (string) ($flag['staged_zip'] ?? 'tmp/grav-2.0-staged.zip');

$flash = null;
if (isset($_GET['flash'])) {
    $flash = ['type' => (string) $_GET['flash'], 'msg' => (string) ($_GET['msg'] ?? '')];
}

render_wizard($flag, $step, $webroot, $stageDir, $stagedZip, $flagPath, $expected, $flash);

// ─────────────────────────────────────────────────────────────────────────────
// State helpers
// ─────────────────────────────────────────────────────────────────────────────

function load_flag(string $path): ?array
{
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function save_flag(string $path, array $flag): bool
{
    $json = json_encode($flag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($path, $json) !== false;
}

function base_path_from_script(): string
{
    $base = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    return $base === '/' || $base === '\\' ? '/' : rtrim($base, '/') . '/';
}

function redirect_self(string $token, array $extra): void
{
    $qs = http_build_query(['token' => $token] + $extra);
    $self = basename($_SERVER['SCRIPT_NAME'] ?? 'migrate.php');
    header('Location: ' . base_path_from_script() . $self . '?' . $qs, true, 302);
    exit(0);
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 1: Extract — unzip staged release into stage_dir, strip wrapper prefix
// ─────────────────────────────────────────────────────────────────────────────

function do_extract(string $webroot, array $flag, ?callable $progress = null): array
{
    $stageDir     = trim((string) ($flag['stage_dir'] ?? 'grav-2'), '/');
    $stagedZipRel = (string) ($flag['staged_zip'] ?? 'tmp/grav-2.0-staged.zip');
    $zipPath      = $webroot . '/' . ltrim($stagedZipRel, '/');
    $destPath     = $webroot . '/' . $stageDir;

    if ($stageDir === '' || str_contains($stageDir, '..')) {
        return ['ok' => false, 'msg' => "Invalid stage_dir: {$stageDir}"];
    }
    if (!is_file($zipPath)) {
        return ['ok' => false, 'msg' => "Staged zip missing: {$zipPath}"];
    }

    $zip = new ZipArchive();
    if (($open = $zip->open($zipPath)) !== true) {
        return ['ok' => false, 'msg' => "Could not open zip (code {$open}): {$zipPath}"];
    }

    $prefix = detect_common_prefix($zip);
    $total  = $zip->numFiles;

    if (!is_dir($destPath) && !@mkdir($destPath, 0755, true) && !is_dir($destPath)) {
        $zip->close();
        return ['ok' => false, 'msg' => "Could not create stage dir: {$destPath}"];
    }

    $count = 0;
    for ($i = 0; $i < $total; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;

        $relative = $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
        if ($relative === '' || str_contains($relative, '..')) continue;

        $target = $destPath . '/' . $relative;

        if (substr($name, -1) === '/') {
            if (!is_dir($target)) @mkdir($target, 0755, true);
            continue;
        }

        $parent = dirname($target);
        if (!is_dir($parent)) @mkdir($parent, 0755, true);

        $stream = $zip->getStream($name);
        if ($stream === false) {
            $zip->close();
            return ['ok' => false, 'msg' => "Could not read zip entry: {$name}"];
        }
        $out = @fopen($target, 'wb');
        if (!$out) {
            fclose($stream);
            $zip->close();
            return ['ok' => false, 'msg' => "Could not write: {$target}"];
        }
        while (!feof($stream)) {
            $chunk = fread($stream, 1 << 16);
            if ($chunk === false) break;
            fwrite($out, $chunk);
        }
        fclose($stream);
        fclose($out);
        mg_apply_zip_mode($zip, $i, $target, $relative);
        $count++;

        // Throttled progress callback — every 50 files or final tick.
        if ($progress && ($count % 50 === 0 || $count === $total)) {
            $progress($count, $total, $relative);
        }
    }
    $zip->close();

    if ($progress) $progress($count, $total, null);

    // Ensure runtime dirs Grav expects exist at the staged root. Source zips
    // ship these with .gitkeep, but our test-built zip and some hand-rolled
    // builds skip empty dirs — Grav's Problems plugin then flags them red.
    foreach (['tmp', 'backup', 'logs', 'cache', 'images', 'assets'] as $runtimeDir) {
        $p = $destPath . '/' . $runtimeDir;
        if (!is_dir($p)) @mkdir($p, 0755, true);
    }

    // Normalize the staged layout so later steps don't depend on which package
    // variant was used. grav-update ships only system/vendor/bin — no user/,
    // no root .htaccess. grav / grav-admin ship both. Create the user/
    // skeleton and materialize .htaccess from webserver-configs/ if missing
    // so plugins-themes/content/test steps work regardless of source package.
    foreach (['user', 'user/plugins', 'user/themes', 'user/accounts', 'user/config', 'user/data', 'user/pages'] as $userDir) {
        $p = $destPath . '/' . $userDir;
        if (!is_dir($p)) @mkdir($p, 0755, true);
    }
    $htRoot = $destPath . '/.htaccess';
    $htTmpl = $destPath . '/webserver-configs/htaccess.txt';
    if (!is_file($htRoot) && is_file($htTmpl)) {
        @copy($htTmpl, $htRoot);
    }

    // Stash the staged Grav version so the UI can display "what version of
    // Grav 2.0 are we installing?" without re-reading defines.php on every
    // request. Reads `define('GRAV_VERSION', '...')` from the staged tree.
    $stagedGravVersion = mg_read_defines_version($destPath . '/system/defines.php');

    $flag['step']       = 'extracted';
    $flag['extracted']  = [
        'at'              => time(),
        'files'           => $count,
        'prefix_stripped' => $prefix,
        'grav_version'    => $stagedGravVersion,
    ];
    save_flag($webroot . '/.migrating', $flag);

    $verStr = $stagedGravVersion ? " (Grav v{$stagedGravVersion})" : '';
    return ['ok' => true, 'msg' => "Extracted {$count} files into /{$stageDir}/{$verStr}"];
}

/**
 * Render a streaming "extracting…" page that shows live progress as the zip
 * expands, then redirects to the wizard view once complete. Disables output
 * buffering so each flush lands on the browser immediately.
 */
function stream_extract_page(string $webroot, array $flag, string $token): void
{
    // Buffering off. Web-server-level gzip buffering may still defeat this;
    // the padding bytes at the end of each flush help kick most setups loose.
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) @ob_end_flush();
    @ob_implicit_flush(true);

    header('Content-Type: text/html; charset=utf-8');
    header('X-Accel-Buffering: no'); // nginx
    header('Cache-Control: no-cache, no-store, must-revalidate');

    page_header('Extracting Grav 2.0…');
    echo '<div class="mg-page">';
    echo '<div class="mg-hero"><div class="mg-hero-inner">';
    echo '<div class="mg-hero-icon"><span class="mg-spin-ring" aria-hidden="true"></span></div>';
    echo '<div class="mg-hero-text"><h2>Extracting Grav 2.0…</h2>';
    echo '<p>Unzipping the staged release into <code>/' . htmlspecialchars((string)($flag['stage_dir'] ?? 'grav-2')) . '/</code>. Keep this tab open until the migration advances to the next step.</p>';
    echo '</div></div></div>';

    echo '<div class="mg-card mg-card-active"><h3>Progress</h3>';
    echo '<div class="mg-progress-wrap"><div id="mg-bar" class="mg-progress-bar"></div></div>';
    echo '<p class="mg-progress-text"><span id="mg-count">0</span> of <span id="mg-total">?</span> files &middot; <code id="mg-file">starting…</code></p>';
    echo '</div>';

    echo '<script>var mgBar=document.getElementById("mg-bar"),mgCount=document.getElementById("mg-count"),mgTotal=document.getElementById("mg-total"),mgFile=document.getElementById("mg-file");function mgUpdate(c,t,f){mgCount.textContent=c;mgTotal.textContent=t;mgFile.textContent=f||"";mgBar.style.width=(t?Math.round(c*100/t):0)+"%";}</script>';
    flush();

    $progress = static function (int $count, int $total, ?string $file) {
        $f = $file ? substr($file, 0, 80) : '';
        echo '<script>mgUpdate(' . $count . ',' . $total . ',' . json_encode($f) . ');</script>';
        echo str_repeat(' ', 1024) . "\n"; // padding to bust buffers
        flush();
    };

    $result = do_extract($webroot, $flag, $progress);

    if ($result['ok']) {
        $self = basename($_SERVER['SCRIPT_NAME'] ?? 'migrate.php');
        $qs   = http_build_query(['token' => $token, 'flash' => 'extracted', 'msg' => $result['msg']]);
        $url  = base_path_from_script() . $self . '?' . $qs;

        echo '<div class="mg-callout mg-callout-ok"><i class="mg-i-info"></i><div><strong>Done.</strong> ' . htmlspecialchars($result['msg']) . ' &mdash; redirecting…</div></div>';
        echo '<script>window.location.replace(' . json_encode($url) . ');</script>';
        echo '<noscript><p><a href="' . htmlspecialchars($url) . '">Continue</a></p></noscript>';
    } else {
        echo '<div class="mg-callout mg-callout-error"><i class="mg-i-warn"></i><div><strong>Extract failed.</strong> ' . htmlspecialchars($result['msg']) . '</div></div>';
    }

    echo '</div>';
    page_footer();
}

/**
 * Detect a shared top-level directory prefix (e.g. "grav-admin/") and return it.
 * Returns '' when entries don't share a single root folder.
 */
function detect_common_prefix(ZipArchive $zip): string
{
    $prefix = null;
    for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || $name === '') continue;

        $slash = strpos($name, '/');
        $first = $slash === false ? $name : substr($name, 0, $slash + 1);

        if ($prefix === null) {
            $prefix = $first;
        } elseif ($prefix !== $first) {
            return '';
        }
    }
    return $prefix ?? '';
}

/**
 * Apply the correct unix file mode to a just-extracted zip entry.
 *
 * PHP's raw `fwrite()` extract path used here does not carry over the mode
 * stored in the zip's central directory (external attributes), so files
 * land at whatever umask dictates — usually 0644. For the Grav distribution
 * that silently strips the +x bit from bin/grav, bin/gpm, bin/plugin, and
 * bin/composer.phar, leaving operators with broken CLI tools post-migration.
 *
 * Strategy:
 *   1. Prefer the mode stored in the zip (OPSYS_UNIX). Real release builds
 *      ship with 0755 on bin/* so this is usually sufficient.
 *   2. Fallback: test-built zips and some hand-rolled builds don't pack
 *      unix modes. For those, force 0755 on anything sitting directly
 *      under bin/ since that dir contains only executables in the Grav
 *      distribution (grav, gpm, plugin, composer.phar, ...).
 */
function mg_apply_zip_mode(ZipArchive $zip, int $index, string $target, string $relative): void
{
    $applied = false;
    $opsys = null;
    $attr  = null;
    if (@$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
        if ($opsys === ZipArchive::OPSYS_UNIX && $attr !== null) {
            $mode = ($attr >> 16) & 0xFFFF;
            // Only trust zips that actually stored a permission bitset.
            if (($mode & 0o777) !== 0) {
                @chmod($target, $mode & 0o777);
                $applied = true;
            }
        }
    }

    if (!$applied && (str_starts_with($relative, 'bin/') && substr_count($relative, '/') === 1)) {
        @chmod($target, 0755);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 2: Import — copy source user/* into stage_dir/user/
// ─────────────────────────────────────────────────────────────────────────────

/**
 * MG_IMPORT_DEFAULT — always-copied top-level user/ entries (site content).
 * Plugins and themes are also always copied, but each one is classified by
 * 2.0 compatibility first and handled per the user-selected policy.
 */

// ─── Step 2: Plugins & Themes ──────────────────────────────────────────────

function do_plugins_themes(string $webroot, array $flag, array $options, ?callable $progress = null): array
{
    $stageDir = trim((string)($flag['stage_dir'] ?? 'grav-2'), '/');
    $srcUser  = $webroot . '/user';
    $dstUser  = $webroot . '/' . $stageDir . '/user';

    if (!is_dir($srcUser)) {
        return ['ok' => false, 'msg' => "Source user/ missing at {$srcUser}"];
    }
    ensure_dir($dstUser);

    $mode       = (string) ($options['mode'] ?? MG_MODE_STRICT);
    if (!in_array($mode, MG_MODES_ALL, true)) $mode = MG_MODE_STRICT;
    $policy     = ($options['policy'] ?? 'disable') === 'skip' ? 'skip' : 'disable';
    $autoUpdate = empty($options['skip_update']);
    $scan       = mg_compat_scan_cached($webroot, $flag);
    $superseded = mg_collect_superseded($scan); // ['plugins/admin' => 'admin2', ...]

    $stageRoot = $webroot . '/' . $stageDir;

    // ─── Phase 1: bulk-copy source user/ → staged user/ ──────────────────────
    // Everything (plugins, themes, accounts, pages, data, config, env,
    // languages, any custom folders, any top-level files) comes across
    // verbatim. Dotfiles/dotdirs at user/ root (.git, .DS_Store, editor
    // backups) are skipped as filesystem cruft. Symlinks (top-level and
    // mid-tree) are preserved so dev clones stay live. Downstream phases
    // mutate the staged tree in place.
    $copied = 0;
    $copySkipped = [];
    $copiedEntries = [];
    mg_bulk_copy_user($srcUser, $dstUser, $copied, $progress, $copySkipped, $copiedEntries);

    // ─── Phase 2: collect symlinked plugin/theme slugs ───────────────────────
    // Excluded from the upgrade pass (gpm would unlink the symlink and
    // extract a fresh zip in its place) and from the policy pass (dev
    // clones manage their own version, leave them alone).
    $symlinkedSlugs = ['plugins' => [], 'themes' => []];
    foreach (['plugins', 'themes'] as $kind) {
        $kindDir = $dstUser . '/' . $kind;
        if (!is_dir($kindDir)) continue;
        foreach (scandir($kindDir) ?: [] as $slug) {
            if ($slug === '.' || $slug === '..' || $slug[0] === '.') continue;
            if (is_link($kindDir . '/' . $slug)) $symlinkedSlugs[$kind][] = $slug;
        }
    }

    // ─── Phase 3: neutralize superseded plugins against gpm dep resolution ──
    // Two cooperating tricks so gpm leaves admin (et al.) alone:
    //   (a) Exclude superseded slugs from gpm's positional allowlist so they
    //       aren't directly listed for update.
    //   (b) Pin each superseded plugin's blueprints `version:` to gpm's
    //       reported latest. gpm's getDependencies() removes any installed
    //       dep where `currentlyInstalledVersion === latestRelease` from the
    //       dep list — so transitive declarations like
    //       `dependencies: [{name: admin, version: '>=1.7.4'}]`
    //       (data-manager, login-ldap, ...) won't drag admin back in.
    // Without (b), `bin/gpm update -y` would mark admin as an 'ignore'-type
    // transitive dep, then with -y still install it (the install command
    // treats -y as auto-yes for ignore-type deps too).
    // We don't restore the pinned version — the dir gets deleted in
    // Phase 4.5 anyway.
    $supersededPluginSlugs = [];
    foreach (array_keys($superseded) as $label) {
        if (!str_starts_with($label, 'plugins/')) continue;
        $supersededPluginSlugs[] = substr($label, strlen('plugins/'));
    }
    $gpmPluginsIndex = $scan['gpm']['plugins'] ?? [];
    foreach ($supersededPluginSlugs as $slug) {
        $latest = (string) ($gpmPluginsIndex[$slug]['version'] ?? '');
        if ($latest === '') continue;
        $bpPath = $stageRoot . '/user/plugins/' . $slug . '/blueprints.yaml';
        if (!is_file($bpPath)) continue;
        $bpContent = @file_get_contents($bpPath);
        if (!is_string($bpContent) || $bpContent === '') continue;
        $patched = preg_replace(
            '/^version:\s*[\'"]?[0-9A-Za-z.\-]+[\'"]?\s*$/m',
            "version: {$latest}",
            $bpContent,
            1
        );
        if (is_string($patched) && $patched !== $bpContent) {
            @file_put_contents($bpPath, $patched);
        }
    }

    // ─── Phase 4: bring installed plugins/themes up to their 2.0 versions ──
    // Shell out to the staged Grav 2.0's `bin/gpm update -p -y` for plugins
    // (gpm itself enforces 2.0-compat — only compatible upgrades land).
    // Symlinked slugs AND superseded slugs excluded via positional allowlist.
    // Themes still use the per-slug zip path. Running BEFORE policy so the
    // post-upgrade re-scan reflects what gpm actually did, and policy only
    // kicks in for plugins gpm couldn't rescue.
    $upgraded = [];
    $gpmResult = null;
    if ($autoUpdate) {
        $gpmExclude = array_values(array_unique(array_merge($symlinkedSlugs['plugins'], $supersededPluginSlugs)));
        $gpmResult = mg_gpm_update($stageRoot, 'plugins', $gpmExclude, $progress);
        if ($gpmResult['ok']) {
            // gpm reports display names, not slugs. Resolve back to slug via
            // the scan's blueprint metadata so upgraded[] keys match the
            // slug-keyed convention used by the themes path below.
            $nameToSlug = [];
            foreach (($scan['plugins'] ?? []) as $s => $v) {
                $n = (string) ($v['name'] ?? '');
                if ($n !== '') $nameToSlug[$n] = $s;
            }
            $supersededSet = array_flip($supersededPluginSlugs);
            foreach ($gpmResult['updated'] as $name => $version) {
                $slug = $nameToSlug[$name] ?? '';
                // Hide superseded slugs from the upgraded report — even if the
                // pinning trick above didn't fully suppress gpm's dep handling,
                // the dir gets removed in Phase 4.5 and is replaced by admin2/api.
                if ($slug !== '' && isset($supersededSet[$slug])) continue;
                if ($slug === '') {
                    $upgraded["plugins/{$name}"] = ['to' => $version, 'from' => ''];
                    continue;
                }
                $from = (string) ($scan['plugins'][$slug]['installed_version'] ?? '');
                $upgraded["plugins/{$slug}"] = ['to' => $version, 'from' => $from];
            }
        }

        // Themes — existing per-slug path, skipping symlinked slugs.
        $themeSymSet = array_flip($symlinkedSlugs['themes']);
        foreach (($scan['themes'] ?? []) as $slug => $verdict) {
            if (isset($themeSymSet[$slug])) continue;
            $update = $verdict['update'] ?? null;
            if (!$update || empty($update['download']) || empty($update['to'])) continue;

            $dst = $dstUser . '/themes/' . $slug;
            if (!is_dir($dst) || is_link($dst)) continue;

            if ($progress) $progress(['phase' => 'start', 'entry' => "update/themes/{$slug}"]);

            $res = mg_install_zip_url($webroot, $stageDir, 'themes', $slug, $update['download']);
            if ($res['ok']) {
                $upgraded["themes/{$slug}"] = ['to' => $update['to'], 'from' => $verdict['installed_version'] ?? ''];
                if ($progress) $progress(['phase' => 'done-entry', 'entry' => "update/themes/{$slug}"]);
            } else {
                if ($progress) $progress(['phase' => 'skip', 'entry' => "update/themes/{$slug}", 'reason' => $res['msg']]);
            }
        }
    }

    // ─── Phase 4.5: delete superseded plugin dirs ───────────────────────────
    // Now that gpm has had its dep-resolution pass with these dirs in place,
    // we can remove them. Their replacements (admin2, api, …) are installed
    // in Phase 7 by mg_install_replacements.
    $supersedeResult = mg_handle_supersedes($stageRoot, $superseded, $progress);

    // ─── Phase 5: re-scan compat on the staged tree (post-upgrade) ──────────
    // Verdicts now reflect whatever gpm actually installed — a plugin that
    // was 1.7-only on the source but got upgraded to a 2.0-compat release
    // reads `compatible` here, so policy won't touch it.
    $postScan = mg_rescan_staged($dstUser, $scan);

    // ─── Phase 6: apply policy to plugins still incompatible after upgrade ─
    // In strict mode, this is the small residual set gpm couldn't rescue.
    // In test mode, nothing gets disabled/skipped at all (effective=compat).
    $policyResult = mg_apply_plugin_policy($stageRoot, $postScan, $policy, $mode, $progress);
    $disabled = array_map(static fn($s) => "plugins/{$s}", $policyResult['disabled']);
    $skipped  = array_merge(
        // mg_handle_supersedes already produces kind/slug-prefixed entries
        $supersedeResult['skipped'],
        array_map(static fn($s) => "plugins/{$s}", $policyResult['skipped'])
    );

    // ─── Phase 7: install replacements (admin2, api, etc.) ──────────────────
    $replacements = mg_install_replacements($webroot, $stageDir, $scan, $disabled, $skipped, $progress);

    // Derive the "actually copied" slug list per kind from the scan, minus
    // skipped. (Disabled is a subset of copied — they got the files plus the
    // enabled:false flag.)
    $copiedByKind = ['plugins' => [], 'themes' => []];
    foreach (['plugins', 'themes'] as $kind) {
        $skippedSet = [];
        foreach ($skipped as $s) {
            if (!str_starts_with($s, $kind . '/')) continue;
            $bareSlug = explode(' ', substr($s, strlen($kind) + 1), 2)[0];
            $skippedSet[$bareSlug] = true;
        }
        foreach (array_keys($scan[$kind] ?? []) as $slug) {
            if (!isset($skippedSet[$slug])) {
                $copiedByKind[$kind][] = $slug;
            }
        }
    }

    $symlinkCount = count($symlinkedSlugs['plugins']) + count($symlinkedSlugs['themes']);

    $flag['step']           = 'plugins_done';
    $flag['plugins_themes'] = [
        'at'             => time(),
        'files'          => $copied,
        'copied'         => $copiedByKind,
        'copied_entries' => $copiedEntries,
        'copy_skipped'   => $copySkipped,
        'skipped'        => $skipped,
        'disabled'       => $disabled,
        'mode'           => $mode,
        'policy'         => $policy,
        'auto_update'    => $autoUpdate,
        'upgraded'       => $upgraded,
        'symlinked'      => $symlinkedSlugs,
        'gpm_result'     => $gpmResult ? [
            'ok'      => $gpmResult['ok'],
            'msg'     => $gpmResult['msg'],
            'updated' => $gpmResult['updated'],
        ] : null,
        'replacements'   => $replacements,
        'force_included' => $policyResult['force_included'] ?? [],
    ];
    save_flag($webroot . '/.migrating', $flag);

    $msg = "Copied {$copied} files across user/ (" . count($copiedEntries) . ' top-level entries)';
    if ($mode !== MG_MODE_STRICT) $msg .= "; mode: {$mode}";
    if (!empty($policyResult['force_included'])) $msg .= '; force-included ' . count($policyResult['force_included']);
    if ($upgraded) $msg .= "; updated " . count($upgraded);
    if ($symlinkCount) $msg .= "; preserved {$symlinkCount} symlink(s)";
    if ($disabled) $msg .= "; disabled " . count($disabled);
    if ($skipped)  $msg .= "; skipped " . count($skipped);
    if (!empty($replacements['installed'])) $msg .= "; installed " . count($replacements['installed']) . " replacement(s)";
    if ($gpmResult && !$gpmResult['ok']) $msg .= "; gpm: " . $gpmResult['msg'];
    return ['ok' => true, 'msg' => $msg . '.'];
}

// ─── Step 3: Accounts ──────────────────────────────────────────────────────

function do_accounts(string $webroot, array $flag, array $options, ?callable $progress = null): array
{
    // Accounts were already copied verbatim into staged user/accounts/ by
    // Step 2's bulk user/ copy. This step just applies the optional
    // admin.* → api.* perm mirror transform on the staged yamls in place.
    $stageDir = trim((string)($flag['stage_dir'] ?? 'grav-2'), '/');
    $dst = $webroot . '/' . $stageDir . '/user/accounts';

    if (!is_dir($dst)) {
        $flag['step'] = 'accounts_done';
        $flag['accounts'] = ['at' => time(), 'count' => 0, 'migrated_perms' => 0, 'skipped_perms' => true];
        save_flag($webroot . '/.migrating', $flag);
        return ['ok' => true, 'msg' => 'No user/accounts/ in staged install — nothing to transform.'];
    }

    $migratePerms = !empty($options['migrate_perms']);
    $count = 0;
    $mirrored = 0;
    $details = [];

    foreach (scandir($dst) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if ($entry[0] === '.') continue;

        $path = $dst . '/' . $entry;
        if (!is_file($path)) continue;
        if (!preg_match('/\.yaml$/i', $entry)) continue;

        if ($progress) $progress(['phase' => 'start', 'entry' => "accounts/{$entry}"]);

        if ($migratePerms) {
            // Rewrite in place: read, mirror admin.* → api.*, overwrite.
            $added = mg_migrate_account_perms($path, $path);
            $mirrored += $added;
            $details[$entry] = $added;
        }
        $count++;

        if ($progress) $progress(['phase' => 'done-entry', 'entry' => "accounts/{$entry}", 'added' => $details[$entry] ?? 0]);
    }

    $flag['step'] = 'accounts_done';
    $flag['accounts'] = [
        'at'             => time(),
        'count'          => $count,
        'migrated_perms' => $mirrored,
        'skipped_perms'  => !$migratePerms,
        'details'        => $details,
    ];
    save_flag($webroot . '/.migrating', $flag);

    $msg = "Processed {$count} account file(s)";
    if ($migratePerms) $msg .= "; mirrored {$mirrored} admin.* → api.* permission(s)";
    else               $msg .= "; permission mirroring skipped";
    return ['ok' => true, 'msg' => $msg . '.'];
}

/**
 * Copy a single account yaml from $src to $dst, and for every `admin.X`
 * permission under `access:` (nested OR dotted-flat), add a matching
 * `api.X` with the same value if one isn't already present. Preserves
 * existing admin.* entries. Returns the number of api.* keys added.
 */
function mg_migrate_account_perms(string $src, string $dst): int
{
    mg_ensure_yaml_available();

    $raw = @file_get_contents($src);
    if ($raw === false) { @copy($src, $dst); return 0; }

    if (class_exists('Symfony\\Component\\Yaml\\Yaml')) {
        try {
            $data = \Symfony\Component\Yaml\Yaml::parse($raw);
        } catch (\Throwable $e) { @copy($src, $dst); return 0; }
    } elseif (function_exists('yaml_parse')) {
        $data = @yaml_parse($raw);
    } else {
        // Can't safely edit; just copy.
        @copy($src, $dst);
        return 0;
    }
    if (!is_array($data)) { @copy($src, $dst); return 0; }

    $added = 0;
    if (isset($data['access']) && is_array($data['access'])) {
        // Nested form:  access: { admin: { super: true, login: true } }
        if (isset($data['access']['admin']) && is_array($data['access']['admin'])) {
            $apiExisting = (array) ($data['access']['api'] ?? []);
            foreach ($data['access']['admin'] as $k => $v) {
                if (!array_key_exists($k, $apiExisting)) {
                    $apiExisting[$k] = $v;
                    $added++;
                }
            }
            $data['access']['api'] = $apiExisting;
        }
        // Dotted-flat form: access: { admin.super: true, admin.login: true }
        foreach ($data['access'] as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'admin.')) {
                $apiKey = 'api.' . substr($k, strlen('admin.'));
                if (!array_key_exists($apiKey, $data['access'])) {
                    $data['access'][$apiKey] = $v;
                    $added++;
                }
            }
        }
    }

    if (class_exists('Symfony\\Component\\Yaml\\Yaml')) {
        $out = \Symfony\Component\Yaml\Yaml::dump($data, 6, 2);
    } elseif (function_exists('yaml_emit')) {
        $out = yaml_emit($data);
    } else {
        @copy($src, $dst);
        return 0;
    }

    @file_put_contents($dst, $out);
    return $added;
}

/**
 * Ensure Symfony\Component\Yaml\Yaml is loadable. After Step 1 extracts the
 * 2.0 release, the staged vendor/ has it. Pulling in autoload is OK — we
 * only reach here inside POST handlers where the extra class load is fine.
 */
function mg_ensure_yaml_available(): void
{
    if (class_exists('Symfony\\Component\\Yaml\\Yaml')) return;

    // Try the staged 2.0 vendor first (always present after extract).
    foreach ([
        __DIR__ . '/grav-2/vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ] as $candidate) {
        if (is_file($candidate)) {
            require_once $candidate;
            if (class_exists('Symfony\\Component\\Yaml\\Yaml')) return;
        }
    }
}

// ─── Step 6: Promote ────────────────────────────────────────────────────────

/**
 * Promote the staged Grav 2.0 install to the webroot:
 *   1. Create {webroot}/backup-pre-2.0-{YYYYMMDD-HHMMSS}/
 *   2. Move every top-level entry at the webroot EXCEPT the stage dir and
 *      the new backup dir into the backup (this includes migrate.php itself,
 *      .migrating, .htaccess, system/, vendor/, user/, tmp/, logs/, etc.)
 *   3. Move every top-level entry from the stage dir up to the webroot.
 *   4. Remove the empty stage dir.
 *
 * All operations are `rename()` calls on the same filesystem (fast + near-atomic).
 * After success the running PHP process is already finished reading migrate.php,
 * so even though the file has moved the response still renders; the user is
 * redirected to the new webroot.
 */
function do_promote(string $webroot, array $flag, ?callable $progress = null): array
{
    $stageDir  = trim((string)($flag['stage_dir'] ?? 'grav-2'), '/');
    $stagePath = $webroot . '/' . $stageDir;

    if ($stageDir === '' || !is_dir($stagePath)) {
        return ['ok' => false, 'msg' => "Stage dir missing or invalid: {$stagePath}"];
    }

    // Version comes from the CURRENT install's defines.php (the one we're
    // about to back up — not the staged one).
    $currentVersion = mg_read_defines_version($webroot . '/system/defines.php')
        ?? ($flag['source']['grav_version'] ?? 'unknown');
    // Match Grav's backup discovery regex (#(.*)--(\d*).zip#) so the resulting
    // file shows up in the admin's Backups list: <name>--<digits>.zip with a
    // double-dash separator and an unbroken numeric timestamp.
    $timestamp  = date('YmdHis');
    $zipName    = 'migration-backup-' . $currentVersion . '--' . $timestamp . '.zip';

    // Write the zip INSIDE the stage dir's backup/ so that after promote
    // (which moves stage contents up) it naturally lands at
    // {newWebroot}/backup/migration-backup-*.zip — no cross-device move.
    $zipDir  = $stagePath . '/backup';
    ensure_dir($zipDir);
    $zipPath = $zipDir . '/' . $zipName;
    if (is_file($zipPath)) @unlink($zipPath);

    // ─── Phase 1: zip the 1.x install ──────────────────────────────────────
    $zip = new ZipArchive();
    $rc = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($rc !== true) {
        return ['ok' => false, 'msg' => "Could not create backup zip (ZipArchive code {$rc}): {$zipPath}"];
    }
    $added = 0;
    $err = mg_zip_webroot($zip, $webroot, $stageDir, $added, $progress);
    $zip->close();
    if ($err !== null) {
        @unlink($zipPath);
        return ['ok' => false, 'msg' => "Backup failed: {$err}"];
    }
    if (!is_file($zipPath) || filesize($zipPath) < 1024) {
        return ['ok' => false, 'msg' => 'Backup zip looks invalid after close'];
    }

    // ─── Phase 2: delete everything at webroot except the stage dir ────────
    $deleted = [];
    foreach (scandir($webroot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if ($entry === $stageDir) continue;

        if ($progress) $progress(['phase' => 'copy', 'entry' => 'clear', 'file' => $entry, 'copied' => $added]);
        $path = $webroot . '/' . $entry;
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) return ['ok' => false, 'msg' => "Could not delete {$entry}"];
        } elseif (is_dir($path)) {
            if (!mg_rm_tree($path)) return ['ok' => false, 'msg' => "Could not delete directory {$entry}"];
        }
        $deleted[] = $entry;
    }

    // ─── Phase 3: promote stage contents up ────────────────────────────────
    $promoted = [];
    foreach (scandir($stagePath) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;

        if ($progress) $progress(['phase' => 'copy', 'entry' => 'promote', 'file' => $entry, 'copied' => count($promoted)]);
        if (!@rename($stagePath . '/' . $entry, $webroot . '/' . $entry)) {
            return ['ok' => false, 'msg' => "Failed to promote {$entry}"];
        }
        $promoted[] = $entry;
    }
    @rmdir($stagePath);

    // Breadcrumb for the new install.
    $summary = [
        'migrated_at'  => date('c'),
        'backup_zip'   => 'backup/' . $zipName,
        'from_version' => $currentVersion,
        'promoted'     => $promoted,
    ];
    @file_put_contents(
        $webroot . '/.migration-complete',
        json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    if ($progress) $progress(['phase' => 'done-entry', 'entry' => 'promote', 'copied' => count($promoted)]);

    return [
        'ok'     => true,
        'msg'    => "Backed up {$added} files to backup/{$zipName}; promoted " . count($promoted) . ' entries to webroot.',
        'backup' => $zipName,
    ];
}

/**
 * Read the GRAV_VERSION string out of a Grav install's system/defines.php.
 * Returns null if the file is missing or the pattern doesn't match.
 */
function mg_read_defines_version(string $path): ?string
{
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    if (preg_match("/define\\(\\s*['\"]GRAV_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]/", $raw, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Peek into the staged Grav zip to read GRAV_VERSION from system/defines.php
 * without extracting. Used by the wizard's pre-extraction view so users can
 * see exactly what version they're about to install. Returns null if the zip
 * is unreadable or doesn't contain a recognizable defines.php.
 */
function mg_read_grav_version_from_zip(string $zipPath): ?string
{
    if (!is_file($zipPath)) return null;
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return null;

    $found = null;
    for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        // Match system/defines.php at any depth (zip may have a wrapper prefix
        // like grav-admin/ or grav-update/).
        if (!preg_match('~(?:^|/)system/defines\.php$~', $name)) continue;
        $raw = $zip->getFromIndex($i);
        if ($raw === false) continue;
        if (preg_match("/define\\(\\s*['\"]GRAV_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]/", $raw, $m)) {
            $found = $m[1];
            break;
        }
    }
    $zip->close();
    return $found;
}

/**
 * Get the staged zip's Grav version, caching it in the flag so we only
 * crack the zip once. Returns null when the zip is missing or unreadable.
 */
function mg_staged_zip_version(string $webroot, array &$flag): ?string
{
    $cached = $flag['staged_zip_version'] ?? null;
    if (is_string($cached) && $cached !== '') return $cached;

    $stagedZipRel = (string) ($flag['staged_zip'] ?? 'tmp/grav-2.0-staged.zip');
    $version = mg_read_grav_version_from_zip($webroot . '/' . ltrim($stagedZipRel, '/'));
    if ($version !== null) {
        $flag['staged_zip_version'] = $version;
        save_flag($webroot . '/.migrating', $flag);
    }
    return $version;
}

/**
 * Add everything at the webroot to the zip EXCEPT the stage dir. Skips
 * symlinks (avoids chasing dev-env links into unrelated repos). Streams
 * progress per ~200 files for the UI.
 */
function mg_zip_webroot(ZipArchive $zip, string $webroot, string $skipTop, int &$added, ?callable $progress): ?string
{
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($webroot, RecursiveDirectoryIterator::SKIP_DOTS),
                static function ($item, $key, $iterator) use ($webroot, $skipTop) {
                    $path = $item->getPathname();
                    // Skip the stage dir at the top level.
                    if (strpos($path, $webroot . DIRECTORY_SEPARATOR . $skipTop . DIRECTORY_SEPARATOR) === 0
                        || $path === $webroot . DIRECTORY_SEPARATOR . $skipTop) {
                        return false;
                    }
                    return true;
                }
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
    } catch (\Throwable $e) {
        return "Could not iterate webroot: " . $e->getMessage();
    }

    // Follow symlinks when archiving — this is a FULL backup, and in dev
    // environments plugins/themes are often symlinked to sibling repos.
    // Skipping them here would leave them out of the restore zip while the
    // delete phase still removes the link from the webroot (= data loss).
    // ZipArchive::addFile() and PHP's is_dir() both follow symlinks by
    // default, so we just archive whatever the link resolves to.
    foreach ($it as $file) {
        $path = $file->getPathname();
        $rel  = ltrim(substr($path, strlen($webroot)), '/\\');
        if ($rel === '') continue;

        if ($file->isDir()) {
            $zip->addEmptyDir($rel);
        } else {
            if (!$zip->addFile($path, $rel)) {
                return "Could not add to zip: {$rel}";
            }
            $added++;
            if ($progress && $added % 200 === 0) {
                $progress(['phase' => 'copy', 'entry' => 'backup', 'file' => $rel, 'copied' => $added]);
            }
        }
    }
    return null;
}

function mg_rm_tree(string $path): bool
{
    // Critical: never traverse INTO symlinks. The wizard's staged tree often
    // contains symlinks to plugin source clones (a developer convenience), and
    // RecursiveDirectoryIterator follows them by default, which would attempt
    // to delete real source files. scandir() returns symlinks as plain entries
    // we can identify via is_link() before deciding to recurse or just unlink.
    if (is_link($path) || is_file($path)) {
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
        if ($item === '.' || $item === '..') continue;
        $sub = $path . DIRECTORY_SEPARATOR . $item;
        if (is_link($sub)) {
            $ok = @unlink($sub) && $ok;
        } elseif (is_dir($sub)) {
            $ok = mg_rm_tree($sub) && $ok;
        } else {
            $ok = @unlink($sub) && $ok;
        }
    }
    return @rmdir($path) && $ok;
}

// ─── Step 5: Test (server-aware sub-path enabler) ──────────────────────────
// MG_HTACCESS_MARKER lives at the top of the file — top-level `const`
// statements run in source order, not hoisted, and these helpers are
// invoked during render before this section would execute.

function mg_server_kind(): string
{
    $s = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
    if (str_contains($s, 'apache'))    return 'apache';
    if (str_contains($s, 'litespeed')) return 'litespeed';
    if (str_contains($s, 'nginx'))     return 'nginx';
    if (str_contains($s, 'caddy'))     return 'caddy';
    return 'other';
}

/**
 * Apache/LiteSpeed only: inject one extra `RewriteCond` into the catch-all
 * `RewriteRule .* index.php [L]` so requests under /<stageDir>/ are NOT
 * routed to the parent install's index.php — letting the staged 2.0 serve
 * itself for testing. Backs up to .htaccess.migrate-grav-backup. Idempotent.
 */
function mg_patch_htaccess(string $webroot, string $stageDir): array
{
    $htpath = $webroot . '/.htaccess';
    if (!is_file($htpath)) {
        return ['ok' => false, 'msg' => "No .htaccess at {$htpath} — manual config needed."];
    }
    $current = (string) file_get_contents($htpath);
    if (str_contains($current, MG_HTACCESS_MARKER)) {
        return ['ok' => true, 'msg' => 'Already patched.'];
    }

    // Apache .htaccess does NOT support inline `# comment` after a directive
    // — must put comments on their own line, otherwise Apache treats the rest
    // (including the #) as part of the directive value and 500s.
    $patchLines = "    " . MG_HTACCESS_MARKER . "\n"
                . "    RewriteCond %{REQUEST_URI} !/" . $stageDir . "/\n";

    // Inject right before any `RewriteRule .* index.php [L]` or [QSA,L] line.
    $patched = preg_replace(
        '/^(\s*RewriteRule\s+\.\*\s+index\.php\s+\[(?:[^\]]*L[^\]]*)\])/m',
        $patchLines . '$1',
        $current,
        1,
        $count
    );
    if ($patched === null || $count === 0) {
        return ['ok' => false, 'msg' => 'Could not find catch-all RewriteRule in .htaccess.'];
    }

    @copy($htpath, $htpath . '.migrate-grav-backup');
    @file_put_contents($htpath, $patched);
    return ['ok' => true, 'msg' => 'Patched.'];
}

/**
 * The staged Grav's .htaccess ships with `# RewriteBase /` commented out. When
 * the staged install lives at a sub-path (e.g. /grav-admin-1749.5/grav-2/),
 * its rewrites need RewriteBase set to that sub-path or every page after the
 * root throws 500/404. Idempotent: skips if a RewriteBase line already exists.
 */
function mg_patch_staged_htaccess(string $webroot, string $stageDir, string $stageUrlPath): array
{
    $htpath = $webroot . '/' . trim($stageDir, '/') . '/.htaccess';
    if (!is_file($htpath)) {
        return ['ok' => false, 'msg' => "Staged .htaccess missing at {$htpath}"];
    }
    $current = (string) file_get_contents($htpath);

    // Already has an active RewriteBase? Leave it alone.
    if (preg_match('/^\s*RewriteBase\s+\S/m', $current)) {
        return ['ok' => true, 'msg' => 'Staged .htaccess already has RewriteBase.'];
    }

    $rewriteBase = '/' . trim($stageUrlPath, '/') . '/';
    // Two lines — comment on its own (Apache doesn't allow inline comments).
    $lines = MG_HTACCESS_MARKER . "\n"
           . "RewriteBase {$rewriteBase}\n";

    // Try replacing the commented "# RewriteBase /" template line first.
    $patched = preg_replace('/^#\s*RewriteBase\s+\/\s*$/m', $lines, $current, 1, $count);
    if ($count === 0) {
        // Fall back to inserting before the first RewriteRule.
        $patched = preg_replace('/^(\s*RewriteRule\b)/m', $lines . '$1', $current, 1, $count);
    }
    if ($count === 0) {
        return ['ok' => false, 'msg' => 'Could not find a place to insert RewriteBase in staged .htaccess'];
    }
    @file_put_contents($htpath, $patched);
    return ['ok' => true, 'msg' => "Set RewriteBase {$rewriteBase} in staged .htaccess."];
}

function mg_unpatch_htaccess(string $webroot): void
{
    $htpath = $webroot . '/.htaccess';
    $backup = $htpath . '.migrate-grav-backup';
    if (is_file($backup)) {
        @copy($backup, $htpath);
        @unlink($backup);
        return;
    }
    // No backup but our marker is present → strip the marker line AND the
    // injected directive on the line that follows it.
    if (is_file($htpath)) {
        $cur = (string) file_get_contents($htpath);
        if (str_contains($cur, MG_HTACCESS_MARKER)) {
            $marker = preg_quote(MG_HTACCESS_MARKER, '/');
            $stripped = preg_replace('/^[ \t]*' . $marker . ".*\n[ \t]*(?:RewriteCond|RewriteBase)[^\n]*\n/m", '', $cur);
            if (is_string($stripped)) @file_put_contents($htpath, $stripped);
        }
    }
}

// ─── Step 4: Content ───────────────────────────────────────────────────────

function do_content(string $webroot, array $flag, ?callable $progress = null): array
{
    // Content (pages, data, config, env, languages, custom folders, any
    // top-level user/* files) was already bulk-copied into the staged
    // install during Step 2. This step just summarises what landed in the
    // staged user/ and marks the flow complete.
    $stageDir = trim((string)($flag['stage_dir'] ?? 'grav-2'), '/');
    $dstUser  = $webroot . '/' . $stageDir . '/user';

    $handled = ['plugins', 'themes', 'accounts'];
    $entries = [];
    if (is_dir($dstUser)) {
        foreach (scandir($dstUser) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if ($entry[0] === '.') continue;
            if (in_array($entry, $handled, true)) continue;
            $entries[] = $entry;
        }
    }

    $flag['step']    = 'content_done';
    $flag['content'] = ['at' => time(), 'entries' => $entries];
    save_flag($webroot . '/.migrating', $flag);

    return ['ok' => true, 'msg' => 'Content already migrated in Step 2 — ' . count($entries) . ' top-level entries under staged user/.'];
}

/**
 * Walk the scan and return ["plugins/admin" => "admin2", ...] for every
 * source plugin/theme whose `replaced_by` target is something we'll actually
 * be able to install (either listed in GPM or has a github_repo in the
 * curated registry). The main copy step uses this to skip the OLD plugin
 * entirely instead of copying-then-disabling it.
 */
function mg_collect_superseded(array $scan): array
{
    $out = [];
    $gpmPlugins = $scan['gpm']['plugins'] ?? [];
    $gpmThemes  = $scan['gpm']['themes']  ?? [];

    foreach (['plugins', 'themes'] as $kind) {
        foreach (($scan[$kind] ?? []) as $slug => $v) {
            $repl = $v['replaced_by'] ?? null;
            if (!$repl) continue;

            $inGpm = isset($gpmPlugins[$repl]) || isset($gpmThemes[$repl]);
            $entry = mg_lookup_registry_entry($repl);
            $hasRepo = is_array($entry) && !empty($entry['github_repo']);

            if ($inGpm || $hasRepo) {
                $out["{$kind}/{$slug}"] = $repl;
            }
        }
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Auto-install replacements from GitHub
// ─────────────────────────────────────────────────────────────────────────────

/**
 * For each disabled/skipped plugin with a curated `replaced_by` that itself
 * has a `github_repo`, download + install the replacement into the staged
 * user/plugins/<slug>/ dir. Returns ['installed' => [slugs], 'failed' => [[slug, reason]]].
 */
function mg_install_replacements(string $webroot, string $stageDir, array $scan, array $disabled, array $skipped, ?callable $progress): array
{
    $installed = [];
    $failed    = [];

    $candidates = [];
    // Step 1: replacements derived from `replaced_by` on disabled/skipped plugins.
    foreach (array_merge($disabled, $skipped) as $label) {
        if (!str_starts_with($label, 'plugins/')) continue;
        $slug = substr($label, strlen('plugins/'));
        $slug = explode(' ', $slug, 2)[0];
        $verdict = $scan['plugins'][$slug] ?? null;
        if (!is_array($verdict)) continue;
        $repl = $verdict['replaced_by'] ?? null;
        if (!$repl) continue;
        $candidates[$repl] = true;
    }

    // Step 2: transitively include any `requires:` from each candidate's
    // registry entry. Admin 2.0 requires the API plugin — same fetch path.
    $queue = array_keys($candidates);
    while ($queue) {
        $slug = array_shift($queue);
        $entry = mg_lookup_registry_entry($slug);
        if (!is_array($entry) || empty($entry['requires'])) continue;
        foreach ((array) $entry['requires'] as $req) {
            if (!isset($candidates[$req])) {
                $candidates[$req] = true;
                $queue[] = $req;
            }
        }
    }

    $gpmPlugins = $scan['gpm']['plugins'] ?? null;

    foreach (array_keys($candidates) as $replSlug) {
        $replEntry = $scan['plugins'][$replSlug] ?? mg_lookup_registry_entry($replSlug);

        if ($progress) $progress(['phase' => 'start', 'entry' => "replacement/{$replSlug}"]);

        $res = null;

        // Prefer GPM when the slug is published there — same path the auto-update
        // flow uses, and the same path real GPM uses (catalog → GitHub zip URL).
        if (is_array($gpmPlugins) && isset($gpmPlugins[$replSlug]['download'])) {
            $gpmEntry = $gpmPlugins[$replSlug];
            $res = mg_install_zip_url($webroot, $stageDir, 'plugins', $replSlug, (string) $gpmEntry['download']);
            if ($res['ok']) {
                $res['version'] = (string) ($gpmEntry['version'] ?? '?');
                $res['source']  = 'gpm';
            }
        }

        // Fall back to direct GitHub fetch via curated github_repo when not in GPM.
        if (!$res || !$res['ok']) {
            if (is_array($replEntry) && !empty($replEntry['github_repo'])) {
                $res = mg_fetch_and_install_github($webroot, $stageDir, $replSlug, $replEntry['github_repo']);
            } elseif (!$res) {
                $res = ['ok' => false, 'msg' => 'not in GPM and no github_repo in registry'];
            }
        }

        if ($res['ok']) {
            $installed[$replSlug] = ['version' => $res['version'] ?? '?', 'source' => $res['source'] ?? '?'];
            if ($progress) $progress(['phase' => 'done-entry', 'entry' => "replacement/{$replSlug}"]);
        } else {
            $failed[] = [$replSlug, $res['msg']];
            if ($progress) $progress(['phase' => 'skip', 'entry' => "replacement/{$replSlug}", 'reason' => $res['msg']]);
        }
    }

    return ['installed' => $installed, 'failed' => $failed];
}

/**
 * The compat scan only includes plugins/themes that exist in the source install.
 * A replacement (e.g. admin-next) won't be in the source — look it up directly
 * from the curated registry.
 */
function mg_lookup_registry_entry(string $slug): ?array
{
    static $all = null;
    if ($all === null) $all = mg_fetch_curated() ?? [];
    return $all['plugins'][$slug] ?? $all['themes'][$slug] ?? null;
}

/**
 * Download a zip of the default branch of <owner>/<repo> from GitHub and
 * extract its single top-level directory's contents into
 * {webroot}/{stageDir}/user/plugins/{slug}/.
 */
function mg_fetch_and_install_github(string $webroot, string $stageDir, string $slug, string $repo): array
{
    $tmp = $webroot . '/tmp';
    ensure_dir($tmp);
    $zipPath = $tmp . '/replace-' . preg_replace('/[^a-z0-9\-]/i', '_', $slug) . '.zip';

    // Try latest release first (proper tagged version). If none exists (no
    // releases published yet), fall back to the default branch HEAD and
    // record version as "1.0.0" — sentinel for "pre-release install".
    $resolved = mg_github_resolve_download($repo);

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 20,
            'header'  => "User-Agent: grav-migrate-wizard/1.0\r\nAccept: application/vnd.github+json\r\n",
            'ignore_errors' => false,
        ],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $bytes = @file_get_contents($resolved['zipball'], false, $ctx);
    if ($bytes === false || strlen($bytes) < 1024) {
        return ['ok' => false, 'msg' => "Could not download {$repo} ({$resolved['source']})"];
    }
    @file_put_contents($zipPath, $bytes);

    $dest = $webroot . '/' . $stageDir . '/user/plugins/' . $slug;
    // Remove any previous stub or partial install
    if (is_dir($dest)) remove_dir($dest);
    ensure_dir($dest);

    $zip = new ZipArchive();
    if (($rc = $zip->open($zipPath)) !== true) {
        @unlink($zipPath);
        return ['ok' => false, 'msg' => "Bad zip (code {$rc})"];
    }
    $prefix = detect_common_prefix($zip);

    for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        $rel = $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
        if ($rel === '' || str_contains($rel, '..')) continue;

        $target = $dest . '/' . $rel;
        if (substr($name, -1) === '/') { ensure_dir($target); continue; }
        ensure_dir(dirname($target));

        $stream = $zip->getStream($name);
        if ($stream === false) continue;
        $out = @fopen($target, 'wb');
        if ($out) {
            while (!feof($stream)) { $c = fread($stream, 1 << 16); if ($c === false) break; fwrite($out, $c); }
            fclose($out);
        }
        fclose($stream);
    }
    $zip->close();
    @unlink($zipPath);

    // Ensure the replacement is enabled (clears any inherited disable).
    $configFile = $webroot . '/' . $stageDir . '/user/config/plugins/' . $slug . '.yaml';
    if (is_file($configFile)) {
        $raw = (string) @file_get_contents($configFile);
        if (preg_match('/^enabled:\s*(false|0)/m', $raw)) {
            @file_put_contents($configFile, preg_replace('/^enabled:\s*(false|0)/m', 'enabled: true', $raw, 1));
        }
    }

    return ['ok' => true, 'msg' => 'installed', 'version' => $resolved['version'], 'source' => $resolved['source']];
}

/**
 * Generic "download a plugin/theme zip and extract it into stage_dir/user/{kind}/{slug}/".
 * Used by the auto-update path (GPM-listed release zips) — same logic as the
 * GitHub-replacement path but parameterized over any source zip URL.
 */
function mg_install_zip_url(string $webroot, string $stageDir, string $kind, string $slug, string $url): array
{
    $tmp = $webroot . '/tmp';
    ensure_dir($tmp);
    $safeKind = preg_replace('/[^a-z0-9]/i', '', $kind) ?: 'plugins';
    $safeSlug = preg_replace('/[^a-z0-9\-]/i', '_', $slug);
    $zipPath  = $tmp . '/update-' . $safeKind . '-' . $safeSlug . '.zip';

    $ctx = stream_context_create([
        'http' => ['timeout' => 25, 'header' => "User-Agent: grav-migrate-wizard/1.0\r\n", 'ignore_errors' => false],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $bytes = @file_get_contents($url, false, $ctx);
    if ($bytes === false || strlen($bytes) < 1024) {
        return ['ok' => false, 'msg' => "Could not download {$slug} from {$url}"];
    }
    @file_put_contents($zipPath, $bytes);

    $dest = $webroot . '/' . $stageDir . '/user/' . $kind . '/' . $slug;
    if (is_dir($dest)) remove_dir($dest);
    ensure_dir($dest);

    $zip = new ZipArchive();
    if (($rc = $zip->open($zipPath)) !== true) {
        @unlink($zipPath);
        return ['ok' => false, 'msg' => "Bad zip for {$slug} (code {$rc})"];
    }
    $prefix = detect_common_prefix($zip);
    for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        $rel = $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
        if ($rel === '' || str_contains($rel, '..')) continue;
        $target = $dest . '/' . $rel;
        if (substr($name, -1) === '/') { ensure_dir($target); continue; }
        ensure_dir(dirname($target));
        $stream = $zip->getStream($name);
        if ($stream === false) continue;
        $out = @fopen($target, 'wb');
        if ($out) {
            while (!feof($stream)) { $c = fread($stream, 1 << 16); if ($c === false) break; fwrite($out, $c); }
            fclose($out);
        }
        fclose($stream);
    }
    $zip->close();
    @unlink($zipPath);
    return ['ok' => true, 'msg' => 'installed'];
}

/**
 * Ask GitHub for the latest tagged release of <owner>/<repo>. Three-tier:
 *   1. /releases/latest    — full releases only (excludes pre-releases)
 *   2. /releases           — full list, picks newest non-draft (incl. betas)
 *   3. default-branch HEAD — last-resort sentinel marked version "1.0.0"
 *
 * Tier 2 is what catches plugins like admin2/api during the 2.0 beta line:
 * they only ship pre-release tags, /releases/latest silently 404s for those,
 * and without this tier the fallback would install untagged HEAD — which is
 * exactly the surprise we want to avoid for replacement installs.
 *
 * Returns:
 *   ['zipball' => URL, 'version' => string, 'source' => 'release'|'default-branch']
 */
function mg_github_resolve_download(string $repo): array
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 6,
            'header'  => "User-Agent: grav-migrate-wizard/1.0\r\nAccept: application/vnd.github+json\r\n",
            'ignore_errors' => true,
        ],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    // Tier 1: /releases/latest (excludes pre-releases by GitHub's design).
    $raw = @file_get_contents("https://api.github.com/repos/{$repo}/releases/latest", false, $ctx);
    if ($raw !== false) {
        $data = json_decode($raw, true);
        if (is_array($data) && !empty($data['zipball_url'])) {
            $tag = (string) ($data['tag_name'] ?? '1.0.0');
            return [
                'zipball' => $data['zipball_url'],
                'version' => ltrim($tag, 'v'),
                'source'  => 'release',
            ];
        }
    }

    // Tier 2: /releases — newest non-draft entry, pre-releases included.
    // GitHub orders the list by created_at desc by default, so the first
    // non-draft release is the newest tag.
    $raw = @file_get_contents("https://api.github.com/repos/{$repo}/releases?per_page=10", false, $ctx);
    if ($raw !== false) {
        $list = json_decode($raw, true);
        if (is_array($list)) {
            foreach ($list as $rel) {
                if (!is_array($rel) || !empty($rel['draft'])) continue;
                if (empty($rel['zipball_url'])) continue;
                $tag = (string) ($rel['tag_name'] ?? '1.0.0');
                return [
                    'zipball' => $rel['zipball_url'],
                    'version' => ltrim($tag, 'v'),
                    'source'  => 'release',
                ];
            }
        }
    }

    // Tier 3: default-branch HEAD.
    return [
        'zipball' => "https://api.github.com/repos/{$repo}/zipball",
        'version' => '1.0.0',
        'source'  => 'default-branch',
    ];
}

/**
 * Locate a CLI php binary suitable for running scripts as argv[1].
 *
 * PHP_BINARY is unreliable from a SAPI context — it can be empty, or it can
 * point at php-fpm (which is a daemon, not a script runner). We try, in
 * order:
 *   1. Sibling /bin/php next to PHP_BINARY's grandparent dir — handles the
 *      Homebrew layout where PHP_BINARY is `…/sbin/php-fpm` and the matching
 *      CLI lives at `…/bin/php`.
 *   2. PHP_BINARY itself if it's executable and doesn't look like FPM/CGI.
 *   3. Common system locations: /usr/local/bin/php, /opt/homebrew/bin/php,
 *      /usr/bin/php.
 *   4. Bare "php" — proc_open's argv form does PATH lookup when the program
 *      name has no slash, so this is the last-resort fallback.
 *
 * Returns null only if every option fails the executable check (rare).
 */
function mg_find_php_cli(): ?string
{
    $bin = (defined('PHP_BINARY') && is_string(PHP_BINARY)) ? PHP_BINARY : '';

    if ($bin !== '' && is_executable($bin)) {
        $base = basename($bin);
        // Sibling bin/php — works for Homebrew sbin/php-fpm → bin/php
        $sibling = dirname(dirname($bin)) . '/bin/php';
        if (is_executable($sibling) && basename($sibling) === 'php') {
            return $sibling;
        }
        // PHP_BINARY itself, if it's plain `php` (not -fpm, -cgi, etc.)
        if ($base === 'php' || preg_match('/^php-?[0-9]/', $base)) {
            return $bin;
        }
    }

    foreach (['/usr/local/bin/php', '/opt/homebrew/bin/php', '/usr/bin/php'] as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }

    // Rely on PATH — proc_open argv form resolves bare names against PATH.
    return 'php';
}

/**
 * Run the staged Grav 2.0's `bin/gpm update` to bring installed plugins
 * (or themes) up to their latest compatible versions on the 2.0 channel.
 *
 * $kind  is 'plugins' or 'themes'.
 * $excludeSlugs is the list of slugs to leave alone — typically symlinked
 *   slugs that we don't want gpm to overwrite (gpm replaces plugin dirs
 *   wholesale; running update on a symlinked dir would unlink the symlink
 *   and extract a fresh zip in its place, silently breaking dev wiring).
 *   If non-empty, we enumerate the rest as positional args (gpm interprets
 *   positional args as an allowlist).
 *
 * Streams gpm's stdout line-by-line into $progress so the wizard UI can
 * render a moving status (gpm prints one or two lines per package).
 *
 * Returns ['ok', 'msg', 'updated' => [slug => version], 'skipped' => [...],
 * 'output' => string].
 */
function mg_gpm_update(string $stageRoot, string $kind, array $excludeSlugs, ?callable $progress): array
{
    if (!in_array($kind, ['plugins', 'themes'], true)) {
        return ['ok' => false, 'msg' => "invalid kind: {$kind}", 'updated' => [], 'skipped' => [], 'output' => ''];
    }

    // Defensive — gpm update of 50+ packages routinely runs longer than the
    // default 30s execution limit, even though mg_stream_setup already
    // disables it for streaming pages.
    @set_time_limit(0);

    // popen / proc_open availability — some shared hosts disable them.
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('proc_open', $disabled, true) || !function_exists('proc_open')) {
        return ['ok' => false, 'msg' => 'PHP proc_open() is disabled — cannot invoke staged bin/gpm update', 'updated' => [], 'skipped' => [], 'output' => ''];
    }

    $bin = $stageRoot . '/bin/gpm';
    if (!is_file($bin)) {
        return ['ok' => false, 'msg' => "Staged bin/gpm not found at {$bin}", 'updated' => [], 'skipped' => [], 'output' => ''];
    }

    $kindFlag = $kind === 'themes' ? '-t' : '-p';

    // If we have exclusions, build an allowlist of (installed slugs) − (excluded).
    $allowSlugs = [];
    if (!empty($excludeSlugs)) {
        $excludeSet = array_flip($excludeSlugs);
        $dir = $stageRoot . '/user/' . $kind;
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $slug) {
                if ($slug === '.' || $slug === '..' || $slug[0] === '.') continue;
                if (isset($excludeSet[$slug])) continue;
                if (!is_dir($dir . '/' . $slug)) continue;
                $allowSlugs[] = $slug;
            }
        }
        if (empty($allowSlugs)) {
            return ['ok' => true, 'msg' => "No {$kind} to update (all installed are symlinked or excluded).", 'updated' => [], 'skipped' => $excludeSlugs, 'output' => ''];
        }
    }

    // Resolve a CLI php binary. PHP_BINARY isn't reliable here — it can be
    // empty (some FPM/SAPI builds), or it can point at php-fpm itself
    // (Homebrew layouts) which can't run a script as argv[1]. Prefer in
    // order: a sibling /bin/php next to PHP_BINARY (Homebrew sbin/php-fpm
    // → bin/php), then PHP_BINARY if it looks CLI, then common system
    // paths, then bare "php" (PATH lookup).
    $phpBin = mg_find_php_cli();
    if ($phpBin === null) {
        return ['ok' => false, 'msg' => 'Could not locate a CLI php binary to run bin/gpm update', 'updated' => [], 'skipped' => [], 'output' => ''];
    }

    $argv = [$phpBin, $bin, 'update', $kindFlag, '-y'];
    foreach ($allowSlugs as $s) $argv[] = $s;

    $desc = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($argv, $desc, $pipes, $stageRoot);
    if (!is_resource($proc)) {
        return ['ok' => false, 'msg' => "proc_open failed for {$phpBin} bin/gpm update", 'updated' => [], 'skipped' => [], 'output' => ''];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    // gpm's output (per package) looks like:
    //   Preparing to install Login OAuth2 [v2.2.6]
    //     |- Downloading package...   100%
    //     |- Checking destination...  ok
    //     |- Installing package...    ok
    //     '- Success!
    // We track the current package name + version from "Preparing to install"
    // and record it in $updated when "Success!" arrives. Sub-steps drive a
    // generic "log" tick so the wizard UI moves while gpm is doing its thing.
    $updated  = [];
    $output   = '';
    $buffers  = ['', ''];
    $curName  = '';
    $curVer   = '';

    while (true) {
        $r = [$pipes[1], $pipes[2]];
        $w = $e = null;
        if (@stream_select($r, $w, $e, 1) === false) break;

        foreach ($r as $stream) {
            $idx  = ($stream === $pipes[1]) ? 0 : 1;
            $data = @fread($stream, 4096);
            if ($data === false || $data === '') continue;

            $buffers[$idx] .= $data;
            while (($nl = strpos($buffers[$idx], "\n")) !== false) {
                $line = rtrim(substr($buffers[$idx], 0, $nl), "\r");
                $buffers[$idx] = substr($buffers[$idx], $nl + 1);
                if ($line === '') continue;

                $output .= $line . "\n";

                // Strip ANSI colour escapes.
                $clean = preg_replace('/\e\[[0-9;]*m/', '', $line);

                if (preg_match('/^Preparing to install\s+(.+?)\s+\[v?([^\]]+)\]\s*$/', $clean, $m)) {
                    $curName = trim($m[1]);
                    $curVer  = trim($m[2]);
                    if ($progress) $progress(['phase' => 'start', 'entry' => "gpm/{$kind}/{$curName}", 'reason' => "v{$curVer}"]);
                } elseif (preg_match("/^\s*'-\s*Success!\s*$/", $clean)) {
                    if ($curName !== '') {
                        $updated[$curName] = $curVer;
                        if ($progress) $progress(['phase' => 'done-entry', 'entry' => "gpm/{$kind}/{$curName}", 'reason' => "v{$curVer}"]);
                    }
                    $curName = '';
                    $curVer  = '';
                } elseif (preg_match('/^\s*\|-\s*(Downloading package|Checking destination|Installing package)/', $clean, $m)) {
                    if ($progress && $curName !== '') {
                        $progress(['phase' => 'log', 'entry' => "gpm/{$kind}/{$curName}", 'reason' => rtrim($m[1], '.')]);
                    }
                } elseif ($progress) {
                    // Anything else (errors, "Nothing to update", header lines)
                    // — surface as a log tick so the wizard UI keeps moving.
                    $progress(['phase' => 'log', 'entry' => "gpm/{$kind}", 'reason' => substr($clean, 0, 200)]);
                }
            }
        }

        $status = @proc_get_status($proc);
        if (is_array($status) && !$status['running'] && feof($pipes[1]) && feof($pipes[2])) break;
    }

    foreach ($buffers as $rem) if ($rem !== '') $output .= $rem . "\n";
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $code = proc_close($proc);

    return [
        'ok'      => $code === 0,
        'msg'     => $code === 0
            ? sprintf('gpm update %s: %d package(s) touched', $kind, count($updated))
            : "gpm update {$kind} exited {$code}",
        'updated' => $updated,
        'skipped' => $excludeSlugs,
        'output'  => $output,
    ];
}

/**
 * Copy the entire source user/ tree into the staged install verbatim.
 * Top-level dotfiles/dotdirs (.git, .DS_Store, editor backups) are skipped
 * as filesystem cruft. Symlinks (top-level and mid-tree) are preserved as
 * symlinks so dev environments with linked plugin/theme clones keep their
 * wiring in the staged tree. Downstream phases
 * mutate the staged tree in place (plugin policy, auto-updates, account
 * perm mirror). Appends skipped entries to $copySkipped with a reason
 * tag, and appends successfully-copied top-level names to $copiedEntries.
 */
function mg_bulk_copy_user(string $srcUser, string $dstUser, int &$copied, ?callable $progress, array &$copySkipped, array &$copiedEntries): void
{
    ensure_dir($dstUser);

    foreach (scandir($srcUser) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;

        if ($entry[0] === '.') {
            $copySkipped[] = "{$entry} (dotfile/dotdir)";
            continue;
        }

        $src = $srcUser . '/' . $entry;
        $dst = $dstUser . '/' . $entry;

        if (is_link($src)) {
            // Preserve top-level symlinks (e.g. user/plugins itself being a
            // symlink — unusual but possible in shared multi-site setups).
            // copy_tree handles deeper symlinks the same way.
            $target = @readlink($src);
            if (is_string($target) && $target !== '' && @symlink($target, $dst)) {
                $copiedEntries[] = $entry;
                if ($progress) $progress(['phase' => 'done-entry', 'entry' => "user/{$entry}", 'reason' => 'symlink preserved', 'copied' => $copied]);
            } else {
                $copySkipped[] = "{$entry} (symlink, could not preserve)";
                if ($progress) $progress(['phase' => 'skip', 'entry' => "user/{$entry}", 'reason' => 'symlink (could not preserve)', 'copied' => $copied]);
            }
            continue;
        }

        if ($progress) $progress(['phase' => 'start', 'entry' => "user/{$entry}", 'copied' => $copied]);

        if (is_dir($src)) {
            copy_tree($src, $dst, static function (string $rel) use (&$copied, $entry, $progress) {
                $copied++;
                if ($progress && $copied % 200 === 0) {
                    $progress(['phase' => 'copy', 'entry' => "user/{$entry}", 'file' => $rel, 'copied' => $copied]);
                }
            });
            $copiedEntries[] = $entry;
        } elseif (is_file($src)) {
            ensure_dir(dirname($dst));
            @copy($src, $dst);
            $copied++;
            $copiedEntries[] = $entry;
        }

        if ($progress) $progress(['phase' => 'done-entry', 'entry' => "user/{$entry}", 'copied' => $copied]);
    }
}

/**
 * Apply the chosen compat policy to the already-copied staged user/plugins/.
 *   - superseded (admin → admin2, etc.): remove the old slug dir; the
 *     replacement is installed separately via mg_install_replacements.
 *   - skip policy + incompatible: remove the slug dir entirely.
 *   - disable policy + incompatible: write user/config/plugins/<slug>.yaml
 *     with enabled: false so Grav loads the config but ignores the plugin.
 * Themes are NOT touched here — they're always kept, and Grav 2.0's Twig 3
 * compat layer handles most existing themes at runtime. Returns
 * ['skipped' => [...], 'disabled' => [...]] (bare slug names; caller
 * prefixes with "plugins/" for summary).
 */
/**
 * Apply the chosen compat policy to staged plugins, using POST-UPGRADE
 * verdicts (mg_rescan_staged result). Symlinked dirs are left alone —
 * dev environments choose their own versions and we don't second-guess
 * them. Supersedes are handled in their own phase before this runs.
 *
 * Returns ['skipped', 'disabled', 'force_included'].
 */
function mg_apply_plugin_policy(string $stageRoot, array $postScan, string $policy, string $mode, ?callable $progress): array
{
    $skipped       = [];
    $disabled      = [];
    $forceIncluded = []; // slugs that strict would have flagged as incompatible
                         // but the chosen mode promoted to compatible
    $pluginDir     = $stageRoot . '/user/plugins';
    if (!is_dir($pluginDir)) {
        return ['skipped' => $skipped, 'disabled' => $disabled, 'force_included' => $forceIncluded];
    }

    $verdicts = $postScan['plugins'] ?? [];

    foreach (scandir($pluginDir) ?: [] as $slug) {
        if ($slug === '.' || $slug === '..') continue;
        if ($slug[0] === '.') continue;
        $slugDir = $pluginDir . '/' . $slug;

        // Symlinked plugins: dev clones, leave alone.
        if (is_link($slugDir)) continue;
        if (!is_dir($slugDir)) continue;

        $label = "plugins/{$slug}";

        $rawVerdict = $verdicts[$slug] ?? ['status' => 'unknown'];
        $rawStatus  = (string) ($rawVerdict['status'] ?? 'unknown');
        $effective  = mg_effective_status($rawVerdict, $mode);

        // Track plugins that strict mode would mark incompatible but the
        // chosen mode is force-including. Used for the test-step report.
        if ($effective === 'compatible' && $rawStatus !== 'compatible' && $rawStatus !== 'needs_update') {
            $forceIncluded[] = [
                'slug'   => $slug,
                'reason' => (string) ($rawVerdict['reason'] ?? 'inferred 1.7-only'),
                'source' => (string) ($rawVerdict['source'] ?? 'default'),
            ];
        }

        if ($effective === 'compatible' || $effective === 'needs_update') continue;

        if ($policy === 'skip') {
            remove_dir($slugDir);
            $skipped[] = $slug;
            if ($progress) $progress(['phase' => 'skip', 'entry' => $label, 'reason' => 'incompatible (skip policy)']);
        } elseif ($policy === 'disable') {
            mg_write_plugin_disable($stageRoot, $slug);
            $disabled[] = $slug;
            if ($progress) $progress(['phase' => 'done-entry', 'entry' => $label, 'reason' => 'incompatible (disabled)']);
        }
    }

    return ['skipped' => $skipped, 'disabled' => $disabled, 'force_included' => $forceIncluded];
}

/**
 * Re-scan compatibility on the STAGED tree after the gpm upgrade pass has
 * run. Returns a fresh ['plugins' => [slug => verdict], 'themes' => [...]]
 * map, where verdicts reflect the post-upgrade blueprint state — so a
 * plugin that was 1.7-only on disk but got upgraded to a 2.0-compatible
 * release reads as `compatible` here.
 *
 * Reuses the source scan's cached curated registry + gpm index so we
 * don't pay another network round-trip.
 */
function mg_rescan_staged(string $stagedUserDir, array $sourceScan): array
{
    $curated = $sourceScan['curated'] ?? null;
    $gpm     = $sourceScan['gpm']     ?? ['plugins' => null, 'themes' => null];
    return [
        'plugins' => mg_scan_category($stagedUserDir . '/plugins', 'plugins', $curated, $gpm['plugins'] ?? null),
        'themes'  => mg_scan_category($stagedUserDir . '/themes',  'themes',  $curated, $gpm['themes']  ?? null),
    ];
}

/**
 * Remove staged dirs for slugs the curated registry has marked as
 * superseded by a different package (admin → admin2, etc.). Runs AFTER
 * the gpm update pass — keeping the old slug on disk during gpm prevents
 * its dependents (data-manager etc. which declare `admin` as a dep) from
 * triggering a missing-dep reinstall. The slug is also excluded from
 * gpm's update allowlist so gpm leaves it alone version-wise. The actual
 * replacement is installed later by mg_install_replacements. Idempotent.
 */
function mg_handle_supersedes(string $stageRoot, array $superseded, ?callable $progress): array
{
    $skipped = [];
    foreach ($superseded as $label => $replSlug) {
        $parts = explode('/', $label, 2);
        if (count($parts) !== 2) continue;
        [$kind, $slug] = $parts;
        if (!in_array($kind, ['plugins', 'themes'], true)) continue;

        $path = $stageRoot . '/user/' . $kind . '/' . $slug;
        if (!file_exists($path) && !is_link($path)) continue;

        if (is_link($path)) {
            @unlink($path);
        } else {
            remove_dir($path);
        }
        // Already kind/slug-prefixed (e.g. "plugins/admin (superseded by admin2)")
        // so the caller can merge straight into the summary skipped[] list.
        $skipped[] = $label . " (superseded by {$replSlug})";
        if ($progress) $progress(['phase' => 'skip', 'entry' => $label, 'reason' => "superseded by {$replSlug}"]);
    }
    return ['skipped' => $skipped];
}

function mg_write_plugin_disable(string $stageRoot, string $slug): void
{
    $dir = $stageRoot . '/user/config/plugins';
    ensure_dir($dir);
    $file = $dir . '/' . $slug . '.yaml';

    // Preserve any existing config from the imported 1.x yaml; just flip enabled.
    $existing = is_file($file) ? @file_get_contents($file) : '';
    if (is_string($existing) && $existing !== '' && preg_match('/^enabled:\s*(true|false|0|1)\s*$/m', $existing)) {
        $patched = preg_replace('/^enabled:\s*(true|false|0|1)\s*$/m', 'enabled: false', $existing, 1);
        @file_put_contents($file, $patched);
        return;
    }
    @file_put_contents($file, "enabled: false\n" . ($existing ?: ''));
}

// ─────────────────────────────────────────────────────────────────────────────
// Compatibility scan
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Run (or reuse cached) compatibility scan for plugins + themes in the source
 * install. Caches the curated registry + verdicts in .migrating.compat_scan
 * so the UI renders consistently with what do_import() will actually do.
 */
function mg_compat_scan_cached(string $webroot, array &$flag): array
{
    $cached = $flag['compat_scan'] ?? null;
    if (is_array($cached) && (time() - (int)($cached['at'] ?? 0)) < MG_COMPAT_TTL) {
        return $cached;
    }

    $curated  = mg_fetch_curated();

    // Use the staged Grav 2.0 version (and current PHP) so the catalog
    // returns each plugin's newest release that's actually compatible with
    // the migration target. Falls back to '2.0.0' when the zip hasn't been
    // cracked yet — still a 2.x query, just less specific.
    $stagedGrav = mg_staged_zip_version($webroot, $flag) ?? '';
    $php        = PHP_VERSION;
    $gpm = [
        'plugins' => mg_fetch_gpm_index('plugins', $stagedGrav, $php),
        'themes'  => mg_fetch_gpm_index('themes',  $stagedGrav, $php),
    ];

    $scan = [
        'at'      => time(),
        'source'  => $curated !== null ? 'remote' : 'offline',
        'gpm'     => $gpm,  // Cached so install paths can prefer GPM URLs over github_repo fallback.
        'curated' => $curated, // Cached so mg_rescan_staged() can re-verdict the post-upgrade staged tree without a second fetch.
        'plugins' => mg_scan_category($webroot . '/user/plugins', 'plugins', $curated, $gpm['plugins']),
        'themes'  => mg_scan_category($webroot . '/user/themes',  'themes',  $curated, $gpm['themes']),
    ];

    // Resolve install source/version info for every pending replacement
    // (replaced_by target + its transitive `requires:`) so the UI shows
    // matching info to what the install path will actually do.
    $scan['github_versions'] = mg_resolve_pending_versions($scan, $curated, $gpm);

    // No separate "pending update" pre-flight: pending updates are now
    // derived from the same v=<staged 2.0> + php=<current> GPM query that
    // mg_fetch_gpm_index() already ran. mg_resolve_update() (called by
    // mg_scan_category) writes the result into $verdict['update'], and the
    // UI badge falls back to it. Querying the source 1.7 install's bin/gpm
    // would filter the catalog with v=1.7.x and miss the 2.0-line upgrades
    // that are the whole point of the migration.

    $flag['compat_scan'] = $scan;
    save_flag($webroot . '/.migrating', $flag);
    return $scan;
}

/**
 * Walk the scan's pending replacements (replaced_by + requires transitively)
 * and call GitHub's releases API for each repo. Returns a slug → [version,
 * source] map. Cached inside the compat scan so we only hit GitHub once per
 * 15-minute TTL.
 */
function mg_resolve_pending_versions(array $scan, ?array $curated, array $gpm = []): array
{
    if ($curated === null) return [];

    $queue = [];
    foreach (['plugins', 'themes'] as $kind) {
        foreach ($scan[$kind] ?? [] as $v) {
            $repl = $v['replaced_by'] ?? null;
            if ($repl) $queue[$repl] = true;
        }
    }
    // Transitively include requires
    $stack = array_keys($queue);
    while ($stack) {
        $slug = array_shift($stack);
        $entry = $curated['plugins'][$slug] ?? $curated['themes'][$slug] ?? null;
        if (!is_array($entry) || empty($entry['requires'])) continue;
        foreach ((array) $entry['requires'] as $req) {
            if (!isset($queue[$req])) {
                $queue[$req] = true;
                $stack[] = $req;
            }
        }
    }

    $out = [];
    foreach (array_keys($queue) as $slug) {
        // Prefer GPM (matches what mg_install_replacements actually does).
        $gpmEntry = $gpm['plugins'][$slug] ?? $gpm['themes'][$slug] ?? null;
        if (is_array($gpmEntry) && !empty($gpmEntry['version'])) {
            $out[$slug] = [
                'version' => (string) $gpmEntry['version'],
                'source'  => 'gpm',
            ];
            continue;
        }
        // Fall back to GitHub via curated github_repo.
        $entry = $curated['plugins'][$slug] ?? $curated['themes'][$slug] ?? null;
        if (!is_array($entry) || empty($entry['github_repo'])) continue;
        $info = mg_github_resolve_download($entry['github_repo']);
        $out[$slug] = ['version' => $info['version'], 'source' => $info['source']];
    }
    return $out;
}

function mg_scan_category(string $dir, string $kind, ?array $curated, ?array $gpmIndex = null): array
{
    $out = [];
    if (!is_dir($dir)) return $out;

    foreach (scandir($dir) ?: [] as $slug) {
        if ($slug === '.' || $slug === '..') continue;
        $path = $dir . '/' . $slug;
        if (!is_dir($path)) continue;

        $bp = mg_read_blueprint($path . '/blueprints.yaml');
        $installedVersion = (string) ($bp['version'] ?? '');

        $verdict = mg_resolve_compat($slug, $installedVersion, $bp, $curated[$kind] ?? []);
        $verdict['installed_version'] = $installedVersion;
        // Display name from the blueprint — used when matching gpm output back
        // to slug ("Preparing to install Login OAuth2 [v2.2.6]" → login-oauth2).
        $verdict['name'] = (string) ($bp['name'] ?? '');

        // Annotate with GPM-available update if applicable.
        $verdict['update'] = mg_resolve_update($slug, $installedVersion, $verdict, $gpmIndex);

        $out[$slug] = $verdict;
    }

    return $out;
}

/**
 * Decide whether the GPM-listed latest version is an "update worth taking"
 * during 2.0 migration. Returns ['to' => version, 'download' => url] or null.
 *
 * Trust model: mg_fetch_gpm_index() pins the catalog query to v=<staged Grav
 * 2.0> + php=<current>, so any version returned here has already been
 * filtered by getgrav.org against the destination's dependency + blueprint
 * compatibility constraints. We accept the suggested upgrade unless the
 * curated registry explicitly forbids it.
 *
 * Rules:
 *   - Only consider plugins/themes with an entry in the GPM index.
 *   - Skip when latest <= installed (semver compare).
 *   - Skip when the curated registry explicitly marks the slug as
 *     2.0-incompatible (hard block — overrides the GPM filter).
 *   - When curated supplies minimum_version, skip if GPM latest is below it.
 *   - Otherwise: trust the GPM filter and offer the update. This is what lets
 *     us surface major-line upgrades (e.g. page-toc 3.x → 4.x) for plugins
 *     whose locally-installed blueprint is the legacy 1.x line and has no
 *     explicit 2.0 compat marker.
 */
function mg_resolve_update(string $slug, string $installedVersion, array $verdict, ?array $gpmIndex): ?array
{
    if (!$gpmIndex || !isset($gpmIndex[$slug])) return null;
    $entry  = $gpmIndex[$slug];
    $latest = (string) ($entry['version'] ?? '');
    $url    = (string) ($entry['download'] ?? '');
    if ($latest === '' || $url === '') return null;
    if ($installedVersion !== '' && version_compare($latest, $installedVersion, '<=')) return null;

    // Hard block: curated registry says this slug isn't 2.0-compatible at all.
    // Even if GPM returned a newer version, the registry overrides — typically
    // means the maintainer has marked it deprecated or replaced_by another slug.
    if (($verdict['source'] ?? '') === 'curated' && ($verdict['status'] ?? '') === 'incompatible') {
        return null;
    }

    // Curated minimum_version still wins when set.
    $min = (string) ($verdict['min_version'] ?? '');
    if ($min !== '' && version_compare($latest, $min, '<')) return null;

    return ['to' => $latest, 'download' => $url];
}

/**
 * Fetch the GPM plugins.json or themes.json index and reshape to slug → entry.
 * Returns null on any network/parse failure (callers fall back gracefully).
 */
/**
 * Fetch a GPM index (plugins.json | themes.json) filtered for the target
 * Grav + PHP version. v= and php= match the params Grav core sends from
 * AbstractPackageCollection — required for the catalog to return the right
 * "latest" per plugin when the plugin has multiple major lines (1.x vs 2.x).
 *
 * @param string $kind        'plugins' | 'themes'
 * @param string $gravVersion Target Grav version (e.g. '2.0.0' or the staged
 *                            zip's version). Empty falls back to '2.0.0' so
 *                            migration always queries the 2.x catalog.
 * @param string $phpVersion  Target PHP version. Defaults to PHP_VERSION since
 *                            the wizard runs in the same process the staged
 *                            install will run under.
 */
function mg_fetch_gpm_index(string $kind, string $gravVersion = '', string $phpVersion = ''): ?array
{
    $base = $kind === 'themes' ? MG_GPM_THEMES_BASE : MG_GPM_PLUGINS_BASE;
    $url  = $base . '?' . http_build_query([
        'v'       => $gravVersion !== '' ? $gravVersion : '2.0.0',
        'php'     => $phpVersion  !== '' ? $phpVersion  : PHP_VERSION,
        'testing' => 1,
    ]);
    $ctx = stream_context_create([
        'http' => ['timeout' => 6, 'ignore_errors' => true, 'header' => "User-Agent: grav-migrate-wizard/1.0\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;

    // The endpoint returns slug-keyed entries already.
    return $data;
}

/**
 * Fetch the curated compat registry from getgrav.org. Returns null on any
 * network/parse failure — callers must fall back to blueprint-only logic.
 */
function mg_fetch_curated(): ?array
{
    $ctx = stream_context_create([
        'http' => ['timeout' => 4, 'ignore_errors' => true, 'header' => "User-Agent: grav-migrate-wizard/1.0\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents(MG_COMPAT_URL, false, $ctx);
    if ($raw === false) return null;

    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Resolve one plugin/theme's compat against (in priority):
 *   1. Curated registry entry (authoritative)
 *   2. Blueprint `compatibility.grav`
 *   3. Inference from blueprint `dependencies.grav` constraint
 *   4. Default: ['1.7'] only
 *
 * Returns: ['status' => 'compatible|incompatible|needs_update|unknown',
 *           'reason' => string, 'source' => 'curated|blueprint|inferred|default',
 *           'replaced_by' => ?string, 'min_version' => ?string]
 */
function mg_resolve_compat(string $slug, string $installedVersion, array $bp, array $curatedKind): array
{
    // 1. Curated registry
    if (isset($curatedKind[$slug]) && is_array($curatedKind[$slug])) {
        $entry = $curatedKind[$slug];
        $gravs = (array) ($entry['grav'] ?? []);
        $min   = (string) ($entry['minimum_version'] ?? '');
        $repl  = $entry['replaced_by'] ?? null;

        if (!in_array(MG_COMPAT_TARGET, $gravs, true)) {
            return [
                'status' => 'incompatible',
                'reason' => $repl ? "Deprecated on 2.0 — use {$repl}" : ($entry['notes'] ?? '1.x-only'),
                'source' => 'curated',
                'replaced_by' => $repl,
                'min_version' => $min ?: null,
            ];
        }
        if ($min !== '' && $installedVersion !== '' && version_compare($installedVersion, $min, '<')) {
            return [
                'status' => 'needs_update',
                'reason' => "Requires v{$min}+ for 2.0 (installed {$installedVersion})",
                'source' => 'curated',
                'replaced_by' => null,
                'min_version' => $min,
            ];
        }
        return [
            'status' => 'compatible',
            'reason' => $entry['notes'] ?? 'Curated 2.0-compatible',
            'source' => 'curated',
            'replaced_by' => null,
            'min_version' => $min ?: null,
        ];
    }

    // 2. Blueprint explicit compatibility
    $bpCompat = $bp['compatibility']['grav'] ?? null;
    if (is_array($bpCompat)) {
        $ok = in_array(MG_COMPAT_TARGET, array_map('strval', $bpCompat), true);
        return [
            'status' => $ok ? 'compatible' : 'incompatible',
            'reason' => $ok ? 'Blueprint declares 2.0 support' : 'Blueprint lists only ' . implode(',', $bpCompat),
            'source' => 'blueprint',
            'replaced_by' => null,
            'min_version' => null,
        ];
    }

    // 3. Infer from dependencies.grav
    $inferred = mg_infer_compat_from_deps($bp['dependencies'] ?? []);
    if (in_array(MG_COMPAT_TARGET, $inferred, true)) {
        return [
            'status' => 'compatible',
            'reason' => "Inferred from dependencies.grav >= " . MG_COMPAT_TARGET,
            'source' => 'inferred',
            'replaced_by' => null,
            'min_version' => null,
        ];
    }

    // 4. Default
    return [
        'status' => 'incompatible',
        'reason' => 'Assumed 1.7-only (no explicit 2.0 compatibility)',
        'source' => 'default',
        'replaced_by' => null,
        'min_version' => null,
    ];
}

/**
 * Map a raw compat verdict (from mg_resolve_compat) to an effective status
 * given the user's selected mode. Pure function — same input always yields
 * same output. The raw scan is mode-agnostic so it can be cached once and
 * reinterpreted as the user toggles modes during a re-run.
 *
 * Rules:
 *   strict      — return verdict status as-is (current default behavior)
 *   permissive  — promote default-inferred incompatibles to compatible, but
 *                 respect curated explicit 1.x-only entries and supersedes
 *   test        — promote everything to compatible, EXCEPT supersedes (those
 *                 still get removed so the replacement plugin can be installed
 *                 cleanly — installing both old + new would conflict)
 *
 * needs_update preserved across modes (it's a real signal — the user probably
 * wants to know they're carrying forward an outdated version).
 */
function mg_effective_status(array $verdict, string $mode): string
{
    $raw = (string) ($verdict['status'] ?? 'incompatible');

    if ($raw === 'compatible' || $raw === 'needs_update') return $raw;
    // Supersedes are always honored — Phase 4 will install the replacement.
    if (!empty($verdict['replaced_by'])) return 'incompatible';

    if ($mode === MG_MODE_TEST) return 'compatible';

    if ($mode === MG_MODE_PERMISSIVE) {
        // Promote inferred/default incompatibles. Leave curated explicit
        // 1.x-only entries alone — those have a real reason behind them.
        $source = (string) ($verdict['source'] ?? 'default');
        return $source === 'curated' ? 'incompatible' : 'compatible';
    }

    return 'incompatible';
}

/**
 * Port of Grav core's Local/Package::inferCompatibility for standalone use.
 */
function mg_infer_compat_from_deps(array $dependencies): array
{
    foreach ($dependencies as $dep) {
        if (!is_array($dep) || ($dep['name'] ?? '') !== 'grav') continue;
        $version = (string) ($dep['version'] ?? '');
        if (!preg_match('/(\d+\.\d+(?:\.\d+)?)/', $version, $m)) continue;

        if (version_compare($m[1], '2.0', '>=')) return ['2.0'];
        if (version_compare($m[1], '1.8', '>=')) return ['1.8'];
        return ['1.7'];
    }
    return ['1.7'];
}

/**
 * Read a plugin/theme blueprints.yaml into the small array structure we need.
 * Uses ext-yaml when available; falls back to a narrow hand parser that
 * only understands: version, slug, compatibility.grav, dependencies (list
 * of {name, version} maps). Sufficient for all core Grav blueprints.
 */
function mg_read_blueprint(string $path): array
{
    if (!is_file($path)) return [];

    if (function_exists('yaml_parse_file')) {
        $data = @yaml_parse_file($path);
        if (is_array($data)) return $data;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) return [];

    $out = [];
    if (preg_match('/^version:\s*["\']?([\w.\-+]+)["\']?/m', $raw, $m))  $out['version']  = $m[1];
    if (preg_match('/^slug:\s*["\']?([\w.\-]+)["\']?/m', $raw, $m))      $out['slug']     = $m[1];

    // compatibility.grav: ['1.7','2.0'] — one-line flow style
    if (preg_match('/^compatibility:\s*\n(?:\s+\S.*\n)*?\s+grav:\s*\[([^\]]+)\]/m', $raw, $m)) {
        $out['compatibility']['grav'] = array_map(static fn($s) => trim($s, " \t\"'"), explode(',', $m[1]));
    }

    // dependencies: - { name: grav, version: '>=1.7.0' }
    if (preg_match_all('/-\s*\{\s*name:\s*([\w.\-]+)\s*,\s*version:\s*["\']?([^"\'}\s]+)["\']?\s*\}/m', $raw, $ms, PREG_SET_ORDER)) {
        $out['dependencies'] = [];
        foreach ($ms as $dep) {
            $out['dependencies'][] = ['name' => $dep[1], 'version' => $dep[2]];
        }
    }

    return $out;
}

/**
 * Recursively copy a directory tree. Invokes $onFile for each file copied
 * with the relative path under $src. Returns null on success, or an error
 * string on the first failure.
 */
function copy_tree(string $src, string $dst, callable $onFile, string $prefix = ''): ?string
{
    if (!is_dir($dst) && !@mkdir($dst, 0755, true) && !is_dir($dst)) {
        return "Could not create {$dst}";
    }

    $dh = @opendir($src);
    if ($dh === false) return "Could not open {$src}";

    while (($entry = readdir($dh)) !== false) {
        if ($entry === '.' || $entry === '..') continue;

        $srcPath = $src . '/' . $entry;
        $dstPath = $dst . '/' . $entry;
        $rel     = $prefix === '' ? $entry : $prefix . '/' . $entry;

        if (is_link($srcPath)) {
            // Preserve the symlink as-is. Common in dev environments where
            // user/plugins/<slug> or user/themes/<slug> point to a sibling
            // working clone — the staged tree should keep the same wiring so
            // those clones remain live during testing. The downstream gpm
            // update phase detects symlinked slugs and skips updating them
            // (otherwise gpm would unlink the symlink and overwrite with a
            // fresh zip).
            $target = @readlink($srcPath);
            if (is_string($target) && $target !== '') {
                @symlink($target, $dstPath);
            }
            continue;
        }

        if (is_dir($srcPath)) {
            $err = copy_tree($srcPath, $dstPath, $onFile, $rel);
            if ($err !== null) { closedir($dh); return $err; }
        } else {
            if (!@copy($srcPath, $dstPath)) {
                closedir($dh);
                return "Could not copy {$rel}";
            }
            $onFile($rel);
        }
    }
    closedir($dh);
    return null;
}

function ensure_dir(string $path): void
{
    if (!is_dir($path)) @mkdir($path, 0755, true);
}

function mg_stream_setup(string $title, string $subtitle): void
{
    // Streaming steps are inherently long-running — bulk copies, downloads,
    // and gpm subprocess invocations routinely exceed PHP's default 30s
    // max_execution_time. Disable the limit and ignore user abort so a
    // user accidentally navigating away doesn't strand the migration in a
    // half-applied state.
    @set_time_limit(0);
    @ignore_user_abort(true);

    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) @ob_end_flush();
    @ob_implicit_flush(true);

    header('Content-Type: text/html; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    page_header($title);
    echo '<div class="mg-page">';
    echo '<div class="mg-hero"><div class="mg-hero-inner">';
    echo '<div class="mg-hero-icon"><span class="mg-spin-ring" aria-hidden="true"></span></div>';
    echo '<div class="mg-hero-text"><h2>' . htmlspecialchars($title) . '</h2>';
    echo '<p>' . $subtitle . '</p>';
    echo '</div></div></div>';

    echo '<div class="mg-card mg-card-active"><h3>Progress</h3>';
    echo '<p class="mg-progress-text">Current: <code id="mg-entry">starting…</code></p>';
    echo '<p class="mg-progress-text"><span id="mg-count">0</span> files copied</p>';
    echo '<ul id="mg-log" class="mg-log"></ul>';
    echo '</div>';

    echo '<script>var mgEntry=document.getElementById("mg-entry"),mgCount=document.getElementById("mg-count"),mgLog=document.getElementById("mg-log");function mgImport(e,f,c,p){if(p==="start"){mgEntry.textContent=e;var li=document.createElement("li");li.id="mg-log-"+e;li.textContent="⟳ "+e;mgLog.appendChild(li);}else if(p==="done-entry"){var l=document.getElementById("mg-log-"+e);if(l){l.textContent="✓ "+e;l.classList.add("mg-log-done");}}else if(p==="skip"){var li=document.createElement("li");li.textContent="○ "+e+" (skipped)";li.classList.add("mg-log-skip");mgLog.appendChild(li);}else if(p==="copy"){mgEntry.textContent=e+" — "+f;}mgCount.textContent=c;}</script>';
    flush();
}

function mg_stream_progress_cb(): callable
{
    return static function (array $evt) {
        $entry  = $evt['entry']  ?? '';
        $file   = $evt['file']   ?? '';
        $copied = $evt['copied'] ?? 0;
        $phase  = $evt['phase']  ?? 'copy';
        echo '<script>mgImport(' . json_encode($entry) . ',' . json_encode($file) . ',' . (int) $copied . ',' . json_encode($phase) . ');</script>';
        echo str_repeat(' ', 1024) . "\n";
        flush();
    };
}

function mg_stream_finish(array $result, string $token, string $flashKey): void
{
    if ($result['ok']) {
        $self = basename($_SERVER['SCRIPT_NAME'] ?? 'migrate.php');
        $qs   = http_build_query(['token' => $token, 'flash' => $flashKey, 'msg' => $result['msg']]);
        $url  = base_path_from_script() . $self . '?' . $qs;
        echo '<div class="mg-callout mg-callout-ok"><i class="mg-i-info"></i><div><strong>Done.</strong> ' . htmlspecialchars($result['msg']) . ' &mdash; redirecting…</div></div>';
        echo '<script>window.location.replace(' . json_encode($url) . ');</script>';
        echo '<noscript><p><a href="' . htmlspecialchars($url) . '">Continue</a></p></noscript>';
    } else {
        echo '<div class="mg-callout mg-callout-error"><i class="mg-i-warn"></i><div><strong>Failed.</strong> ' . htmlspecialchars($result['msg']) . '</div></div>';
    }
    echo '</div>';
    page_footer();
}

function stream_plugins_themes_page(string $webroot, array $flag, string $token, array $options): void
{
    mg_stream_setup('Migrating user/ …', 'Bulk-copying your entire <code>user/</code> directory (pages, data, config, languages, accounts, plugins, themes, and any custom folders) from your live site into the staged Grav 2.0, then applying plugin transforms: incompatible plugins follow your chosen policy, 2.0-compatible plugins are updated to latest, and replacements (admin2, api, etc.) are installed. Themes are kept as-is — Grav 2.0\'s default Twig 3 compatibility mode aims to keep existing themes rendering.');
    $result = do_plugins_themes($webroot, $flag, $options, mg_stream_progress_cb());
    mg_stream_finish($result, $token, 'plugins_done');
}

function stream_accounts_page(string $webroot, array $flag, string $token, array $options): void
{
    mg_stream_setup('Processing accounts…', 'Account yamls were copied during Step 2' . (empty($options['migrate_perms']) ? '; confirming accounts step and moving on.' : '. Mirroring <code>admin.*</code> permissions to <code>api.*</code> in place so the same users keep full access on Admin 2.0.'));
    $result = do_accounts($webroot, $flag, $options, mg_stream_progress_cb());
    mg_stream_finish($result, $token, 'accounts_done');
}

function stream_content_page(string $webroot, array $flag, string $token): void
{
    mg_stream_setup('Content confirmation…', 'All <code>user/</code> content (pages, data, config, languages, custom folders) was bulk-copied during Step 2. Marking content complete and advancing to testing.');
    $result = do_content($webroot, $flag, mg_stream_progress_cb());
    mg_stream_finish($result, $token, 'content_done');
}

function stream_promote_page(string $webroot, array $flag, string $token): void
{
    mg_stream_setup('Promoting Grav 2.0 to live…', 'Moving the existing install into a timestamped backup and swapping the staged Grav 2.0 into the webroot. Keep this tab open until it completes.');

    $result = do_promote($webroot, $flag, mg_stream_progress_cb());

    if ($result['ok']) {
        // After promote, .migrating / migrate.php are inside the backup dir —
        // the wizard's state is effectively gone, so redirect to the new
        // live webroot instead of trying to re-render a wizard page.
        $base = base_path_from_script();
        echo '<div class="mg-callout mg-callout-ok"><i class="mg-i-info"></i><div><strong>Migration complete.</strong> ' . htmlspecialchars($result['msg']) . ' &mdash; redirecting to the new install…</div></div>';
        echo '<script>window.location.replace(' . json_encode($base) . ');</script>';
        echo '<noscript><p><a href="' . htmlspecialchars($base) . '">Open the new Grav 2.0 install</a></p></noscript>';
    } else {
        echo '<div class="mg-callout mg-callout-error"><i class="mg-i-warn"></i><div><strong>Promote failed.</strong> ' . htmlspecialchars($result['msg']) . '</div></div>';
    }

    echo '</div>';
    page_footer();
}

// ─────────────────────────────────────────────────────────────────────────────
// Reset
// ─────────────────────────────────────────────────────────────────────────────

function wizard_reset(string $webroot, string $stageDir): void
{
    @unlink($webroot . '/.migrating');
    @unlink($webroot . '/migrate.php');
    @unlink($webroot . '/tmp/grav-2.0-staged.zip');

    // Restore parent .htaccess if the Test step patched it.
    mg_unpatch_htaccess($webroot);

    $stagePath = $webroot . '/' . trim($stageDir, '/');
    if ($stageDir !== '' && is_dir($stagePath)) {
        remove_dir($stagePath);
    }
}

/**
 * Light reset: clear the stage dir + .htaccess patch, keep the staged zip and
 * the wizard, and rewrite .migrating with only the kickoff-time keys (step
 * rewound to 'staged'). Wizard run state (plugins_themes/accounts/content
 * choices, _prev_options, etc.) is dropped so the rerun starts clean. Lets the
 * user re-run the wizard without re-downloading Grav 2.0.
 */
function wizard_restart(string $webroot, string $flagPath, array $flag): void
{
    mg_unpatch_htaccess($webroot);

    $stageDir  = trim((string) ($flag['stage_dir'] ?? 'grav-2'), '/');
    $stagePath = $webroot . '/' . $stageDir;
    if ($stageDir !== '' && is_dir($stagePath)) {
        remove_dir($stagePath);
    }

    $minimal = array_filter([
        'token'      => $flag['token']      ?? '',
        'created'    => $flag['created']    ?? time(),
        'step'       => 'staged',
        'source'     => $flag['source']     ?? null,
        'stage_dir'  => $flag['stage_dir']  ?? 'grav-2',
        'staged_zip' => $flag['staged_zip'] ?? 'tmp/grav-2.0-staged.zip',
        'wizard_url' => $flag['wizard_url'] ?? null,
    ], static fn($v) => $v !== null && $v !== '');
    save_flag($flagPath, $minimal);
}

function remove_dir(string $path): void
{
    // Symlinks are unlinked, never traversed — staged trees can contain
    // symlinked plugin clones (developer convenience). Following the symlinks
    // would attempt to delete real source files outside the staged tree.
    if (is_link($path)) { @unlink($path); return; }
    if (!is_dir($path)) { return; }
    $items = @scandir($path);
    if ($items === false) { return; }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $sub = $path . DIRECTORY_SEPARATOR . $item;
        if (is_link($sub)) {
            @unlink($sub);
        } elseif (is_dir($sub)) {
            remove_dir($sub);
        } else {
            @unlink($sub);
        }
    }
    @rmdir($path);
}

/**
 * Rewind the wizard to a previous step so subsequent steps can be re-run with
 * different options. Wipes the staged files for everything downstream of the
 * target so the re-run starts clean. Source user/ on the live 1.x install is
 * never touched.
 *
 *   'extracted'     — re-run plugins_themes (Step 2). Wipes staged user/
 *                     entirely; bulk-copy will rebuild it.
 *   'plugins_done'  — re-run accounts (Step 3). Re-copies source
 *                     user/accounts/ over the staged copy so previously-
 *                     applied perm mirrors are undone.
 *   'accounts_done' — re-run content (Step 4). Summary-only step; just
 *                     clears the prior summary blob.
 *
 * Previous step options are stashed under flag['_prev_options'][target] so
 * the prerequisite step's form can pre-fill from the last run.
 *
 * NOTE: re-running 'extracted' is destructive to any hand-edits made in the
 * staged install during Test (Step 5). Documented in the UI confirmation.
 */
function mg_rewind_to(string $webroot, array &$flag, string $target): void
{
    if (!in_array($target, ['extracted', 'plugins_done', 'accounts_done'], true)) {
        throw new InvalidArgumentException("Unknown rewind target: {$target}");
    }

    $stageDir = trim((string)($flag['stage_dir'] ?? 'grav-2'), '/');
    $stagedUser = $webroot . '/' . $stageDir . '/user';
    $stash = $flag['_prev_options'] ?? [];

    switch ($target) {
        case 'extracted':
            if (is_dir($stagedUser)) {
                mg_rm_tree($stagedUser);
            }
            if (isset($flag['plugins_themes'])) {
                $stash['plugins_themes'] = [
                    'mode'        => $flag['plugins_themes']['mode']        ?? MG_MODE_STRICT,
                    'policy'      => $flag['plugins_themes']['policy']      ?? 'disable',
                    'auto_update' => $flag['plugins_themes']['auto_update'] ?? true,
                ];
            }
            unset($flag['plugins_themes'], $flag['accounts'], $flag['content']);
            unset($flag['compat_scan']);
            break;

        case 'plugins_done':
            $srcAccounts = $webroot . '/user/accounts';
            $dstAccounts = $stagedUser . '/accounts';
            if (is_dir($dstAccounts)) {
                mg_rm_tree($dstAccounts);
            }
            if (is_dir($srcAccounts)) {
                copy_tree($srcAccounts, $dstAccounts, static function () {});
            }
            if (isset($flag['accounts'])) {
                $stash['accounts'] = [
                    'migrate_perms' => empty($flag['accounts']['skipped_perms']),
                ];
            }
            unset($flag['accounts'], $flag['content']);
            break;

        case 'accounts_done':
            unset($flag['content']);
            break;
    }

    if ($stash) {
        $flag['_prev_options'] = $stash;
    }
    $flag['step'] = $target;
    $flag['rerun_count'][$target] = ($flag['rerun_count'][$target] ?? 0) + 1;
    save_flag($webroot . '/.migrating', $flag);
}

// ─────────────────────────────────────────────────────────────────────────────
// Renderers
// ─────────────────────────────────────────────────────────────────────────────

function render_error_page(string $title, string $body): void
{
    page_header($title);
    echo '<div class="mg-page">';
    echo '<div class="mg-callout mg-callout-error"><i class="mg-i-warn"></i><div><strong>' . htmlspecialchars($title) . '</strong><br>' . $body . '</div></div>';
    echo '</div>';
    page_footer();
}

function render_wizard(array $flag, string $step, string $webroot, string $stageDir, string $stagedZip, string $flagPath, string $token, ?array $flash): void
{
    $stageDirAbs = $webroot . '/' . ltrim($stageDir, '/');

    $preflight = [
        ['webroot writable',           is_writable($webroot)],
        ['staged zip present',         is_file($webroot . '/' . ltrim($stagedZip, '/'))],
        ['staged zip readable',        is_readable($webroot . '/' . ltrim($stagedZip, '/'))],
        ['stage dir writable / absent', !is_dir($stageDirAbs) || is_writable($stageDirAbs)],
        ['PHP version >= 8.3',         version_compare(PHP_VERSION, '8.3.0', '>=')],
        ['zip extension loaded',       extension_loaded('zip')],
    ];
    $preflightOk = !array_filter($preflight, static fn($c) => !$c[1]);

    page_header('Grav 2.0 Migration Wizard');
    echo '<div class="mg-page">';

    // Cache the staged-zip Grav version into flag once (cheap zip peek the
    // first time, free after) so hero + staged-state row + Step 1 can all
    // show the same version pre-extraction.
    if (empty($flag['extracted']['grav_version']) && empty($flag['staged_zip_version'])) {
        mg_staged_zip_version($webroot, $flag);
    }

    // Hero
    $stagedGravVersion = (string) ($flag['extracted']['grav_version'] ?? $flag['staged_zip_version'] ?? '');
    $heroTitle = $stagedGravVersion !== ''
        ? 'Grav 2.0 Migration Wizard <span class="mg-hero-version">v' . htmlspecialchars($stagedGravVersion) . '</span>'
        : 'Grav 2.0 Migration Wizard';

    echo '<div class="mg-hero"><div class="mg-hero-inner">';
    echo '<div class="mg-hero-icon">' . MG_ROCKET_SVG . '</div>';
    echo '<div class="mg-hero-text"><h2>' . $heroTitle . '</h2>';
    echo '<p>Standalone wizard for staging and (eventually) importing your site into a fresh Grav 2.0 at <code>/' . htmlspecialchars($stageDir) . '/</code>. No Grav 1.x code is loaded.</p>';
    echo '</div></div></div>';

    render_stepper($step);

    if ($flash) {
        $successKeys = ['extracted', 'imported', 'ok', 'plugins_done', 'accounts_done', 'content_done', 'test_done', 'restarted'];
        $type = in_array($flash['type'], $successKeys, true)
            ? 'ok'
            : ($flash['type'] === 'error' ? 'error' : 'warn');
        $msg = htmlspecialchars($flash['msg'] ?: ucfirst($flash['type']));

        // Map flash type → the just-completed step's flag key, so we can
        // expand the rich breakdown inline beneath the success message.
        $detailsMap = [
            'plugins_done'  => 'plugins_themes',
            'accounts_done' => 'accounts',
            'content_done'  => 'content',
        ];
        $detailsKey = $detailsMap[$flash['type']] ?? null;

        echo '<div class="mg-callout mg-callout-' . $type . '"><i class="mg-i-info"></i><div>';
        echo '<div>' . $msg . '</div>';

        if ($detailsKey && isset($flag[$detailsKey])) {
            echo '<details class="mg-details mg-flash-details"><summary>Show what was copied / skipped / disabled</summary>';
            echo '<div class="mg-details-body">';
            if ($detailsKey === 'plugins_themes') mg_render_pt_details($flag[$detailsKey]);
            elseif ($detailsKey === 'accounts')   mg_render_accounts_details($flag[$detailsKey]);
            elseif ($detailsKey === 'content')    mg_render_content_details($flag[$detailsKey]);
            echo '</div></details>';
        }

        echo '</div></div>';
    }

    render_current_step($step, $preflight, $preflightOk, $flag, $stageDir, $stageDirAbs, $token);

    // Always-visible staged state
    echo '<div class="mg-card mg-card-collapsed"><h3>Staged state</h3><table class="mg-table">';
    foreach ([
        'Source root'           => $flag['source']['root'] ?? '',
        'Source Grav version'   => $flag['source']['grav_version'] ?? '',
        'Staged Grav version'   => $flag['extracted']['grav_version']
                                       ?? (isset($flag['staged_zip_version']) ? $flag['staged_zip_version'] . ' (in zip; not yet extracted)' : '(not yet extracted)'),
        'Trigger'               => $flag['source']['trigger'] ?? '',
        'Flag created'          => isset($flag['created']) ? date('Y-m-d H:i:s', (int) $flag['created']) : '',
        'Stage directory'       => $stageDir,
        'Staged zip'            => $stagedZip,
        'Current step'          => $step,
    ] as $k => $v) {
        echo '<tr><th>' . htmlspecialchars($k) . '</th><td><code>' . htmlspecialchars((string) $v) . '</code></td></tr>';
    }
    if (isset($flag['extracted']['files'])) {
        echo '<tr><th>Extracted</th><td><code>' . (int) $flag['extracted']['files'] . ' files' . ($flag['extracted']['prefix_stripped'] ? ' (stripped prefix: ' . htmlspecialchars($flag['extracted']['prefix_stripped']) . ')' : '') . '</code></td></tr>';
    }
    if (isset($flag['plugins_themes'])) {
        $pt = $flag['plugins_themes'];
        $modeStr = (string) ($pt['mode'] ?? MG_MODE_STRICT);
        $summary = (int) ($pt['files'] ?? 0) . ' files · mode: ' . $modeStr
                 . ' · policy: ' . ($pt['policy'] ?? 'disable')
                 . ' · disabled ' . count($pt['disabled'] ?? [])
                 . ' · skipped ' . count($pt['skipped'] ?? [])
                 . ' · force-included ' . count($pt['force_included'] ?? [])
                 . ' · installed ' . count($pt['replacements']['installed'] ?? []);

        echo '<tr><th>Copy &amp; migrate</th><td>';
        echo '<details class="mg-details"><summary><code>' . htmlspecialchars($summary) . '</code></summary>';
        echo '<div class="mg-details-body">';
        mg_render_pt_details($pt);
        mg_render_rerun_form($token, 'extracted', 'Re-run Step 2 with different options', 'This will wipe the staged user/ and re-bulk-copy from your live 1.x install. Steps 3 and 4 will need to be re-run too.');
        echo '</div></details></td></tr>';
    }
    if (isset($flag['accounts'])) {
        $ac = $flag['accounts'];
        $summary = (int) ($ac['count'] ?? 0) . ' account file(s)'
                 . (!empty($ac['skipped_perms'])
                     ? '; permission mirror skipped'
                     : '; mirrored ' . (int) ($ac['migrated_perms'] ?? 0) . ' admin.* → api.* perms');

        echo '<tr><th>Accounts</th><td>';
        echo '<details class="mg-details"><summary><code>' . htmlspecialchars($summary) . '</code></summary>';
        echo '<div class="mg-details-body">';
        mg_render_accounts_details($ac);
        mg_render_rerun_form($token, 'plugins_done', 'Re-run Step 3 with different options', 'Re-copies user/accounts/ from your live 1.x install and clears Step 4. Plugins/themes (Step 2) are not affected.');
        echo '</div></details></td></tr>';
    }
    if (isset($flag['content'])) {
        $c = $flag['content'];
        $summary = count($c['entries'] ?? []) . ' top-level entries in staged user/';

        echo '<tr><th>Content</th><td>';
        echo '<details class="mg-details"><summary><code>' . htmlspecialchars($summary) . '</code></summary>';
        echo '<div class="mg-details-body">';
        mg_render_content_details($c);
        mg_render_rerun_form($token, 'accounts_done', 'Re-run Step 4', 'Step 4 is a summary-only step; re-running just refreshes the entry list.');
        echo '</div></details></td></tr>';
    }
    echo '</table></div>';

    // Restart / Reset card
    echo '<div class="mg-card mg-card-muted"><h3>Restart or Reset</h3>';
    echo '<p>Your original site at <code>' . htmlspecialchars($webroot) . '</code> is <strong>not</strong> touched by either action.</p>';
    echo '<p><strong>Restart Wizard</strong> clears the staged Grav 2.0 directory and your wizard progress, but keeps the downloaded release zip and your migration token so you can re-run from step 1 without re-downloading.</p>';
    echo '<p><strong>Reset Migration</strong> abandons the migration entirely — deletes <code>.migrating</code>, <code>migrate.php</code>, the staged zip, and the staged Grav 2.0 directory. Starting over re-downloads Grav 2.0.</p>';
    echo '<div class="mg-action-buttons" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">';
    echo '<form method="post" style="margin:0" onsubmit="return confirm(\'Restart the wizard? Clears the staged Grav 2.0 directory and any wizard progress; keeps the downloaded zip and migration token.\');">';
    echo '<input type="hidden" name="action" value="restart">';
    echo '<input type="hidden" name="token"  value="' . htmlspecialchars($token) . '">';
    echo '<button type="submit" class="mg-btn mg-btn-secondary">Restart Wizard</button>';
    echo '</form>';
    echo '<form method="post" style="margin:0" onsubmit="return confirm(\'Reset the migration completely? Deletes .migrating, migrate.php, the staged zip, and the staged Grav 2.0 directory.\');">';
    echo '<input type="hidden" name="action" value="reset">';
    echo '<input type="hidden" name="token"  value="' . htmlspecialchars($token) . '">';
    echo '<button type="submit" class="mg-btn mg-btn-danger">Reset Migration</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';

    echo '</div>';
    page_footer();
}

function render_stepper(string $current): void
{
    // Stepper shows the five actions the user performs.
    $steps = [
        ['label' => 'Extract',          'done_when' => ['extracted','plugins_done','accounts_done','content_done','test_done','promoted'], 'current_when' => 'staged'],
        ['label' => 'Copy & Migrate', 'done_when' => ['plugins_done','accounts_done','content_done','test_done','promoted'],             'current_when' => 'extracted'],
        ['label' => 'Accounts',         'done_when' => ['accounts_done','content_done','test_done','promoted'],                            'current_when' => 'plugins_done'],
        ['label' => 'Content',          'done_when' => ['content_done','test_done','promoted'],                                            'current_when' => 'accounts_done'],
        ['label' => 'Test',             'done_when' => ['test_done','promoted'],                                                           'current_when' => 'content_done'],
        ['label' => 'Promote',          'done_when' => ['promoted'],                                                                       'current_when' => 'test_done'],
    ];

    echo '<ol class="mg-stepper">';
    foreach ($steps as $i => $s) {
        if (in_array($current, $s['done_when'], true)) {
            $state = 'done';
        } elseif ($current === $s['current_when']) {
            $state = 'current';
        } else {
            $state = 'pending';
        }
        echo '<li class="mg-step mg-step-' . $state . '"><span class="mg-step-dot">' . ($state === 'done' ? '✓' : ($i + 1)) . '</span><span class="mg-step-label">' . ($i + 1) . '. ' . htmlspecialchars($s['label']) . '</span></li>';
    }
    echo '</ol>';
}

function render_current_step(string $step, array $preflight, bool $preflightOk, array $flag, string $stageDir, string $stageDirAbs, string $token): void
{
    switch ($step) {
        case 'staged':
            $webroot = dirname($stageDirAbs);
            $flagRef = $flag;
            $stagedVersion = mg_staged_zip_version($webroot, $flagRef);
            $verBadge = $stagedVersion ? ' <span class="mg-version-pill">v' . htmlspecialchars($stagedVersion) . '</span>' : '';

            echo '<div class="mg-card mg-card-active"><h3>Step 1: Extract Grav 2.0' . $verBadge . '</h3>';
            echo '<p>The Grav ' . ($stagedVersion ? '<strong>v' . htmlspecialchars($stagedVersion) . '</strong>' : '2.0') . ' zip is ready at <code>' . htmlspecialchars($flag['staged_zip'] ?? '') . '</code>. Once all pre-flight checks pass, extract it into <code>/' . htmlspecialchars($stageDir) . '/</code>.</p>';
            echo '<table class="mg-table">';
            foreach ($preflight as [$label, $ok]) {
                echo '<tr><th>' . htmlspecialchars($label) . '</th><td class="' . ($ok ? 'mg-ok' : 'mg-fail') . '">' . ($ok ? 'OK' : 'FAILED') . '</td></tr>';
            }
            echo '</table>';
            echo '<form method="post" style="margin-top:16px">';
            echo '<input type="hidden" name="action" value="extract">';
            echo '<input type="hidden" name="token"  value="' . htmlspecialchars($token) . '">';
            echo '<button type="submit" class="mg-btn mg-btn-primary"' . ($preflightOk ? '' : ' disabled') . '>Extract &amp; stage Grav 2.0 →</button>';
            if (!$preflightOk) echo ' <span class="mg-btn-note">Resolve the failed checks above first.</span>';
            echo '</form>';
            echo '</div>';
            break;

        case 'extracted':
            $webroot = dirname($stageDirAbs);
            $flagRef = $flag;
            $scan    = mg_compat_scan_cached($webroot, $flagRef);

            echo '<div class="mg-card mg-card-active"><h3>Step 2: Copy &amp; Migrate</h3>';
            echo '<p>This is the heavy-lifting step: your entire <code>user/</code> directory (pages, data, config, languages, accounts, plugins, themes, and any custom folders) is copied into the staged install, then plugin transforms are applied based on your choices below. Steps 3 and 4 just run light transforms on the already-copied data.</p>';
            echo '<p>Review 2.0 compatibility. <strong>Plugins</strong>: incompatible ones follow the policy you choose. <strong>Themes</strong>: always kept as-is — Grav 2.0 ships Twig 3 compatibility mode (enabled by default), which lets most existing themes (including custom ones) render without changes. Verify the staged site in Step 5 before promoting to live.</p>';

            if ($scan['source'] === 'offline') {
                echo '<div class="mg-callout mg-callout-warn"><i class="mg-i-info"></i><div>Could not reach the curated compatibility registry. Falling back to blueprint + dependency inference only — verdicts may be less accurate.</div></div>';
            }

            // Upgrade preview — counts how many rows have a candidate upgrade
            // resolved via mg_resolve_update() (i.e. GPM, with v=<staged 2.0>,
            // is offering a newer version than what's installed).
            $countUpdates = static fn(array $bucket): int => count(array_filter(
                $bucket,
                static fn($v) => is_array($v) && !empty($v['update'])
            ));
            $pendingPlugins = $countUpdates($scan['plugins'] ?? []);
            $pendingThemes  = $countUpdates($scan['themes']  ?? []);
            if ($pendingPlugins + $pendingThemes > 0) {
                $parts = [];
                if ($pendingPlugins > 0) $parts[] = "<strong>{$pendingPlugins}</strong> plugin update" . ($pendingPlugins === 1 ? '' : 's');
                if ($pendingThemes  > 0) $parts[] = "<strong>{$pendingThemes}</strong> theme update"  . ($pendingThemes  === 1 ? '' : 's');
                $msg = implode(' and ', $parts) . ' available on GPM.';
                echo '<div class="mg-callout mg-callout-info"><i class="mg-i-info"></i><div>'
                   . $msg . ' With <em>upgrade plugins during migration</em> on (default), Grav 2.0&apos;s GPM picks up these versions during Copy &amp; Migrate — only ones gpm confirms are 2.0-compatible actually get installed. Rows in the table below tagged &ldquo;<strong>↑ will upgrade to vX.Y.Z</strong>&rdquo; are the candidates.'
                   . '</div></div>';
            }

            render_compat_breakdown($scan);

            // Pre-fill from previous run if user rewound to here.
            $prev = $flag['_prev_options']['plugins_themes'] ?? null;
            $prevMode     = $prev['mode']        ?? MG_MODE_STRICT;
            $prevPolicy   = $prev['policy']      ?? 'disable';
            $prevAutoUpd  = $prev['auto_update'] ?? true;
            $rerunCount   = (int)($flag['rerun_count']['extracted'] ?? 0);

            if ($prev !== null) {
                echo '<div class="mg-callout mg-callout-warn"><i class="mg-i-info"></i><div>Re-running this step (#' . ($rerunCount + 1) . '). The staged <code>user/</code> was wiped — any hand-edits made while testing have been lost. Source <code>user/</code> on the live 1.x install is unchanged.</div></div>';
            }

            $isChecked = static fn(string $v, string $cur) => $v === $cur ? ' checked' : '';

            echo '<form method="post" style="margin-top:18px" id="mg-pt-form">';
            echo '<input type="hidden" name="action" value="plugins_themes">';
            echo '<input type="hidden" name="token"  value="' . htmlspecialchars($token) . '">';

            // Upgrade pass — primary action. Positive-framed (checked = run
            // upgrade) so the default state is unambiguous. The action handler
            // maps !upgrade_plugins → skip_update for back-compat with the
            // existing flag schema.
            echo '<p style="margin:0 0 8px;font-size:13.5px;color:#333;font-weight:600">Upgrade plugins during migration:</p>';
            echo '<label class="mg-check mg-check-block">'
               . '<input type="checkbox" name="upgrade_plugins" value="1"' . ($prevAutoUpd ? ' checked' : '') . '>'
               . ' <strong>Run upgrade pass after copy</strong> '
               . '<span class="mg-btn-note">(recommended) Grav 2.0&apos;s <code>bin/gpm update</code> runs after the copy step and upgrades any plugin with a newer 2.0-compatible version on GPM. Symlinked plugins and ones without a 2.0-compatible release are left alone.</span>'
               . '</label>';

            echo '<p style="margin:14px 0 8px;font-size:13.5px;color:#333;font-weight:600">Compatibility mode:</p>';
            echo '<label class="mg-check mg-check-block"><input type="radio" name="mode" value="strict" data-mode="strict"' . $isChecked('strict', $prevMode) . '> <strong>Strict</strong> <span class="mg-btn-note">(recommended) only carry forward plugins/themes the curated registry or their blueprint explicitly marks as 2.0-compatible</span></label>';
            echo '<label class="mg-check mg-check-block"><input type="radio" name="mode" value="permissive" data-mode="permissive"' . $isChecked('permissive', $prevMode) . '> <strong>Permissive</strong> <span class="mg-btn-note">also carry forward plugins where compat is just unknown — only items the curated registry explicitly flags 1.x-only stay incompatible</span></label>';
            echo '<label class="mg-check mg-check-block"><input type="radio" name="mode" value="test" data-mode="test"' . $isChecked('test', $prevMode) . '> <strong>Test</strong> <span class="mg-btn-note">(not for production) carry forward and enable EVERYTHING you have — useful for finding what actually breaks under 2.0</span></label>';

            echo '<div id="mg-pt-policy" style="margin-top:14px"' . ($prevMode === 'test' ? ' hidden' : '') . '>';
            echo '<p style="margin:0 0 8px;font-size:13.5px;color:#333;font-weight:600">For incompatible plugins (after the upgrade pass):</p>';
            echo '<label class="mg-check mg-check-block"><input type="radio" name="policy" value="disable"' . $isChecked('disable', $prevPolicy) . '> Copy but <strong>disable</strong> them <span class="mg-btn-note">(keeps your config; reinstall a compatible version in-place)</span></label>';
            echo '<label class="mg-check mg-check-block"><input type="radio" name="policy" value="skip"' . $isChecked('skip', $prevPolicy) . '> <strong>Skip</strong> them entirely <span class="mg-btn-note">(cleaner; reinstall from scratch via the 2.0 admin)</span></label>';
            echo '</div>';

            echo '<div style="margin-top:14px"><button type="submit" class="mg-btn mg-btn-primary">Copy &amp; migrate user/ →</button></div>';

            // Toggle policy section visibility based on mode (test mode has no incompatibles).
            echo '<script>(function(){var f=document.getElementById("mg-pt-form");if(!f)return;f.addEventListener("change",function(e){if(e.target.name!=="mode")return;document.getElementById("mg-pt-policy").hidden=(e.target.value==="test");});})();</script>';

            echo '</form>';
            echo '</div>';
            break;

        case 'plugins_done':
            $acctDir = $stageDirAbs . '/user/accounts';
            $nAccounts = is_dir($acctDir) ? count(array_filter(scandir($acctDir) ?: [], static fn($e) => $e !== '.' && $e !== '..' && preg_match('/\.yaml$/i', $e))) : 0;

            echo '<div class="mg-card mg-card-active"><h3>Step 3: Accounts</h3>';
            echo '<p>Account yamls were copied verbatim into the staged install during Step 2 (' . $nAccounts . ' yaml(s) detected in staged <code>user/accounts/</code>). This step just applies the optional permission transform.</p>';
            echo '<p>Grav 2.0 (with Admin 2.0) uses <code>api.*</code> permission names going forward. Your existing <code>admin.*</code> permissions can be mirrored in place so the same users keep full access on both Admin 1.x and 2.0 while you transition.</p>';
            echo '<form method="post" style="margin-top:14px">';
            echo '<input type="hidden" name="action" value="accounts">';
            echo '<input type="hidden" name="token"  value="' . htmlspecialchars($token) . '">';
            echo '<label class="mg-check mg-check-block"><input type="checkbox" name="skip_perms" value="1"> Skip permission mirroring <span class="mg-btn-note">(leave account yamls as-is; you\'ll set <code>api.*</code> perms manually later)</span></label>';
            echo '<div style="margin-top:14px"><button type="submit" class="mg-btn mg-btn-primary">Apply permission mirror →</button></div>';
            echo '</form>';
            echo '</div>';
            break;

        case 'accounts_done':
            $webroot = dirname($stageDirAbs);
            $dstUser = $stageDirAbs . '/user';
            $handled = ['plugins', 'themes', 'accounts'];
            $present = is_dir($dstUser)
                ? array_values(array_filter(scandir($dstUser) ?: [], static fn($e) => $e !== '.' && $e !== '..' && $e[0] !== '.' && !in_array($e, $handled, true)))
                : [];

            echo '<div class="mg-card mg-card-active"><h3>Step 4: Content</h3>';
            echo '<p>All content under <code>user/</code> (pages, data, config, languages, custom folders, top-level files) was bulk-copied into the staged install during Step 2. Confirm below to continue to testing.</p>';
            echo '<p><strong>Present in staged <code>user/</code>:</strong> ';
            echo $present ? implode(', ', array_map(static fn($e) => '<code>user/' . htmlspecialchars($e) . '/</code>', $present)) : '<em>no additional entries beyond plugins/themes/accounts</em>';
            echo '</p>';
            echo '<form method="post" style="margin-top:14px">';
            echo '<input type="hidden" name="action" value="content">';
            echo '<input type="hidden" name="token"  value="' . htmlspecialchars($token) . '">';
            echo '<button type="submit" class="mg-btn mg-btn-primary">Confirm →</button>';
            echo '</form>';
            echo '</div>';
            break;

        case 'content_done':
            $webroot = dirname($stageDirAbs);
            $serverKind = mg_server_kind();
            $stageUrl = base_path_from_script() . rawurlencode($stageDir) . '/';

            // Auto-patch on Apache/LiteSpeed (idempotent). For nginx/Caddy,
            // skip auto-patch and show manual config. The parent rule
            // exclusion is sufficient; the staged install does NOT need a
            // RewriteBase tweak (it works as-is when the parent stops
            // intercepting).
            $patchResult = null;
            if (in_array($serverKind, ['apache', 'litespeed'], true)) {
                $patchResult = mg_patch_htaccess($webroot, $stageDir);
            }

            echo '<div class="mg-card mg-card-active"><h3>Step 5: Test the staged install</h3>';
            echo '<p>Open the staged Grav 2.0 in a new tab and verify pages, admin login, plugins, and theme behavior. Your live 1.x install at this URL is unchanged.</p>';

            // Test/Permissive-mode report card: list what was force-included
            // so the user knows which plugins to specifically smoke-test.
            $pt = $flag['plugins_themes'] ?? [];
            $ptMode = (string) ($pt['mode'] ?? MG_MODE_STRICT);
            $forced = $pt['force_included'] ?? [];
            if ($ptMode !== MG_MODE_STRICT && !empty($forced)) {
                $modeWord = $ptMode === MG_MODE_TEST ? 'Test' : 'Permissive';
                echo '<div class="mg-callout mg-callout-warn"><i class="mg-i-warn"></i><div>';
                echo '<strong>' . count($forced) . ' plugin(s) force-included by ' . $modeWord . ' mode.</strong> ';
                echo 'These would have been disabled/skipped in Strict mode. Smoke-test these specifically — fatal errors here are expected and informative. ';
                echo '<details style="margin-top:6px"><summary>Force-included list</summary><div class="mg-details-body">';
                $lines = [];
                foreach ($forced as $f) {
                    $lines[] = '<code>' . htmlspecialchars((string)($f['slug'] ?? '')) . '</code>';
                }
                echo '<div class="mg-result-row">' . implode(', ', $lines) . '</div>';
                echo '</div></details>';
                echo '</div></div>';
            }

            if ($patchResult && $patchResult['ok']) {
                echo '<div class="mg-callout mg-callout-ok"><i class="mg-i-info"></i><div>Detected <strong>' . htmlspecialchars(ucfirst($serverKind)) . '</strong>. Patched parent <code>.htaccess</code> so requests under <code>/' . htmlspecialchars($stageDir) . '/</code> bypass the parent install. Reset will restore the original.</div></div>';
            } elseif ($patchResult && !$patchResult['ok']) {
                echo '<div class="mg-callout mg-callout-warn"><i class="mg-i-warn"></i><div>Could not auto-patch <code>.htaccess</code>: ' . htmlspecialchars($patchResult['msg']) . ' &mdash; you may need to add the rule manually (see below).</div></div>';
            } elseif ($serverKind !== 'apache' && $serverKind !== 'litespeed') {
                echo '<div class="mg-callout mg-callout-warn"><i class="mg-i-warn"></i><div>Detected <strong>' . htmlspecialchars(ucfirst($serverKind)) . '</strong>. Auto-patching only works for Apache/LiteSpeed. <strong>Apply the manual config below before testing</strong>, or your test traffic will hit the 1.x install.</div></div>';
            }

            echo '<div style="margin-top:12px"><a class="mg-btn mg-btn-primary" href="' . htmlspecialchars($stageUrl) . '" target="_blank" rel="noopener">Open staged install →</a></div>';

            // Server-specific manual instructions
            echo '<details class="mg-details" style="margin-top:18px"><summary>Using nginx, Caddy, or another server? Manual config snippets</summary>';
            echo '<div class="mg-details-body">';

            $installPath = rtrim(base_path_from_script(), '/');
            $stagePathFull = $installPath . '/' . $stageDir;

            echo '<div class="mg-result-section"><strong>nginx</strong><div class="mg-result-row">Add a location block above your existing Grav location:</div>';
            echo '<pre class="mg-code">location ^~ ' . htmlspecialchars($stagePathFull) . '/ {' . "\n";
            echo '    try_files $uri $uri/ ' . htmlspecialchars($stagePathFull) . '/index.php?$query_string;' . "\n";
            echo "}\n";
            echo 'location ~ ^' . htmlspecialchars($stagePathFull) . "/.+\\.php$ {\n";
            echo "    fastcgi_pass   unix:/run/php/php-fpm.sock;\n";
            echo "    include        fastcgi_params;\n";
            echo "    fastcgi_param  SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n";
            echo '}</pre></div>';

            echo '<div class="mg-result-section"><strong>Caddy v2</strong><div class="mg-result-row">Inside your site block:</div>';
            echo '<pre class="mg-code">handle ' . htmlspecialchars($stagePathFull) . "/* {\n";
            echo '    root * {http.vars.root}' . "\n";
            echo '    php_fastcgi unix//run/php/php-fpm.sock' . "\n";
            echo '    try_files {path} {path}/ ' . htmlspecialchars($stagePathFull) . "/index.php?{query}\n";
            echo '    file_server' . "\n";
            echo '}</pre></div>';

            echo '<div class="mg-result-section"><strong>Apache / LiteSpeed (manual)</strong><div class="mg-result-row">If auto-patch failed, add this <em>before</em> the catch-all rewrite in your <code>.htaccess</code>:</div>';
            echo '<pre class="mg-code">RewriteCond %{REQUEST_URI} !/' . htmlspecialchars($stageDir) . '/  # migrate-grav stage exclusion</pre>';
            echo '</div>';

            echo '</div></details>';

            echo '<form method="post" style="margin-top:18px">';
            echo '<input type="hidden" name="action" value="test_continue">';
            echo '<input type="hidden" name="token"  value="' . htmlspecialchars($token) . '">';
            echo '<button type="submit" class="mg-btn mg-btn-primary">I\'ve tested it — continue to promote →</button>';
            echo '</form>';
            echo '</div>';
            break;

        case 'test_done':
            $webroot = dirname($stageDirAbs);
            $currentVersion = mg_read_defines_version($webroot . '/system/defines.php') ?? ($flag['source']['grav_version'] ?? 'unknown');
            $backupPreview = 'migration-backup-' . $currentVersion . '--' . date('YmdHis') . '.zip';
            echo '<div class="mg-card mg-card-active"><h3>Step 6: Promote to live</h3>';
            echo '<p>Final step. This:</p>';
            echo '<ol class="mg-promote-steps">';
            echo '<li>Zips the existing 1.x install into <code>backup/' . htmlspecialchars($backupPreview) . '</code></li>';
            echo '<li>Deletes the 1.x files from the webroot</li>';
            echo '<li>Moves the staged Grav 2.0 from <code>/' . htmlspecialchars($stageDir) . '/</code> up into the webroot root</li>';
            echo '<li>Removes the empty <code>/' . htmlspecialchars($stageDir) . '/</code> dir</li>';
            echo '<li>Writes a breadcrumb at <code>.migration-complete</code> with the backup path</li>';
            echo '</ol>';
            echo '<div class="mg-callout mg-callout-warn"><i class="mg-i-warn"></i><div><strong>Point of no return.</strong> Your live URL will start serving Grav 2.0 immediately after this step. To revert, you\'d extract the backup zip back into place. Make sure Step 5 testing went well first.</div></div>';
            echo '<form method="post" onsubmit="return confirm(\'Promote Grav 2.0 to live? Existing install will be zipped up to backup/ and then removed from the webroot. Revert requires manually extracting the backup zip.\');">';
            echo '<input type="hidden" name="action" value="promote">';
            echo '<input type="hidden" name="token"  value="' . htmlspecialchars($token) . '">';
            echo '<button type="submit" class="mg-btn mg-btn-primary">Promote to live →</button>';
            echo '</form>';
            echo '</div>';
            break;

        case 'promoted':
            echo '<div class="mg-card mg-card-active"><h3>Migration complete</h3>';
            echo '<p>Grav 2.0 is live. Log into the admin to verify your content and plugins.</p>';
            echo '</div>';
            break;
    }
}

/**
 * Render the rich body for a completed plugins-themes step (used by both the
 * post-action flash callout and the persistent staged-state card).
 */
function mg_render_pt_details(array $pt): void
{
    $mode = (string) ($pt['mode'] ?? MG_MODE_STRICT);
    if ($mode !== MG_MODE_STRICT) {
        echo '<div class="mg-result-section"><strong>Mode</strong>';
        $modeLabel = $mode === MG_MODE_TEST ? 'TEST (everything force-included)' : 'PERMISSIVE (only curated 1.x-only blocked)';
        echo '<div class="mg-result-row"><span class="mg-result-tag mg-tag-warn">' . htmlspecialchars($mode) . '</span> ' . htmlspecialchars($modeLabel) . '</div></div>';
    }

    if (!empty($pt['force_included'])) {
        echo '<div class="mg-result-section"><strong>Force-included (would be incompatible in strict)</strong>';
        $lines = [];
        foreach ($pt['force_included'] as $f) {
            $slug = (string) ($f['slug'] ?? '');
            $reason = (string) ($f['reason'] ?? '');
            $lines[] = '<code>' . htmlspecialchars($slug) . '</code>' . ($reason !== '' ? ' <span class="mg-result-meta">(' . htmlspecialchars($reason) . ')</span>' : '');
        }
        echo '<div class="mg-result-row"><span class="mg-result-tag mg-tag-warn">force</span> ' . implode(', ', $lines) . '</div></div>';
    }

    $stripPrefix = static function (array $list, string $kind): array {
        $out = [];
        foreach ($list as $s) {
            if (str_starts_with($s, $kind . '/')) $out[] = substr($s, strlen($kind) + 1);
        }
        return $out;
    };

    foreach (['plugins' => 'Plugins', 'themes' => 'Themes'] as $kind => $label) {
        $copied   = $pt['copied'][$kind] ?? [];
        $disabled = $stripPrefix($pt['disabled'] ?? [], $kind);
        $skipped  = $stripPrefix($pt['skipped']  ?? [], $kind);
        if (!$copied && !$disabled && !$skipped) continue;

        $disabledSet = array_flip(array_map(static fn($d) => explode(' ', $d, 2)[0], $disabled));
        $enabledOnly = array_values(array_filter($copied, static fn($s) => !isset($disabledSet[$s])));

        echo '<div class="mg-result-section"><strong>' . $label . '</strong>';
        if ($enabledOnly) echo '<div class="mg-result-row"><span class="mg-result-tag mg-tag-ok">copied + enabled</span> ' . mg_render_slug_list($enabledOnly) . '</div>';
        if ($disabled)    echo '<div class="mg-result-row"><span class="mg-result-tag mg-tag-warn">copied + disabled</span> ' . mg_render_slug_list($disabled) . '</div>';
        if ($skipped)     echo '<div class="mg-result-row"><span class="mg-result-tag mg-tag-skip">skipped</span> ' . mg_render_slug_list($skipped) . '</div>';
        echo '</div>';
    }

    if (!empty($pt['upgraded'])) {
        echo '<div class="mg-result-section"><strong>Updated to 2.0-compatible version</strong>';
        $lines = [];
        foreach ($pt['upgraded'] as $label => $info) {
            $lines[] = '<code>' . htmlspecialchars($label) . '</code> <span class="mg-result-meta">(' . htmlspecialchars(($info['from'] ?: '?') . ' → v' . $info['to']) . ')</span>';
        }
        echo '<div class="mg-result-row"><span class="mg-result-tag mg-tag-new">updated</span> ' . implode(', ', $lines) . '</div></div>';
    }
    if (!empty($pt['replacements']['installed'])) {
        echo '<div class="mg-result-section"><strong>Auto-installed</strong>';
        $lines = [];
        foreach ($pt['replacements']['installed'] as $slug => $info) {
            if (is_string($info)) { $lines[] = '<code>' . htmlspecialchars($slug) . '</code>'; continue; }
            $src = $info['source'] ?? '?';
            $tag = $src === 'release' ? ('v' . $info['version'] . ' (github release)')
                 : ($src === 'default-branch' ? ($info['version'] . ' (github default branch)')
                 : ($src === 'gpm' ? ('v' . $info['version'] . ' (gpm)')
                 : ('v' . $info['version'])));
            $lines[] = '<code>' . htmlspecialchars($slug) . '</code> <span class="mg-result-meta">(' . htmlspecialchars($tag) . ')</span>';
        }
        echo '<div class="mg-result-row"><span class="mg-result-tag mg-tag-new">installed</span> ' . implode(', ', $lines) . '</div></div>';
    }
    if (!empty($pt['replacements']['failed'])) {
        echo '<div class="mg-result-section"><strong>Replacement failures</strong>';
        $lines = array_map(static fn($f) => '<code>' . htmlspecialchars($f[0]) . '</code> <span class="mg-result-meta">(' . htmlspecialchars($f[1]) . ')</span>', $pt['replacements']['failed']);
        echo '<div class="mg-result-row"><span class="mg-result-tag mg-tag-err">failed</span> ' . implode(', ', $lines) . '</div></div>';
    }
}

function mg_render_accounts_details(array $ac): void
{
    echo '<div class="mg-result-section"><strong>Account yamls processed</strong>';
    echo '<div class="mg-result-row"><span class="mg-result-tag mg-tag-ok">files</span> ' . (int) ($ac['count'] ?? 0) . '</div>';
    if (!empty($ac['details']) && is_array($ac['details'])) {
        $rows = [];
        foreach ($ac['details'] as $file => $added) {
            $rows[] = '<code>' . htmlspecialchars((string) $file) . '</code> <span class="mg-result-meta">(+' . (int) $added . ' api perms)</span>';
        }
        echo '<div class="mg-result-row" style="margin-top:6px">' . implode(', ', $rows) . '</div>';
    }
    echo '</div>';

    echo '<div class="mg-result-section"><strong>Permission mirror</strong>';
    if (!empty($ac['skipped_perms'])) {
        echo '<div class="mg-result-row"><span class="mg-result-tag mg-tag-skip">skipped</span> No <code>admin.*</code> permissions were mirrored to <code>api.*</code>. Set them manually if needed for Admin 2.0.</div>';
    } else {
        echo '<div class="mg-result-row"><span class="mg-result-tag mg-tag-ok">applied</span> Mirrored ' . (int) ($ac['migrated_perms'] ?? 0) . ' <code>admin.*</code> → <code>api.*</code> permission(s).</div>';
    }
    echo '</div>';
}

function mg_render_rerun_form(string $token, string $target, string $label, string $impact): void
{
    $confirmJs = "return confirm('Re-run? ' + " . json_encode($impact) . " + '\\n\\nThis cannot be undone short of using Reset.');";
    echo '<div class="mg-result-section" style="margin-top:14px">';
    echo '<form method="post" onsubmit="' . htmlspecialchars($confirmJs) . '">';
    echo '<input type="hidden" name="action" value="rerun_step">';
    echo '<input type="hidden" name="target" value="' . htmlspecialchars($target) . '">';
    echo '<input type="hidden" name="token"  value="' . htmlspecialchars($token) . '">';
    echo '<button type="submit" class="mg-btn mg-btn-secondary">↺ ' . htmlspecialchars($label) . '</button>';
    echo ' <span class="mg-btn-note">' . htmlspecialchars($impact) . '</span>';
    echo '</form></div>';
}

function mg_render_content_details(array $c): void
{
    echo '<div class="mg-result-section"><strong>Content in staged <code>user/</code></strong>';
    echo '<div class="mg-result-row"><span class="mg-result-tag mg-tag-ok">top-level entries</span> ' . count($c['entries'] ?? []) . '</div>';
    if (!empty($c['entries'])) {
        $entries = array_map(static fn($e) => '<code>user/' . htmlspecialchars((string) $e) . '/</code>', $c['entries']);
        echo '<div class="mg-result-row" style="margin-top:6px">' . implode(', ', $entries) . '</div>';
    }
    echo '</div>';
}

function mg_render_slug_list(array $slugs): string
{
    return implode(', ', array_map(static fn($s) => '<code>' . htmlspecialchars((string) $s) . '</code>', $slugs));
}

function render_compat_breakdown(array $scan): void
{
    // Collect unique pending replacements — plugins/themes that will be
    // auto-installed even though they aren't in the source install. Shown
    // as their own rows so the user sees "admin2 will be installed" (plus
    // its `requires:` deps like `api`).
    $pendingInstalls = ['plugins' => [], 'themes' => []];
    foreach (['plugins', 'themes'] as $kind) {
        foreach (($scan[$kind] ?? []) as $slug => $v) {
            $replBy = $v['replaced_by'] ?? null;
            if (!$replBy) continue;
            $replEntry = mg_lookup_registry_entry($replBy);
            if (is_array($replEntry) && !empty($replEntry['github_repo'])) {
                $pendingInstalls[$kind][$replBy] = ['entry' => $replEntry, 'kind' => 'replaces', 'target' => $slug];
                // Transitively include anything in `requires:`
                foreach ((array) ($replEntry['requires'] ?? []) as $req) {
                    $reqEntry = mg_lookup_registry_entry($req);
                    if (is_array($reqEntry) && !empty($reqEntry['github_repo']) && !isset($pendingInstalls[$kind][$req])) {
                        $pendingInstalls[$kind][$req] = ['entry' => $reqEntry, 'kind' => 'requires', 'target' => $replBy];
                    }
                }
            }
        }
    }

    $categories = ['plugins' => 'Plugins', 'themes' => 'Themes'];

    foreach ($categories as $k => $label) {
        $items = $scan[$k] ?? [];
        $pending = $pendingInstalls[$k] ?? [];
        if (!$items && !$pending) continue;

        $buckets = ['compatible' => [], 'needs_update' => [], 'incompatible' => []];
        foreach ($items as $slug => $v) {
            $status = $v['status'] ?? 'incompatible';
            $buckets[$status === 'compatible' ? 'compatible' : ($status === 'needs_update' ? 'needs_update' : 'incompatible')][$slug] = $v;
        }

        $total = count($items) + count($pending);
        echo '<div class="mg-compat-group"><h4>' . htmlspecialchars($label) . ' <span class="mg-btn-note">' . $total . ' total</span></h4>';

        // Render pending replacements first under their own subhead so the
        // user sees the "incoming" rows up top.
        $versions = $scan['github_versions'] ?? [];
        if ($pending) {
            echo '<h5 class="mg-compat-subhead mg-compat-subhead-new">Will be installed <span class="mg-compat-subhead-count">' . count($pending) . '</span></h5>';
            echo '<ul class="mg-compat-list">';
            foreach ($pending as $slug => $info) {
                $ver = $versions[$slug] ?? null;
                if ($ver) {
                    $verLabel = match ($ver['source']) {
                        'release'        => 'v' . $ver['version'],
                        'gpm'            => 'v' . $ver['version'],
                        'default-branch' => $ver['version'] . ' (default)',
                        default          => 'v' . $ver['version'],
                    };
                    $sourceKey = $ver['source'] === 'default-branch' ? 'github' : $ver['source'];
                    $sourceKey = $sourceKey === 'release' ? 'github' : $sourceKey;
                    $repoTag = $ver['source'] === 'gpm'
                        ? 'getgrav.org/downloads (gpm)'
                        : (string) ($info['entry']['github_repo'] ?? '');
                } else {
                    $verLabel = 'latest';
                    $sourceKey = 'github';
                    $repoTag = (string) ($info['entry']['github_repo'] ?? '');
                }

                $reasonText = ($info['kind'] ?? 'replaces') === 'requires'
                    ? 'Will be installed (required by <code>' . htmlspecialchars($info['target']) . '</code>)'
                    : 'Will be installed to replace <code>' . htmlspecialchars($info['target']) . '</code>';
                echo '<li class="mg-compat mg-compat-new">';
                echo '<span class="mg-compat-icon">+</span>';
                echo '<span class="mg-compat-slug">' . htmlspecialchars($slug) . '</span>';
                echo ' <span class="mg-compat-version">' . htmlspecialchars($verLabel) . '</span>';
                echo '<span class="mg-compat-reason">' . $reasonText . ' <span class="mg-compat-autoinstall">auto-install</span> <span class="mg-compat-repo"><code>' . htmlspecialchars($repoTag) . '</code></span></span>';
                echo '<span class="mg-compat-source mg-compat-source-' . htmlspecialchars(strtolower($sourceKey)) . '">' . htmlspecialchars(strtoupper($sourceKey)) . '</span>';
                echo '</li>';
            }
            echo '</ul>';
        }

        $bucketMeta = [
            'compatible'   => ['icon' => '✓', 'cls' => 'ok',   'label' => 'Compatible'],
            'needs_update' => ['icon' => '⚠', 'cls' => 'warn', 'label' => 'Needs update'],
            'incompatible' => ['icon' => '✗', 'cls' => 'err',  'label' => 'Incompatible'],
        ];
        foreach ($bucketMeta as $status => $meta) {
            $rows = $buckets[$status];
            if (!$rows) continue;
            echo '<h5 class="mg-compat-subhead mg-compat-subhead-' . $meta['cls'] . '">'
                . htmlspecialchars($meta['label'])
                . ' <span class="mg-compat-subhead-count">' . count($rows) . '</span></h5>';
            echo '<ul class="mg-compat-list">';
            foreach ($rows as $slug => $v) {
                $version = $v['installed_version'] ?? '';
                $reason  = $v['reason'] ?? '';
                $src     = $v['source'] ?? '';
                $replBy  = $v['replaced_by'] ?? null;
                $update  = $v['update'] ?? null;
                $replEntry = $replBy ? mg_lookup_registry_entry($replBy) : null;
                $autoInstall = $replBy && is_array($replEntry) && !empty($replEntry['github_repo']);

                // Themes without explicit 2.0 markers are the norm (almost no
                // theme — and certainly no custom theme — declares 2.0
                // compatibility). They're kept as-is and rely on Grav 2.0's
                // Twig 3 compat layer. Reframe the row so users don't see a
                // scary ✗ next to their working theme.
                $isKeptTheme = ($k === 'themes' && $status === 'incompatible' && !$replBy);
                $rowIcon   = $isKeptTheme ? '⚠' : $meta['icon'];
                $rowCls    = $isKeptTheme ? 'warn' : $meta['cls'];
                $rowReason = $isKeptTheme
                    ? 'Kept — Twig 3 compatibility enabled (verify before promoting)'
                    : $reason;

                echo '<li class="mg-compat mg-compat-' . $rowCls . '">';
                echo '<span class="mg-compat-icon">' . $rowIcon . '</span>';
                echo '<span class="mg-compat-slug">' . htmlspecialchars($slug) . '</span>';
                if ($version !== '') echo ' <span class="mg-compat-version">' . htmlspecialchars($version) . '</span>';
                echo '<span class="mg-compat-reason">' . htmlspecialchars($rowReason);
                if ($update) {
                    echo ' <span class="mg-compat-update">↑ will upgrade to v' . htmlspecialchars($update['to']) . '</span>';
                }
                if ($replBy) {
                    echo ' <span class="mg-compat-autoinstall">→ <code>' . htmlspecialchars($replBy) . '</code></span>';
                }
                echo '</span>';
                $srcKey = strtolower($src) ?: 'default';
                echo '<span class="mg-compat-source mg-compat-source-' . htmlspecialchars($srcKey) . '">' . htmlspecialchars($src) . '</span>';
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
}

function page_header(string $title): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title) . '</title>';
    echo '<style>' . page_css() . '</style></head><body>';
}

function page_footer(): void
{
    echo '<div class="mg-foot">Migration wizard served standalone by <code>migrate.php</code> &middot; provided by the <code>migrate-grav</code> plugin.</div>';
    // Instant click feedback for any non-reset form: swap the button to a
    // spinner + disable it on submit so the page doesn\'t feel dead while
    // the server works. Streaming pages (extract) overwrite this with live
    // progress; this is the fallback for future steps that aren\'t streamed.
    echo '<script>document.addEventListener("submit",function(e){var f=e.target;if(!f||f.tagName!=="FORM")return;var a=f.querySelector(\'[name=action]\');if(a&&a.value==="reset")return;var b=f.querySelector(\'button[type=submit]\');if(b&&!b.disabled){b.dataset.mgOriginal=b.innerHTML;b.innerHTML=\'<span class="mg-spin">⟳</span> Working…\';b.disabled=true;}},true);</script>';
    echo '</body></html>';
}

function page_css(): string
{
    return <<<'CSS'
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f5f9; color: #333; margin: 0; }
    .mg-page { max-width: 920px; margin: 0 auto; padding: 32px 28px 64px; }

    .mg-hero { position: relative; overflow: hidden; margin: 0 0 20px; padding: 28px 32px; border-radius: 8px;
        background: linear-gradient(135deg, #5b3ea8 0%, #7b2ff7 55%, #ff4d8f 100%); color: #fff;
        box-shadow: 0 6px 24px -8px rgba(91, 62, 168, 0.55), 0 2px 4px rgba(0, 0, 0, 0.08); }
    .mg-hero::before { content: ""; position: absolute; top: -80px; right: -80px; width: 280px; height: 280px;
        background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0) 70%); pointer-events: none; }
    .mg-hero-inner { position: relative; display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
    .mg-hero-icon { flex: 0 0 auto; width: 80px; height: 80px; border-radius: 14px;
        background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.22);
        display: flex; align-items: center; justify-content: center; font-size: 38px; color: #fff; }
    .mg-rocket { width: 42px; height: 42px; }
    .mg-hero-text { flex: 1 1 320px; min-width: 0; }
    .mg-hero-text h2 { margin: 0 0 6px; color: #fff; font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
    .mg-hero-version { display: inline-block; margin-left: 10px; padding: 3px 9px; border-radius: 999px;
                       background: rgba(255,255,255,0.18); color: #fff; font-size: 13px; font-weight: 600;
                       letter-spacing: 0; vertical-align: middle; }
    .mg-version-pill { display: inline-block; margin-left: 8px; padding: 2px 8px; border-radius: 999px;
                       background: #eef2ff; color: #4338ca; font-size: 12px; font-weight: 600;
                       vertical-align: middle; }
    .mg-hero-text p  { margin: 0; color: rgba(255,255,255,0.92); font-size: 14px; line-height: 1.55; max-width: 620px; }
    .mg-hero-text code { background: rgba(255,255,255,0.16); padding: 1px 6px; border-radius: 3px; color: #fff; }

    .mg-stepper { display: flex; gap: 0; list-style: none; padding: 0; margin: 0 0 24px; background: #fff;
        border: 1px solid #e6e8ef; border-radius: 6px; overflow: hidden; }
    .mg-step { flex: 1 1 0; display: flex; align-items: center; gap: 8px; padding: 12px 10px;
        font-size: 12.5px; color: #888; border-right: 1px solid #f0f1f6; position: relative;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mg-step:last-child { border-right: 0; }
    .mg-step-dot { width: 24px; height: 24px; border-radius: 50%; display: inline-flex;
        align-items: center; justify-content: center; font-size: 11px; font-weight: 700;
        background: #eef0f8; color: #888; flex: 0 0 auto; }
    .mg-step-current { background: linear-gradient(to right, #f4f0ff, #fff); color: #5b3ea8; }
    .mg-step-current .mg-step-dot { background: #5b3ea8; color: #fff; }
    .mg-step-done { color: #2a7f2a; }
    .mg-step-done .mg-step-dot { background: #dff2df; color: #2a7f2a; }
    .mg-step-label { font-weight: 600; }

    .mg-card { background: #fff; border: 1px solid #e6e8ef; border-radius: 6px;
        padding: 20px 24px; margin: 0 0 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
    .mg-card h3 { margin: 0 0 14px; font-size: 15px; font-weight: 600; color: #333; letter-spacing: 0.2px; }
    .mg-card p  { margin: 0 0 10px; font-size: 14px; line-height: 1.6; color: #555; }
    .mg-card p:last-child { margin-bottom: 0; }
    .mg-card code { background: #f4f4f8; padding: 1px 6px; border-radius: 3px; font-size: 12.5px; color: #333; }
    .mg-card-muted { background: #fafafd; }
    .mg-card-active { border-left: 4px solid #5b3ea8; }
    .mg-card-collapsed h3 + table { font-size: 12.5px; }

    .mg-callout { display: flex; gap: 12px; align-items: flex-start; padding: 14px 18px; margin: 0 0 20px;
        border-radius: 4px; font-size: 13.5px; line-height: 1.55; }
    .mg-callout-ok    { background: #f0faf2; border-left: 4px solid #2a7f2a; color: #1f5a1f; }
    .mg-callout-warn  { background: #fffaf0; border-left: 4px solid #f7a600; color: #5a4a1f; }
    .mg-callout-error { background: #fff1f1; border-left: 4px solid #d94141; color: #762222; }
    .mg-callout code  { background: rgba(0,0,0,0.05); padding: 1px 5px; border-radius: 3px; }
    .mg-i-info, .mg-i-warn { flex: 0 0 auto; width: 18px; height: 18px; border-radius: 50%;
        display: inline-block; position: relative; margin-top: 1px; }
    .mg-i-info { background: #5b3ea8; }
    .mg-i-info::before { content: "i"; position: absolute; inset: 0; color: #fff; text-align: center;
        font-weight: 700; font-size: 12px; line-height: 18px; font-style: italic; }
    .mg-i-warn { background: #d94141; }
    .mg-i-warn::before { content: "!"; position: absolute; inset: 0; color: #fff; text-align: center;
        font-weight: 700; font-size: 12px; line-height: 18px; }

    .mg-table { width: 100%; border-collapse: collapse; }
    .mg-table th, .mg-table td { padding: 8px 12px; border-bottom: 1px solid #f0f1f6; text-align: left; font-size: 13.5px; }
    .mg-table tr:last-child th, .mg-table tr:last-child td { border-bottom: 0; }
    .mg-table th { width: 200px; color: #888; font-weight: 500; }
    .mg-table code { background: #f4f4f8; padding: 1px 6px; border-radius: 3px; font-size: 12.5px; color: #333; }
    .mg-ok   { color: #2a7f2a; font-weight: 600; }
    .mg-fail { color: #c62828; font-weight: 600; }

    .mg-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border: none;
        border-radius: 4px; font-weight: 600; font-size: 13px; cursor: pointer; text-decoration: none;
        transition: background 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease; }
    .mg-btn-primary { background: #5b3ea8; color: #fff; box-shadow: 0 2px 6px rgba(91, 62, 168, 0.25); }
    .mg-btn-primary:not(:disabled):hover { background: #4a328b; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(91, 62, 168, 0.35); }
    .mg-btn-secondary { background: #fff; color: #5b3ea8; border: 1px solid #d8d2ec; }
    .mg-btn-secondary:hover { background: #f5f2fb; color: #4a328b; }
    .mg-btn-danger { background: #fff; color: #c62828; border: 1px solid #e5c2c2; }
    .mg-btn-danger:hover { background: #fff1f1; color: #b72020; }
    .mg-btn:disabled { opacity: 0.55; cursor: not-allowed; }
    .mg-btn-note { font-weight: 400; font-size: 12px; opacity: 0.85; color: #777; }

    .mg-foot { max-width: 920px; margin: 40px auto 0; padding: 20px 28px; border-top: 1px solid #e6e8ef;
        color: #999; font-size: 12px; text-align: center; }
    .mg-foot code { background: #f4f4f8; padding: 1px 5px; border-radius: 3px; }

    .mg-details { display: inline-block; max-width: 100%; }
    .mg-details summary { cursor: pointer; list-style: none; }
    .mg-details summary::-webkit-details-marker { display: none; }
    .mg-details summary::before { content: "▸ "; color: #888; font-size: 11px; margin-right: 2px; }
    .mg-details[open] summary::before { content: "▾ "; }
    .mg-details-body { margin-top: 10px; padding: 10px 12px; background: #fafafd;
        border: 1px solid #eef0f6; border-radius: 4px; }
    .mg-result-section { margin: 0 0 10px; }
    .mg-result-section:last-child { margin-bottom: 0; }
    .mg-result-section strong { display: block; font-size: 12px; color: #888; text-transform: uppercase;
        letter-spacing: 0.5px; margin-bottom: 4px; font-weight: 600; }
    .mg-result-row { font-size: 13px; line-height: 1.7; color: #444; padding: 2px 0; }
    .mg-result-row code { background: #fff; padding: 1px 6px; border-radius: 3px; font-size: 12px;
        border: 1px solid #eef0f6; }
    .mg-result-tag { display: inline-block; padding: 1px 7px; margin-right: 6px; font-size: 10.5px;
        font-weight: 600; letter-spacing: 0.4px; text-transform: uppercase; border-radius: 3px;
        vertical-align: 1px; }
    .mg-tag-ok   { background: #dff2df; color: #2a7f2a; }
    .mg-tag-warn { background: #fff3d6; color: #b3741a; }
    .mg-tag-skip { background: #eef0f8; color: #777; }
    .mg-tag-new  { background: #ede6ff; color: #5b3ea8; }
    .mg-tag-err  { background: #fde0e0; color: #c62828; }
    .mg-result-meta { color: #999; font-size: 12px; }

    .mg-code { background: #1f1f2b; color: #e9e9f0; padding: 12px 14px; margin: 6px 0 10px;
        border-radius: 4px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 12.5px; line-height: 1.5; overflow-x: auto; white-space: pre; }

    .mg-flash-details { display: block; margin-top: 10px; }
    .mg-flash-details summary { font-size: 12.5px; color: inherit; opacity: 0.85; }
    .mg-flash-details .mg-details-body { background: rgba(255,255,255,0.55); border-color: rgba(0,0,0,0.06); }

    .mg-promote-steps { padding-left: 22px; margin: 6px 0 14px; font-size: 13.5px; color: #444; line-height: 1.65; }
    .mg-promote-steps li { padding: 2px 0; }
    .mg-promote-steps code { background: #f4f4f8; padding: 1px 6px; border-radius: 3px; font-size: 12.5px; }

    .mg-spin { display: inline-block; line-height: 1; transform-origin: 50% 50%;
        animation: mg-spin 1.1s linear infinite; }
    @keyframes mg-spin { to { transform: rotate(360deg); } }

    /* Dedicated hero spinner — CSS-drawn circle with a bright top arc.
       Avoids the off-center problem the ⟳ glyph had (non-uniform visual weight
       in the unicode bounding box). Perfectly symmetric, so rotation is clean. */
    .mg-spin-ring { display: block; width: 44px; height: 44px; border-radius: 50%;
        border: 4px solid rgba(255,255,255,0.22); border-top-color: #fff;
        animation: mg-spin 1s linear infinite; }

    .mg-progress-wrap { background: #eef0f8; height: 10px; border-radius: 5px; overflow: hidden; margin: 4px 0 10px; }
    .mg-progress-bar  { width: 0; height: 100%; background: linear-gradient(to right, #5b3ea8, #ff4d8f);
        transition: width 0.2s ease; }
    .mg-progress-text { font-size: 12.5px; color: #666; margin: 0 0 6px; }
    .mg-progress-text code { background: #f4f4f8; padding: 1px 6px; border-radius: 3px; color: #333; font-size: 12px; }

    .mg-log { list-style: none; padding: 0; margin: 12px 0 0; font-size: 13px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: #555; }
    .mg-log li { padding: 3px 0; }
    .mg-log-done { color: #2a7f2a; }
    .mg-log-skip { color: #999; }

    .mg-check { display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; color: #444; cursor: pointer; }
    .mg-check input { margin: 0; }
    .mg-check-block { display: flex; padding: 10px 12px; margin: 4px 0; border: 1px solid #e6e8ef; border-radius: 4px; }
    .mg-check-block:hover { background: #fafafd; }

    .mg-compat-group { margin: 14px 0 6px; }
    .mg-compat-group h4 { margin: 0 0 8px; font-size: 14px; color: #333; }
    .mg-compat-subhead { margin: 14px 0 6px; font-size: 12px; font-weight: 600;
        text-transform: uppercase; letter-spacing: 0.6px; color: #6b7280;
        display: flex; align-items: center; gap: 8px; }
    .mg-compat-subhead::before { content: ""; flex: 0 0 3px; height: 14px; border-radius: 2px;
        background: #c8ccd6; }
    .mg-compat-subhead-count { display: inline-flex; align-items: center; justify-content: center;
        min-width: 22px; height: 18px; padding: 0 6px; border-radius: 9px;
        background: #eef0f6; color: #4b5563; font-size: 11px; font-weight: 700;
        letter-spacing: 0; text-transform: none; }
    .mg-compat-subhead-ok::before   { background: #2a7f2a; }
    .mg-compat-subhead-warn::before { background: #c47d00; }
    .mg-compat-subhead-err::before  { background: #c62828; }
    .mg-compat-subhead-new::before  { background: #5b3ea8; }
    .mg-compat-subhead-ok .mg-compat-subhead-count   { background: #e3f4e3; color: #1f5a1f; }
    .mg-compat-subhead-warn .mg-compat-subhead-count { background: #fff1d6; color: #80560a; }
    .mg-compat-subhead-err .mg-compat-subhead-count  { background: #fde2e2; color: #8c2020; }
    .mg-compat-subhead-new .mg-compat-subhead-count  { background: #ece5fa; color: #4a328b; }
    .mg-compat-list { list-style: none; padding: 0; margin: 0 0 4px; border: 1px solid #eef0f6;
        border-radius: 4px; overflow: hidden; }
    .mg-compat { display: grid; grid-template-columns: 22px 190px 80px 1fr 96px; gap: 10px;
        align-items: center; padding: 9px 12px; border-bottom: 1px solid #f3f4f8; font-size: 13px; }
    .mg-compat:last-child { border-bottom: 0; }
    .mg-compat-icon { font-weight: 700; text-align: center; font-size: 13px; }
    .mg-compat-slug { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: #1f2937;
        font-weight: 600; word-break: break-all; }
    .mg-compat-version { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: #6b7280;
        font-size: 12px; }
    .mg-compat-reason { color: #4b5563; font-size: 12.5px; }
    .mg-compat-source { display: inline-block; padding: 2px 8px; border-radius: 10px;
        background: #eef0f6; color: #4b5563; font-size: 10.5px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.5px; text-align: center;
        justify-self: end; min-width: 76px; }
    .mg-compat-source-curated   { background: #ece5fa; color: #4a328b; }
    .mg-compat-source-blueprint { background: #e0ecff; color: #1d4ed8; }
    .mg-compat-source-inferred  { background: #d8f1ee; color: #0f766e; }
    .mg-compat-source-default   { background: #eef0f6; color: #6b7280; }
    .mg-compat-source-github    { background: #1f2937; color: #f3f4f6; }
    .mg-compat-source-gpm       { background: #d1f0d6; color: #1f5a1f; }
    .mg-compat-ok   { background: #f6fcf6; } .mg-compat-ok   .mg-compat-icon { color: #2a7f2a; }
    .mg-compat-warn { background: #fffaf0; } .mg-compat-warn .mg-compat-icon { color: #c47d00; }
    .mg-compat-err  { background: #fff5f5; } .mg-compat-err  .mg-compat-icon { color: #c62828; }
    .mg-compat-new  { background: linear-gradient(to right, #f4efff, #fff); border-left: 3px solid #5b3ea8; }
    .mg-compat-new .mg-compat-icon { color: #5b3ea8; font-size: 16px; }
    .mg-compat-new .mg-compat-slug { color: #5b3ea8; }
    .mg-compat-autoinstall { display: inline-block; margin-left: 6px; padding: 1px 7px;
        background: #5b3ea8; color: #fff; font-size: 11px; font-weight: 600; border-radius: 3px;
        letter-spacing: 0.3px; }
    .mg-compat-autoinstall code { background: rgba(255,255,255,0.18); color: #fff; padding: 0 4px;
        border-radius: 2px; font-size: 11px; }
    .mg-compat-update { display: inline-flex; align-items: center; margin-left: 6px;
        padding: 1px 8px 1px 7px; background: #ecf7ec; color: #1f5a1f;
        border: 1px solid #b8dcb8; font-size: 11px; font-weight: 600; border-radius: 10px;
        letter-spacing: 0.2px; line-height: 1.55; }
    .mg-compat-repo { display: inline-block; margin-left: 6px; color: #888; font-size: 12px; }
    .mg-compat-repo code { background: transparent; padding: 0; color: #888; font-size: 12px; }
    @media (max-width: 700px) {
      .mg-compat { grid-template-columns: 22px 1fr auto; }
      .mg-compat-reason, .mg-compat-source { grid-column: 1 / -1; padding-left: 32px; }
      .mg-compat-source { justify-self: start; }
    }

    .mg-table-compact th, .mg-table-compact td { padding: 6px 10px; }
    .mg-table-compact th { width: 170px; }
CSS;
}
