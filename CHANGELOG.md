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
