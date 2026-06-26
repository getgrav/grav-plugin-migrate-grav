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
source_local_zip: ''        # absolute path to a manually-downloaded 2.0 zip
stage_dir: 'grav-2'
require_super_admin: true
```

### Shared hosting / blocked downloads

The kickoff downloads the Grav 2.0 zip from `source_url`. It uses PHP's URL stream
wrapper when `allow_url_fopen` is enabled and falls back to **cURL** when it isn't — so
most shared hosts work out of the box. If a host disables *both* `allow_url_fopen` and the
cURL extension, or blocks outbound HTTPS to `getgrav.org` entirely, the admin page will say
so before you stage and the download will fail with a clear message. The fix: download the
Grav 2.0 release zip yourself, upload it to the server, and set `source_local_zip` to its
absolute path. When `source_local_zip` is set the kickoff skips the download completely.

## Twig in content

Grav 2.0 changed how editor-authored Twig (Twig inside page content) is secured: the
`security.twig_content` gate is off by default, a sandbox restricts what content Twig can
do, and the blanket `undefined_functions` escape hatch was removed — an unlisted Twig
function or filter is now a hard error. The `safe_functions` / `safe_filters` allow-lists
are retained (and hardened: command/code-execution functions can never be enabled). The
migration tries to preserve your 1.x behavior:

- It turns the `security.twig_content` gate back on when your source site used Twig in
  content (per-page `process: twig: true` or the site-wide `system.yaml` opt-ins). The
  site-wide opt-ins are honored whether they live in `user/config/system.yaml` or in an
  environment override under `user/env/<host>/config/system.yaml`.
- It scans your Twig-enabled page content for the functions/filters it calls — every page
  body when Twig was enabled site-wide, or just the per-page `process: twig: true` pages
  otherwise. **Raw PHP
  functions** (e.g. `strtoupper`) are added to `system.twig.safe_functions` /
  `safe_filters` (so they're callable at all) **and** to the
  `security.twig_sandbox.allowed_functions` / `allowed_filters` lists (so sandboxed content
  may call them). Your existing `safe_functions` entries are preserved and merged in.
- **Plugin-provided Twig functions** (e.g. `unite_gallery`) are added to the sandbox
  allow-list, but the providing plugin must still register them — ideally via the
  `onBuildTwigSandboxPolicy` event. These are listed in the migration report.
- **Object method calls** (e.g. the image idiom
  `{{ page.media['x.jpg'].lightbox().cropResize(…).html()|raw }}`) are scanned too. The
  documented media chain (`lightbox`, `cropResize`, `cropZoom`, `resize`, `quality`,
  `grayscale`, …) is allow-listed by Grav 2.0 core out of the box (getgrav/grav#4164), so
  the migrator recognises it as already covered and does **not** re-add it. Only media or
  object methods your content uses that 2.0 defaults don't already permit are seeded into
  `security.twig_sandbox.allowed_methods`; method tokens that can't be mapped to a known
  class are listed in the report for you to allowlist by hand.
- Functions Grav 2.0 refuses — `Utils::isDangerousFunction()` (`system`, `exec`,
  `preg_replace`, …) and the sandbox's by-design exclusions (`constant`, `read_file`,
  `evaluate`, …) — are never added; the report lists them so you know those usages need
  reworking.

**What it can't always detect automatically:** custom **object methods and properties** on
classes the migrator doesn't know about (for example a plugin object's `{{ thing.render() }}`)
can't be mapped by a static scan, because the object's class isn't known until runtime. Grav
2.0 already allowlists the common page, media, config, and user classes (the full documented
media chain included), so most content keeps working. If something still renders as raw Twig
(or shows a sandbox placeholder) after migration, open **Tools → Reports → "Twig in Content"**
in Admin: it lists every page still leaking raw Twig and every sandbox block with a one-click
**Add to allowlist**, and its **Scan content** action finds anything not yet exercised. (The
same events are still written to `logs/security.log`.) For plugin-provided members, the
durable fix is to update the providing plugin to a 2.0 version that registers its safe Twig
members via the `onBuildTwigSandboxPolicy` event.

The allowlists written to `user/config/security.yaml` are the **full** lists (core defaults
plus your additions) on purpose: the flat lists (`allowed_functions`/`allowed_filters`/
`allowed_tags`) are replaced wholesale and the per-class lists (`allowed_methods`/
`allowed_properties`) merge by position, so a partial override would drop core defaults. If
you prune an entry, leave the rest intact.

## URL-based image actions

Grav 1.7 applied image transforms straight from the query string —
`image.jpg?cropResize=300,200` resized on the fly with no gate. Grav 2.0 moved that behind
the new `system.images.url_actions` toggle (**off by default**), because those actions run
with arguments an unauthenticated visitor controls. The normal, developer-driven path is
unaffected: a Twig/Markdown media call like `page.media['x'].cropResize(300,200)`, or a
Markdown image whose file is the page's own media (`![](x.jpg?cropResize=300,200)`), is
resolved through the media object at render time into a hashed cache URL with no query
string — it never touches this toggle.

The migration scans your content for the query-string form that *does* bypass the media
object — absolute or rooted paths, `theme://`/`image://` stream paths, references to files
that aren't the page's media, and anything hand-written in a theme template — and turns
`system.images.url_actions` on in the staged `user/config/system.yaml` when it finds any, so
those images keep transforming after migration. Co-located Markdown media references (the
common case) are recognised and left alone, so the toggle is not flipped on needlessly.
External and protocol-relative URLs (`https://cdn.example.com/x.jpg?…`, `//host/x.jpg?…`)
are skipped too — those are served by the remote host, so a CDN's own `?format=webp` query
can't be mistaken for a Grav image action.

If a flagged transform requests an image larger than `system.images.max_pixels`
(25,000,000px by default), Grav still refuses it even with the toggle on — the report calls
those out so you can raise the ceiling or rework them.

## Carrying over root files and custom .htaccess rules

A fresh Grav 2.0 install ships its own webroot — it won't have files you added at the root of your 1.x site. The **promote** step (Step 6) detects these and lets you opt in to carrying them forward:

- **Root files & folders.** Any top-level entry that isn't part of Grav itself (a custom `robots.txt`, `favicon.ico`, `.well-known/`, ownership-verification files, custom upload folders, …) is offered as a checklist. Grav-managed entries (`system/`, `vendor/`, `user/`, `index.php`, `composer.json`, …) are never offered, since carrying them would clobber the new install. A ticked entry replaces any 2.0 default of the same name. Everything is also captured in the backup zip regardless.
- **Custom `.htaccess` rules.** The wizard diffs your live `.htaccess` against the stock Grav template (`webserver-configs/htaccess.txt`) and shows the lines that look like your own additions — custom redirects, `Header` directives, an uncommented `RewriteBase`, `ErrorDocument`, caching blocks, etc. Review and edit them in the textarea (some lines may be Grav's own rules from a different point release rather than your customizations — remove anything that isn't yours), tick the box, and they're spliced into the new `.htaccess` inside a clearly marked `# BEGIN/END migrate-grav` block, placed right after `RewriteEngine On`. Both are off by default — nothing is carried unless you opt in.

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
