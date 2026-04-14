<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\MigrateGrav\Kickoff;
use RocketTheme\Toolbox\Event\Event;
use RuntimeException;

class MigrateGravPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        if (!$this->isAdmin()) {
            return;
        }

        $this->enable([
            'onAdminMenu' => ['onAdminMenu', 0],
            'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
            'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
        ]);
    }

    public function onAdminMenu(): void
    {
        $this->grav['twig']->plugins_hooked_nav['Migrate to Grav 2.0'] = [
            'route' => 'migrate-grav',
            'icon' => 'fa-rocket',
        ];
    }

    public function onAdminTwigTemplatePaths(Event $event): void
    {
        $paths = $event['paths'];
        $paths[] = __DIR__ . '/admin/templates';
        $event['paths'] = $paths;
    }

    public function onAdminTaskExecute(Event $event): void
    {
        $task = $event['method'] ?? null;

        $controller = $event['controller'] ?? null;
        $authorized = !$controller
            || !method_exists($controller, 'isAuthorizedFunction')
            || $controller->isAuthorizedFunction('admin.super');

        if ($task === 'taskMigrateGravInit') {
            if (!$authorized) {
                $this->grav['admin']->setMessage('Super admin required to start migration.', 'error');
                return;
            }
            try {
                $payload = $this->runKickoff('admin');
            } catch (RuntimeException $e) {
                $this->grav['admin']->setMessage('Migration kickoff failed: ' . $e->getMessage(), 'error');
                return;
            }
            $this->grav->redirect($payload['wizard_url'], 302);
            return;
        }

        if ($task === 'taskMigrateGravReset') {
            if (!$authorized) {
                $this->grav['admin']->setMessage('Super admin required to reset migration.', 'error');
                return;
            }
            $result = $this->runReset();
            if ($result['errors']) {
                $this->grav['admin']->setMessage('Reset incomplete: ' . implode('; ', $result['errors']), 'error');
            } else {
                $msg = $result['removed']
                    ? 'Migration reset. Removed: ' . implode(', ', $result['removed'])
                    : 'Nothing to reset.';
                $this->grav['admin']->setMessage($msg, 'info');
            }
            // Pass the Route object directly — Grav::redirect() handles
            // Route instances via toString(true), which already includes the
            // install base and admin route (no doubling, no manual stitching).
            $this->grav->redirect($this->grav['admin']->getAdminRoute('/migrate-grav'), 302);
            return;
        }
    }

    /**
     * Shared kickoff entry point used by both admin and CLI surfaces.
     */
    public function runKickoff(string $trigger, ?string $adminUser = null): array
    {
        return $this->newKickoff()->run([
            'grav_version' => GRAV_VERSION,
            'admin_user' => $adminUser,
            'trigger' => $trigger,
        ]);
    }

    /**
     * Shared reset entry point used by admin and CLI.
     */
    public function runReset(): array
    {
        return $this->newKickoff()->reset();
    }

    /**
     * Expose the current .migrating state to admin twig templates, so the
     * migrate-grav page can switch between "Start" and "Continue/Reset" UI
     * without round-tripping through an AJAX call.
     */
    public function onTwigSiteVariables(): void
    {
        // Only attach the flag state on the migrate-grav admin page. Checking
        // the request URI is more reliable than poking admin internals.
        $path = (string) $this->grav['uri']->path();
        if (!str_ends_with(rtrim($path, '/'), '/migrate-grav')) {
            return;
        }

        $state = $this->newKickoff()->readFlag();
        $this->grav['twig']->twig_vars['migrate_grav_state'] = $state;
    }

    private function newKickoff(): Kickoff
    {
        require_once __DIR__ . '/classes/Kickoff.php';

        $config  = (array) $this->config->get('plugins.migrate-grav', []);
        $webroot = defined('GRAV_WEBROOT') ? GRAV_WEBROOT : GRAV_ROOT;

        return new Kickoff($webroot, $config);
    }
}
