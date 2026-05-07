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
