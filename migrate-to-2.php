<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\MigrateToTwo\Kickoff;
use RocketTheme\Toolbox\Event\Event;
use RuntimeException;

class MigrateToTwoPlugin extends Plugin
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
        ]);
    }

    public function onAdminMenu(): void
    {
        $this->grav['twig']->plugins_hooked_nav['Migrate to Grav 2.0'] = [
            'route' => 'migrate-to-2',
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
        if ($task !== 'taskMigrateToTwoInit') {
            return;
        }

        $controller = $event['controller'] ?? null;
        if ($controller && method_exists($controller, 'isAuthorizedFunction')
            && !$controller->isAuthorizedFunction('admin.super')) {
            $this->grav['admin']->setMessage('Super admin required to start migration.', 'error');
            return;
        }

        try {
            $payload = $this->runKickoff('admin');
        } catch (RuntimeException $e) {
            $this->grav['admin']->setMessage('Migration kickoff failed: ' . $e->getMessage(), 'error');
            return;
        }

        $url = $payload['wizard_url'];
        $this->grav->redirect($url, 302);
    }

    /**
     * Shared kickoff entry point used by both admin and CLI surfaces.
     */
    public function runKickoff(string $trigger, ?string $adminUser = null): array
    {
        require_once __DIR__ . '/classes/Kickoff.php';

        $config = (array)$this->config->get('plugins.migrate-to-2', []);
        $webroot = defined('GRAV_WEBROOT') ? GRAV_WEBROOT : GRAV_ROOT;

        $kickoff = new Kickoff($webroot, $config);

        return $kickoff->run([
            'grav_version' => GRAV_VERSION,
            'admin_user' => $adminUser,
            'trigger' => $trigger,
        ]);
    }
}
