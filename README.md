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

- **Windows:** in File Explorer, right-click the zip → **Extract All…** and pick your webroot. **Do not** drag entries out of File Explorer's in-place zip viewer — that view shows a flat breadcrumb list (`system·src·Grav·…`) and dragging out produces files with literal `·` separators in their names. The Extract All wizard reconstructs the directory tree correctly. 7-Zip and WinRAR also work fine.
- **macOS:** double-click in Finder (Archive Utility extracts a proper tree), or `unzip migration-backup-*.zip -d /path/to/webroot` from Terminal.
- **Linux:** `unzip migration-backup-*.zip -d /path/to/webroot`.

Once the webroot is restored, follow the **Aborting** steps above to clear the wizard state, then re-run the wizard from the admin.

## License

MIT
