<?php
namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;

/**
 * Reports the current state of any in-progress migration.
 *
 * Usage: bin/plugin migrate-to-2 status
 */
class StatusCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('status')
            ->setDescription('Show the state of any in-progress Grav 2.0 migration.');
    }

    protected function serve(): int
    {
        $webroot = defined('GRAV_WEBROOT') ? GRAV_WEBROOT : GRAV_ROOT;
        $flag = $webroot . DIRECTORY_SEPARATOR . '.migrating';

        if (!is_file($flag)) {
            $this->output->writeln('<cyan>No migration in progress.</cyan>');
            return 0;
        }

        $raw = file_get_contents($flag);
        $data = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($data)) {
            $this->output->writeln('<red>Found .migrating but contents are not valid JSON.</red>');
            return 1;
        }

        $this->output->writeln('<green>Migration staged.</green>');
        $this->output->writeln('  Created:     ' . date('c', (int)($data['created'] ?? 0)));
        $this->output->writeln('  Token:       ' . ($data['token'] ?? '(none)'));
        $this->output->writeln('  Stage dir:   ' . ($data['stage_dir'] ?? '(none)'));
        $this->output->writeln('  Wizard URL:  ' . ($data['wizard_url'] ?? '(none)'));
        $this->output->writeln('  Source:      ' . json_encode($data['source'] ?? [], JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
