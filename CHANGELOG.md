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
