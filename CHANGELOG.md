# v1.0.5
## 07-02-2026

1. [](#bugfix)
    * **Migrated accounts keep the admin language they were using before.** Classic admin stored each user's language on their account, but Admin 2.0 reads it from a different place, so the accounts step now copies that preference across instead of letting everyone fall back to the site default. [grav-plugin-admin2#98]
    * **A version-controlled webroot keeps its `.git` repo through the migration.** The promote step deleted every top-level entry except the staged install when swapping Grav 2.0 in, which wiped a `.git` (or `.svn`/`.hg`) directory at the webroot root — so a site deployed via git lost its repository and history, with no way to opt out since VCS folders were never shown in the carry-forward list. Promote now leaves version-control metadata in place (neither backed up nor deleted), so your repo survives and the new 2.0 files simply show up as changes to review and commit; the promote screen also notes when it detects a repo. [#15]

# v1.0.4
## 07-02-2026

1. [](#bugfix)
    * **The in-process plugin installer now works on hosts with `allow_url_fopen` disabled.** The 1.0.3 fallback that installs each plugin's 2.0 release when `proc_open()` is blocked still fetched every URL with PHP's stream wrapper, so on restrictive shared hosts that also disable `allow_url_fopen` (a common pairing) the curated registry, the GPM index, the GitHub release lookup, and the plugin/theme zip downloads all silently returned nothing — admin2/api never installed and flex-objects couldn't upgrade, so the promote guard correctly blocked with "missing required plugins" and the migration dead-ended. Every remote fetch in the wizard now falls back to cURL when `allow_url_fopen` is off, mirroring the staging download that already did — the same transport Grav's own GPM uses, so a host with a working admin/GPM can now download here too. [#14]
    * **flex-objects now upgrades to its 2.0 release even when the GPM index is unreachable.** The baseline registry pointed flex-objects at `getgrav/grav-plugin-flex-objects`, but the repo lives under `trilbymedia` — the wrong path 404s, so the GitHub fallback dropped to an untagged default-branch zip (version sentinel `1.0.0`) that then failed the required v1.4.0+ floor and blocked the promote. The primary path (the getgrav.org GPM release zip) is unchanged and preferred; the registry owner is corrected so the GitHub fallback also resolves a real tagged release when the GPM index can't be fetched. [#14]
    * **The pre-flight check now hard-fails up front when the host truly can't download.** `proc_open` and `allow_url_fopen` each have a working fallback, so neither alone blocks a migration; the one condition that genuinely can't complete is when *both* `allow_url_fopen` is off *and* the PHP `curl` extension is missing — there's then no way out to fetch the Grav 2.0 zip or the required 2.0 plugins. Step 1 now surfaces this as a red, blocking check with the reason and concrete remedies (enable `curl` or `allow_url_fopen`, run `bin/plugin migrate-grav init` from the CLI, or stage the zip and plugins by hand), instead of letting the migration proceed and silently dead-end at promote. [#14]

# v1.0.3
## 06-26-2026

1. [](#bugfix)
    * **Plugins now upgrade to their Grav 2.0 versions even when `proc_open()` is disabled.** On shared hosts that block shell access the wizard falls back to an in-process installer that downloads each plugin's 2.0 release directly, instead of skipping the upgrade. [#13]
    * **Required plugins like Flex Objects are no longer disabled during migration.** Stock dependencies a working admin needs are force-upgraded to their 2.0 release and never disabled, so the site stays usable after promoting. [#13]
    * **The promote step now refuses to swap in a broken site.** If a required plugin is missing or disabled in the staged install (usually a failed download), the wizard blocks the promote with a clear list of what to fix and leaves your live 1.x site untouched, with an explicit override available. [#13]
    * **Plugin and theme downloads now retry on transient network errors.** A single dropped connection no longer turns into a failed plugin install. [#13]

# v1.0.2
## 06-25-2026

1. [](#new)
    * **The image-manipulation chain in page content now keeps working after migration, with the Twig sandbox left enabled.** The content scan that widens `security.twig_sandbox` only looked at function and filter calls, never object **method** calls, so the standard media idiom `{{ page.media['x.jpg'].lightbox().cropResize(…).html()|raw }}` broke after an otherwise-clean migration. The scanner now also captures `obj.method()` tokens; the documented media chain is already allow-listed by Grav 2.0 core on the `Medium` class, so the migrator recognises it as covered and only seeds object methods your content uses that 2.0 defaults don't already permit (writing the complete per-class list so the by-position merge never drops a default). Anything it can't map to a class is flagged in the report. [#11]
    * **Twig-in-content allowlist suggestions now match the Admin "Twig in Content" report exactly.** The migrator shares Grav 2.0's content-token extractor, so the functions, filters, and methods it seeds at migration time are the same ones the new **Tools → Reports → "Twig in Content"** screen finds; the migration summary points there for follow-up, where any page still leaking raw Twig is listed and blocked members can be allow-listed in one click.
    * **Custom root files and folders can be carried into the new install.** A fresh Grav 2.0 webroot doesn't include files you added at the root of your 1.x site (a custom `robots.txt`, `favicon.ico`, `.well-known/`, verification files, custom folders). The promote step now lists the top-level entries that aren't part of Grav itself and lets you tick the ones to bring forward; Grav-managed entries (`system/`, `vendor/`, `user/`, `index.php`, `composer.json`/`.lock`, …) are never offered, and a ticked entry replaces any 2.0 default of the same name. Off by default. [#9]
    * **Custom `.htaccess` rules can be carried into the new install.** The promote step diffs your live `.htaccess` against the stock Grav template, recognises Grav's own rules (so version drift in the security blocks isn't mistaken for your edits) and keeps custom `<IfModule>` guards intact, then shows the detected custom lines in an editable textarea for review. When you opt in, they're spliced into the new `.htaccess` inside a marked `# BEGIN/END migrate-grav` block placed right after `RewriteEngine On`. Off by default. [#10]

2. [](#bugfix)
    * **Staging no longer fails with "Failed to open source URL" on shared hosting.** The kickoff downloaded the Grav 2.0 zip with PHP's URL stream wrapper, which fails outright when `allow_url_fopen` is disabled — common on locked-down shared hosting — producing only a generic error. The download now falls back to cURL when `allow_url_fopen` is off, the Migrate Grav admin page checks up front whether the host can fetch the release at all (and disables staging with a clear explanation when it can't), and the failure messages point at the `source_local_zip` escape hatch (download the zip manually, set its path, and the download is skipped entirely). [#12]

# v1.0.1
## 06-24-2026

1. [](#bugfix)
    * **An environment-scoped Twig-in-content opt-in is no longer lost, so Twig keeps working after migrating.** The scanner that re-opens Grav 2.0's default-off `security.twig_content` gate only read the top-level `user/config/system.yaml`, so a site that enabled `pages.process.twig` (or `pages.frontmatter.process_twig`) in an environment override at `user/env/<host>/config/system.yaml` migrated with the gate left closed — every `{{ ... }}` and `{% ... %}` in page content stopped rendering, with no clue in `logs/security.log` because content Twig was never even requested. The scanner now reads the top-level config and every environment override, honours the opt-in found in any of them, unions each file's `system.twig.safe_functions`/`safe_filters` allowlist, and strips the now-redundant legacy flag from each file that carried it so the 2.0 gate stays the single source of truth.
    * **A site that enabled Twig in content site-wide now keeps its custom functions (e.g. `unite_gallery`) after migrating.** The pass that seeds the 2.0 Twig sandbox allowlist from the functions and filters a page actually uses only ran on pages carrying their own per-page `process: twig: true` flag, so a site that relied on the site-wide `system.pages.process.twig` opt-in had every page skipped — the gate opened but the sandbox stayed empty and calls like `unite_gallery(...)` were silently blocked. When the source enabled content Twig site-wide, the scanner now collects sandbox tokens from every page body, so those functions are carried into the migrated install's allowlist (plugin-provided ones are still called out in the report for the plugin to register).
    * **A custom base URL no longer breaks the staged preview with `ERR_TOO_MANY_REDIRECTS`.** When the source site set `system.custom_base_url` (e.g. to pin email links to a trusted host), that value was copied verbatim into the staged install. But the staged install runs under a subpath (`stage_dir`, default `/grav-2/`), and a base URL with no matching path forces Grav's `root_path` to empty — so the home/canonical redirect loops and the preview fails to load. The migration now temporarily blanks `custom_base_url` in the staged `system.yaml` so the preview loads, and restores the original value automatically during promote, once the install is back at the original webroot where the setting is correct again. An operator who deliberately re-sets it during the preview is never overwritten. [#8]

# v1.0.0
## 06-20-2026

1. [](#new)
    * Version 1.0 stable release for Grav 2.x

# v1.0.0-rc.7
## 06-19-2026

1. [](#bugfix)
    * **A plugin or theme that declares Grav 2.0 support in its own `blueprints.yaml` is now trusted when it isn't in the curated registry.** The compatibility scan already preferred the curated registry and fell back to the blueprint, but the blueprint reader parsed YAML with a narrow hand-rolled regex whenever PHP's `ext-yaml` was absent (the common case), and that regex only matched the inline flow style `grav: ['1.7', '2.0']` — it silently ignored the standard block-list style. A one-off or private plugin that had been tested and marked compatible with the conventional `compatibility:` / `grav:` block list was therefore reported as "Assumed 1.7-only (no explicit 2.0 compatibility)". [#7]
2. [](#improved)
    * The wizard now reads every YAML file through one parser — Symfony Yaml, loaded from the existing install's `vendor/` that sits right beside `migrate.php` — instead of falling back to `ext-yaml` or a hand-rolled regex. The regex understood only a subset of YAML and produced different results than the real parser; routing everything through the same proven parser gives consistent results regardless of the host's PHP extensions. The existing install's `vendor/` is preferred over the staged 2.0 tree because its location is fixed and known (the staged directory name is configurable) and it is guaranteed present.

# v1.0.0-rc.6
## 06-16-2026

1. [](#bugfix)
    * **A truncated Grav 2.0 download can no longer be staged.** PHP's HTTP stream reports end-of-file when the server, a proxy, or a flaky connection drops mid-transfer, so the kickoff's download loop exited cleanly on a partial file, and the only validation afterward was a 1KB minimum-size check — a release zip cut off at any point past that sailed through and got marked as staged. The wizard then failed at the extract step with the unhelpful `Could not open zip (code 35)` (libzip's "truncated zip archive"). The kickoff now cross-checks the bytes received against the response's `Content-Length`, verifies the file actually opens as a zip archive (with at least one entry) before the `.migrating` flag is written, deletes the partial file on any failure, and reports what went wrong — including how many bytes arrived versus how many were expected — along with the remedy (retry, or download the release manually and point `source_local_zip` at it).
2. [](#improved)
    * The wizard's extract step now recognizes the libzip error codes that mean the staged zip is damaged (19 not-a-zip, 21 inconsistent, 35 truncated) and explains that the download was interrupted and how to recover (Reset Migration on the admin page, then stage again), instead of printing only the bare numeric code.
    * A configured `source_local_zip` is validated as a readable archive before staging, so a bad manual download is caught at kickoff with a pointer to re-download and verify it with `unzip -t` (the file itself is left in place, since the user supplied it).
    * Disk-full or quota errors while saving the download are now detected at write time instead of silently truncating the file.

# v1.0.0-rc.5
## 06-10-2026

1. [](#new)
    * A customized admin path now carries across the migration. Grav 1.7 stores the admin route in `admin.yaml`, but Admin 2.0 reads its own `admin2.yaml`, so a site that changed `/admin` to e.g. `/backend` as a security measure would otherwise revert to the default after migrating. When the source route differs from the `/admin` default, the migration now writes it into the staged `admin2.yaml` (normalized to Admin 2.0's `/path` form), merging into any existing admin2 config rather than overwriting it. Only the route is carried — it's the one 1.7 admin setting Admin 2.0 has an equivalent for. [#6]
    * The migrate page now checks whether the `user/data`, `user/accounts`, `user/config` and `user/env` folders are reachable over the web and, on Apache, offers a one-click fix that blocks them; on other webservers it shows the exact rule to add by hand.
    * Migration now scans content for URL-based image transforms that bypass Grav's media object (e.g. `image.jpg?cropResize=300,200`) and turns on Grav 2.0's new `system.images.url_actions` toggle in the staged `system.yaml` when it finds any. Grav 1.7 applied these query-string actions with no gate; 2.0 disables them by default because they run with arguments an unauthenticated visitor controls. The normal developer-driven path is unaffected and deliberately left alone: a Twig/Markdown media call like `page.media['x'].cropResize(300,200)`, or a Markdown image whose file is the page's own media (`![](x.jpg?cropResize=300,200)`), is resolved through the media object into a hashed cache URL with no query string and never touches the toggle. Only references Grav can't resolve to page media — absolute/rooted paths, `theme://`/`image://` stream paths, files that aren't co-located, and anything hand-written in a theme template — keep their literal `?action=` URL and need the toggle, so those are what flip it on. External and protocol-relative URLs (`https://cdn.example.com/x.jpg?format=webp`, `//host/x.jpg?…`) are skipped, since they're served by the remote host and a CDN's own query string can't be a Grav image action. The report lists the pages and templates involved, and flags any transform that requests an image above the `system.images.max_pixels` ceiling (still refused even with the toggle on) so you can raise the limit or rework it.

# v1.0.0-rc.4
## 06-03-2026

1. [](#new)
    * Migration now scans the source site for Twig-in-content usage (both per-page `process: twig: true` and the site-wide `system.yaml` opt-ins) and turns on Grav 2.0's new `security.twig_content` gate in the migrated install, so those pages keep rendering after promote. If any Twig-enabled page also reads site config inside Twig, the `config` access toggle is enabled too.
    * Migration now scans Twig-enabled page content for the function and filter calls it uses and re-enables them for Grav 2.0, which tightened Twig security. Raw PHP functions (e.g. `strtoupper`) are added to both `system.twig.safe_functions`/`safe_filters` (so they're callable at all) and `security.twig_sandbox.allowed_functions`/`allowed_filters` (so sandboxed page content may call them); your existing `safe_functions` entries are preserved and merged in. Plugin-provided Twig functions (e.g. `unite_gallery`) are added to the sandbox allowlist and called out in the report — the providing plugin still needs to register them (ideally via the `onBuildTwigSandboxPolicy` event). Functions Grav 2.0 refuses outright — `Utils::isDangerousFunction()` (`system`, `exec`, `preg_replace`, …) and the sandbox's by-design exclusions (`constant`, `read_file`, `evaluate`, …) — are never added and are listed in the report instead. The sandbox lists are written in full (core defaults plus additions) because Grav merges them by index.
2. [](#improved)
    * Step 1 pre-flight now warns when PHP's `set_time_limit`, `ignore_user_abort`, or `proc_open` are blocked by `disable_functions` (common on managed hosts such as RunCloud). With `set_time_limit` disabled the wizard cannot lift PHP's execution limit, so the long Step 2 copy or the GPM update of many plugins can exceed `max_execution_time` and die with a silent HTTP 500. The warning prints the current `max_execution_time` and the remedy (raise the host's `max_execution_time` / php-fpm `request_terminate_timeout` / proxy read timeout). The checks are advisory only and do not block the migration. [#5]
    * Migration now strips the dead `twig.undefined_functions` / `undefined_filters` keys from the staged `system.yaml` — Grav 2.0 removed the blanket undefined-function escape hatch (an unlisted function/filter is now a hard error). The retained `safe_functions` / `safe_filters` keys are preserved and merged with anything found in content. Block-style values are rewritten cleanly; a multi-line flow value the rewrite can't safely touch is flagged for you to finish by hand.
    * After turning on Grav 2.0's Twig in content security gate, the migration now also removes the matching legacy flag from the staged `system.yaml` so the migrated install has one setting in one place instead of two doing the same job. An explicit opt-out (`pages.process.twig: false`) is preserved.
    * Removing that legacy flag handles more `system.yaml` shapes than before: quoted values, files without a trailing newline, Windows line endings, and unrelated `twig:` keys under sibling blocks (which a previous version could accidentally strip too). The `system.yaml` rewrite is now atomic so an interrupted migration cannot corrupt the staged config. When the legacy flag is written in flow-style (e.g. `process: { twig: true }`) the migration cannot safely auto-remove it without losing comments, and the wizard now flags this so you can finish the cleanup by hand.
    * The source-site scanner now recognises the same quoted truthy values the per-page scanner already does, so a 1.x install with `pages.process.twig: "true"` correctly turns on the 2.0 security gate after migration.
3. [](#bugfix)
    * **Backup zip created on Windows is now extractable.** `mg_zip_webroot` was passing `SplFileInfo::getPathname()` output straight into `ZipArchive::addFile($abs, $rel)` — on Windows that meant entry names were stored with native `\\` separators instead of the `/` the zip spec requires. Every standards-tolerant extractor (7-Zip, Windows Explorer's in-place viewer, macOS Archive Utility) treated the backslashes as literal filename characters, dumping every file in the zip's root with names like `user\plugins\admin\file.php` and rendering directory entries as a flat breadcrumb list. Now normalized to `/` regardless of OS. A standalone repair script `wizard/mg-repair-backup.php` ships in this release for users whose pre-rc.3 Windows backup zips are still on disk — it rewrites the entry names so the zip extracts correctly with any tool.

# v1.0.0-rc.3
## 05-13-2026

1. [](#improved)
    * Pre-promote callout now warns to close any editor, git GUI (Sourcetree, GitHub Desktop, GitKraken), and terminal that has the webroot open — on Windows these processes hold file handles that block the Phase 2 delete pass.
    * Promote step on Windows now runs a pre-flight scan for locked files BEFORE deleting anything, so the wizard reports the specific paths (e.g. `user/plugins/foo/.git/index`) the user needs to free, rather than half-destroying the webroot and failing midway. macOS and Linux skip the scan — `unlink()` succeeds on open files there.
    * Promote failure callout now names the specific file that couldn't be deleted (e.g. `user/plugins/foo/.git/objects/pack/pack-abc.idx`) instead of just the top-level entry, so it's obvious which editor or git GUI to close.
    * Promote failure callout now includes recovery instructions for the backup zip, including a Windows-specific warning that File Explorer's in-place zip viewer renders nested paths as a flat breadcrumb list (`system·src·Grav·…`) and that you must use **Right-click → Extract All…** rather than dragging entries out.
    * README has a new **Recovering from a failed promote** section documenting the three-phase rollback model and platform-specific extraction commands.
2. [](#bugfix)
    * nginx config snippet shown in Step 5 (Test) is now actually functional. The previous version put the PHP `location ~ \.php$` block as a sibling of the `location ^~ /grav-2/` prefix — but nginx never evaluates sibling regex locations once an `^~` prefix match wins, so PHP under the stage path was served as a static download. The snippet now nests the PHP block *inside* the prefix block and adds `fastcgi_split_path_info` and `fastcgi_index` to match Grav's documented nginx template. [#3]
    * Outbound HTTP from the migration wizard now honors Grav's `system.http.proxy_url` and `system.http.proxy_cert_path` settings (and the standard `HTTPS_PROXY` / `HTTP_PROXY` / `ALL_PROXY` env vars as fallback). Previously, every HTTP call — the Grav 2.0 zip download, GPM catalog queries, GitHub release lookups, plugin/theme replacement zips, the curated compat registry — built its own stream context with no proxy support and silently failed for sites behind a corporate proxy. Kickoff now forwards the site's proxy config into the `.migrating` flag at staging time, and the standalone wizard reads it via a new `mg_http_context()` helper. [#2]

# v1.0.0-rc.2
## 05-06-2026

1. [](#improved)
    * Step 2 compatibility breakdown now has a dedicated **Will be upgraded** bucket for plugins whose installed version reads as 1.7-only but for which GPM has a newer 2.0-compatible release. Previously these were rendered under **Incompatible** even though Phase 4's `gpm update` will land the new version — misleading because the user's skip/disable policy doesn't apply to them.
2. [](#bugfix)
    * Replacement installs (admin2 + api) are now guaranteed even when the curated compatibility registry is offline or has been pruned of those entries — a hardcoded baseline maps `admin → admin2` (with `requires: [api]`) and is merged under the remote response so any remote entry still wins per slug.
    * GPM upgrade detection no longer silently fails: `getgrav.org/downloads` returns the install URL under `zipball_url`, but the wizard was reading `download`. Normalized inside `mg_fetch_gpm_index` so every plugin with a newer 2.0-compatible release on GPM now lands in the **Will be upgraded** bucket and gets installed via GPM during the upgrade pass (instead of silently falling through to the GitHub fallback path).

# v1.0.0-rc.1
## 05-04-2026

1. [](#new)
    * Two reset modes — **Restart Wizard** keeps the downloaded Grav 2.0 zip and lets you re-run from step 1, **Reset Migration** wipes everything and starts over.
2. [](#improved)
    * Plugin upgrade lookups now ask GPM for the release that fits Grav 2.0 specifically, so suggested upgrades reflect what actually works on the destination.
    * Plugin upgrades during migration are offered for any plugin with a newer 2.0-compatible release on GPM, not only those in the curated compatibility registry.
    * Replacement installs (admin2, api, etc.) now fall back to the newest tagged GitHub release — including beta tags — when a plugin isn't on GPM yet.
    * Plugin updates during Copy & Migrate now run through Grav 2.0's own `bin/gpm`, matching how a regular admin update behaves.
    * Compatibility breakdown table groups rows by status with per-bucket counts (Compatible / Needs update / Incompatible / Will be installed) and color-coded labels for where each verdict came from.
    * Symlinked plugins and themes are preserved through the migration, so developer setups with linked plugin clones don't get clobbered.
    * Long-running steps (bulk copy, plugin upgrade) no longer time out on shared hosts with low `max_execution_time`.
    * The "already staged" error when starting a new migration now points at the Restart/Reset buttons instead of asking you to delete files by hand.
3. [](#bugfix)
    * Recursive delete during reset no longer follows symlinks — protects real files outside the staged tree.
    * Plugin upgrade pass no longer clobbers plugins that are about to be replaced (admin → admin2, etc.).
    * Compatibility policy (skip/disable) now applies *after* the upgrade pass, so freshly upgraded 2.0-compatible plugins aren't then disabled.
    * CLI php detection handles hosts where `PHP_BINARY` points at `php-fpm` or `php-cgi`.

# v1.0.0-beta.5
## 04-25-2026

2. [](#bugfix)
    * Use 'latest' URL to always get the latest version of Grav 2.0 beta
    * Allow being run in Grav 1.7.49+

# v1.0.0-beta.4
## 04-21-2026

1. [](#improved)
    * Default source URL now points at the released Grav 2.0 beta `grav-update` package (`https://getgrav.org/download/core/grav-update/2.0.0-beta.1?testing`) instead of a local dev zip. The update package ships system/vendor/bin only (no baseline `user/` pages) — this avoids polluting migrated sites with default home/typography pages that the full install package would otherwise drop on top of the source content.
    * Staging flow reworked around a single bulk copy: Step 2 now copies the entire source `user/` directory verbatim into the staged install (including any custom folders beyond plugins/themes/accounts), then applies plugin compat policy, auto-updates, and replacement installs in place. Step 3 becomes a transform-only step that rewrites `admin.*` → `api.*` on the already-copied account yamls. Step 4 is a confirmation/summary of what landed in staged `user/`.
    * Staged layout is now package-agnostic. After extract, `user/`, `user/{plugins,themes,accounts,config,data,pages}/`, and a root `.htaccess` (materialized from `webserver-configs/htaccess.txt`) are created when missing, so downstream steps work whether the source zip is `grav-update`, `grav`, or `grav-admin`.
    * Theme handling and messaging: themes are always kept as-is (skip policy no longer removes them); incompatible themes render as ⚠ "Kept — Twig 3 compatibility enabled (verify before promoting)" rather than a scary ✗. Step 2 intro and stream subtitles explain the Twig 3 compat layer and that custom/unmarked themes are expected to work through it.
    * Top-level `user/` dotfiles (`.git`, `.DS_Store`, editor backups) and symlinks are explicitly excluded from the bulk copy and recorded in the step summary.

2. [](#bugfix)
    * `do_plugins_themes` and `do_content` no longer abort with "Source or staged user/ missing" when the source package is `grav-update` (which ships no `user/`). Extract normalizes the skeleton first.
    * `mg_patch_staged_htaccess` (used by the Test step to set `RewriteBase` for sub-path testing) no longer fails on `grav-update`-based stages — the extract step materializes `.htaccess` from the zip's `webserver-configs/htaccess.txt` template when missing.

# v1.0.0-beta.3
## 04-20-2026

1. [](#bugfix)
    * Use beta release URL of Grav 2.0

# v1.0.0-beta.2
## 04-16-2026

1. [](#bugfix)
    * Preserve executable bits on `bin/*` during staged zip extract. The raw `fwrite()`-based extractor dropped the mode stored in the zip's central directory, landing `bin/grav`, `bin/gpm`, `bin/plugin`, and `bin/composer.phar` at `0644` post-migration and breaking CLI tooling on the fresh 2.0 install. Extract now honors the zip's unix mode when present, with a safety-net `chmod 0755` for anything directly under `bin/` so test-built zips (which omit mode metadata) also work.

# v1.0.0-beta.1
## 04-15-2026

1. [](#new)
   * Initial scaffold: kickoff plugin for staging Grav 2.0 alongside an existing 1.7/1.8 site.
   * CLI: `bin/plugin migrate-grav init` and `bin/plugin migrate-grav status`.
   * Admin page with single-click staging that redirects to the standalone wizard.
