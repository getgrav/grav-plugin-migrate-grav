<?php
/**
 * Grav 2.0 Migration Wizard — entry point.
 *
 * Copied to webroot as /migrate.php by Kickoff, beside migrate-wizard.php.
 * Grav 2.0 requires PHP 8.3, and the wizard itself is written in PHP 8.x
 * syntax, so on older PHP it would fail to parse before it could say why.
 * This file stays parseable on any PHP 5.x+ so it can check first and show
 * a clear error instead of "syntax error, unexpected 'o777'".
 */

if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    $mgPhp = PHP_VERSION;

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "\nERROR: Grav 2.0 requires PHP 8.3 or newer. This is PHP {$mgPhp}.\n\n"
            . "Grav 2.0 will not run on this PHP version, so the migration cannot continue.\n"
            . "Run this wizard with a PHP 8.3+ binary, or switch the site to PHP 8.3+ first.\n\n");
        exit(1);
    }

    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $mgPhp = htmlspecialchars($mgPhp, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP 8.3 required | Grav 2.0 Migration</title>
<style>
  body { margin: 0; padding: 40px 16px; background: #f4f5f7; color: #1f2328; font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
  .box { max-width: 640px; margin: 0 auto; background: #fff; border: 1px solid #e5484d; border-top: 8px solid #e5484d; border-radius: 10px; padding: 32px; box-shadow: 0 6px 24px rgba(0,0,0,.08); }
  h1 { margin: 0 0 8px; font-size: 28px; line-height: 1.2; color: #b4232a; }
  .ver { display: inline-block; margin: 8px 0 20px; padding: 6px 12px; background: #fdecec; color: #b4232a; border-radius: 6px; font-weight: 600; }
  p { margin: 0 0 14px; }
  ol { margin: 0 0 14px; padding-left: 22px; }
  code { background: #f0f1f3; padding: 1px 6px; border-radius: 4px; }
  .note { color: #59636e; font-size: 14px; margin-top: 20px; }
</style>
</head>
<body>
  <div class="box">
    <h1>Grav 2.0 requires PHP 8.3 or newer</h1>
    <div class="ver">This site is running PHP {$mgPhp}</div>
    <p>Grav 2.0 will not run on this PHP version, so the migration cannot continue.</p>
    <ol>
      <li>Switch this site to PHP 8.3 or newer. On most hosts that is a setting in the control panel; with Docker, use a PHP 8.3+ image.</li>
      <li>Check that your current Grav 1.x site still works on the new PHP version.</li>
      <li>Come back to <code>migrate.php</code> or the Migrate Grav page in the admin to continue.</li>
    </ol>
    <p class="note">Your current site has not been changed.</p>
  </div>
</body>
</html>
HTML;
    exit;
}

define('MG_ENTRY', true);
require __DIR__ . '/migrate-wizard.php';
