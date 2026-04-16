# v1.0.0-beta.2
## 04-16-2026

1. [](#new)
    * Promote now auto-patches the post-migration `.htaccess` to pass the HTTP `Authorization` header through to PHP under FPM / FastCGI. Idempotent — no-op when the rule is already present (Grav 2.0-beta.1+ ships it by default). Ensures Bearer-auth plugins (e.g. Grav API) work immediately after migration on FPM hosts. Result is recorded in `.migration-complete` under `htaccess_authz_patch`.
1. [](#bugfix)
    * Preserve executable bits on `bin/*` during staged zip extract. The raw `fwrite()`-based extractor dropped the mode stored in the zip's central directory, landing `bin/grav`, `bin/gpm`, `bin/plugin`, and `bin/composer.phar` at `0644` post-migration and breaking CLI tooling on the fresh 2.0 install. Extract now honors the zip's unix mode when present, with a safety-net `chmod 0755` for anything directly under `bin/` so test-built zips (which omit mode metadata) also work.

# v1.0.0-beta.1
## 04-15-2026

1. [](#new)
   * Initial scaffold: kickoff plugin for staging Grav 2.0 alongside an existing 1.7/1.8 site.
   * CLI: `bin/plugin migrate-grav init` and `bin/plugin migrate-grav status`.
   * Admin page with single-click staging that redirects to the standalone wizard.
