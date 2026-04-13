<?php
namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\MigrateToTwoPlugin;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;

/**
 * Stages a Grav 2.0 install and writes a migration handoff token. Does NOT
 * execute the wizard itself — the user starts a fresh PHP process so no
 * 1.7/1.8 code remains loaded once migration begins.
 *
 * Usage: bin/plugin migrate-to-2 init [--source-url=URL] [--source-zip=PATH]
 */
class InitCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Stage Grav 2.0 alongside this site and prepare the migration wizard.')
            ->addOption(
                'source-url',
                null,
                InputOption::VALUE_REQUIRED,
                'URL of the Grav 2.0 release zip (overrides plugin config).'
            )
            ->addOption(
                'source-zip',
                null,
                InputOption::VALUE_REQUIRED,
                'Local path to a Grav 2.0 zip (overrides URL — for development).'
            );
    }

    protected function serve(): int
    {
        $grav = Grav::instance();

        /** @var MigrateToTwoPlugin $plugin */
        $plugin = $grav['plugins']->get('migrate-to-2');
        if (!$plugin instanceof MigrateToTwoPlugin) {
            $this->output->writeln('<red>Plugin migrate-to-2 not loaded.</red>');
            return 1;
        }

        // Apply CLI overrides into the plugin config for this run.
        $sourceUrl = $this->input->getOption('source-url');
        $sourceZip = $this->input->getOption('source-zip');
        if ($sourceUrl) {
            $grav['config']->set('plugins.migrate-to-2.source_url', $sourceUrl);
        }
        if ($sourceZip) {
            $grav['config']->set('plugins.migrate-to-2.source_local_zip', $sourceZip);
        }

        try {
            $payload = $plugin->runKickoff('cli');
        } catch (RuntimeException $e) {
            $this->output->writeln('<red>Kickoff failed:</red> ' . $e->getMessage());
            return 1;
        }

        $this->output->writeln('');
        $this->output->writeln('<green>Grav 2.0 staged successfully.</green>');
        $this->output->writeln('');
        $this->output->writeln('  Token:        ' . $payload['token']);
        $this->output->writeln('  Stage dir:    ' . $payload['stage_dir']);
        $this->output->writeln('  Staged zip:   ' . $payload['staged_zip']);
        $this->output->writeln('  Wizard URL:   ' . $payload['wizard_url']);
        $this->output->writeln('');
        $this->output->writeln('<yellow>Next step — start the wizard in a fresh PHP process:</yellow>');
        $this->output->writeln('');
        $this->output->writeln('  In a browser: visit ' . $payload['wizard_url']);
        $this->output->writeln('  On the CLI:   php migrate.php --token=' . $payload['token']);
        $this->output->writeln('');
        $this->output->writeln('<cyan>Important:</cyan> do NOT continue inside this Grav 1.x process —');
        $this->output->writeln('the wizard must run standalone to avoid file locks and library conflicts.');

        return 0;
    }
}
