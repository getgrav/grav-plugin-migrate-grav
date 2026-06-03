# Migrate to Grav 2.0

Stages a fresh Grav 2.0 install alongside your existing Grav 1.7 or 1.8 site
and hands off to a standalone migration wizard. The plugin itself does **not**
perform the migration — it exists solely to download Grav 2.0, drop a
self-contained `migrate.php` at your webroot, and get out of the way so the
wizard can run in a fresh PHP process with no 1.x code loaded.

## Why a standalone handoff?

In-place upgrades from 1.x → 2.0 are not safe: the vendor stacks differ, file
locks and opcache pinning can corrupt mid-upgrade state, and any failure
leaves an unbootable site. This plugin's job is to make the *handoff*
boring: download, drop, redirect. Everything risky happens later, in a
process that has no relationship to your running 1.x install.

## Requirements

- Grav 1.7.50+ or 1.8.x
- Write access to your webroot and `tmp/` directory
- PHP 7.3.6+ (for the kickoff itself; the 2.0 wizard requires PHP 8.3+)

## Installation

```bash
bin/gpm install migrate-grav
```

## Usage

### From the admin

Click **Migrate to Grav 2.0** in the sidebar. Press the staging button. Your
browser will be redirected to `/migrate.php` and you'll be running the wizard
outside of Grav 1.x.

### From the CLI

```bash
bin/plugin migrate-grav init
```

Then follow the printed instructions to start the wizard in a fresh PHP
process (either `php migrate.php` or by visiting the URL).

### Status

```bash
bin/plugin migrate-grav status
```

## Configuration

`user/config/plugins/migrate-grav.yaml`:

```yaml
enabled: true
source_url: 'https://getgrav.org/download/core/grav-update/2.0.0-beta.1?testing'
source_local_zip: ''        # absolute path to a local 2.0 zip (dev only)
stage_dir: 'grav-2'
require_super_admin: true
```

## Twig in content

Grav 2.0 changed how editor-authored Twig (Twig inside page content) is secured: the
`security.twig_content` gate is off by default, a sandbox restricts what content Twig can
do, and the blanket `undefined_functions` escape hatch was removed — an unlisted Twig
function or filter is now a hard error. The `safe_functions` / `safe_filters` allow-lists
are retained (and hardened: command/code-execution functions can never be enabled). The
migration tries to preserve your 1.x behavior:

- It turns the `security.twig_content` gate back on when your source site used Twig in
  content (per-page `process: twig: true` or the site-wide `system.yaml` opt-ins).
- It scans your Twig-enabled page content for the functions/filters it calls. **Raw PHP
  functions** (e.g. `strtoupper`) are added to `system.twig.safe_functions` /
  `safe_filters` (so they're callable at all) **and** to the
  `security.twig_sandbox.allowed_functions` / `allowed_filters` lists (so sandboxed content
  may call them). Your existing `safe_functions` entries are preserved and merged in.
- **Plugin-provided Twig functions** (e.g. `unite_gallery`) are added to the sandbox
  allow-list, but the providing plugin must still register them — ideally via the
  `onBuildTwigSandboxPolicy` event. These are listed in the migration report.
- Functions Grav 2.0 refuses — `Utils::isDangerousFunction()` (`system`, `exec`,
  `preg_replace`, …) and the sandbox's by-design exclusions (`constant`, `read_file`,
  `evaluate`, …) — are never added; the report lists them so you know those usages need
  reworking.

**What it can't detect automatically:** custom **object methods and properties** used in
content Twig (for example a plugin object's `{{ thing.render() }}`) can't be found by a
static scan, because the object's class isn't known until runtime. Grav 2.0 already
allowlists the common page, media, config, and user classes, so most content keeps working.
If something still renders as raw Twig (or shows a sandbox placeholder) after migration,
check `logs/security.log`, then either add the class/method to
`security.twig_sandbox.allowed_methods` by hand or — better — update the providing plugin to
a 2.0 version that registers its safe Twig members via the `onBuildTwigSandboxPolicy` event.

The allowlists written to `user/config/security.yaml` are the **full** lists (core defaults
plus your additions) on purpose: Grav merges these lists by index, so a partial override
would corrupt the core defaults. If you prune an entry, leave the rest intact.

## Aborting

If you want to start over before launching the wizard, remove:

- `.migrating` at your webroot
- The staged subdirectory (default: `grav-2/`)
- `tmp/grav-2.0-staged.zip`

Your existing Grav 1.x site is untouched.

## Recovering from a failed promote

The promote step is the only point where the wizard touches your live webroot. It runs in three phases:

1. **Phase 1 — backup zip.** Every file in your live 1.x install (except the staged `grav-2/`) is zipped to `grav-2/backup/migration-backup-<version>--<timestamp>.zip`. After promote this lands at `backup/<…>.zip` next to Grav's other backups.
2. **Phase 2 — delete.** Top-level entries at the webroot are removed.
3. **Phase 3 — promote.** Contents of `grav-2/` are renamed up to the webroot.

If Phase 2 or Phase 3 fails partway through, your live webroot may be partially destroyed. The backup zip from Phase 1 is your recovery artifact.

**Before you retry, identify and free the lock.** The most common failure (especially on Windows, where open files can't be deleted) is a code editor, git GUI, or terminal holding a file handle on something inside your webroot — `.git/index`, `.git/objects/pack/*.idx`, a `.log` being tailed. The wizard will now report the specific path that failed; close whatever has it open.

**To restore from the backup zip:**

- **Windows:** in File Explorer, right-click the zip → **Extract All…** and pick your webroot. The Extract All wizard reconstructs the directory tree correctly. 7-Zip and WinRAR also work fine.
- **macOS:** double-click in Finder (Archive Utility extracts a proper tree), or `unzip migration-backup-*.zip -d /path/to/webroot` from Terminal.
- **Linux:** `unzip migration-backup-*.zip -d /path/to/webroot`.

Once the webroot is restored, follow the **Aborting** steps above to clear the wizard state, then re-run the wizard from the admin.

### "The zip extracts as flat files with `·` or `\` in their names"

If you ran the wizard on **Windows** with a version **prior to 1.0.0-rc.3**, the backup zip it created has a separator bug — entry names use `\` (Windows path separator) instead of `/` (zip spec). Every standards-tolerant extractor (7-Zip, Archive Utility, Windows Explorer's in-place viewer) treats the backslashes as literal filename characters and dumps every file in the zip's root with names like `user\plugins\admin\file.php` (or, in some viewers, `user·plugins·admin·file.php`).

To repair such a zip, copy `user/plugins/migrate-grav/wizard/mg-repair-backup.php` from this plugin to any directory and run:

```
php mg-repair-backup.php migration-backup-1.7.x--20260507111032.zip
```

It writes `migration-backup-1.7.x--20260507111032.fixed.zip` next to the original with all entry names normalized to forward slashes. Extract the fixed zip with any tool and the directory tree will be correct.

The script is self-contained — no Grav, no Composer, no plugin context. It just needs PHP 8.1+ with the `zip` extension. Backup-zip writes from 1.0.0-rc.3 onward no longer have this bug regardless of OS.

## License

MIT
