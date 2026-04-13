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
bin/gpm install migrate-to-2
```

## Usage

### From the admin

Click **Migrate to Grav 2.0** in the sidebar. Press the staging button. Your
browser will be redirected to `/migrate.php` and you'll be running the wizard
outside of Grav 1.x.

### From the CLI

```bash
bin/plugin migrate-to-2 init
```

Then follow the printed instructions to start the wizard in a fresh PHP
process (either `php migrate.php` or by visiting the URL).

### Status

```bash
bin/plugin migrate-to-2 status
```

## Configuration

`user/config/plugins/migrate-to-2.yaml`:

```yaml
enabled: true
source_url: 'https://getgrav.org/download/core/grav-update/latest'
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

## License

MIT
