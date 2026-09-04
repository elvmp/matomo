<?php

namespace Piwik\Plugins\TrackingAssistant\Commands;

use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\TrackingAssistant\Service\Runner\RunnerClient;

class CheckRunner extends ConsoleCommand
{
    protected function configure()
    {
        $this->setName('tracking-assistant:check-runner');
        $this->setDescription('Check whether the local Tracking Assistant Playwright runner is installed.');
    }

    protected function doExecute(): int
    {
        $status = (new RunnerClient())->getStatus();
        foreach ($status as $key => $value) {
            $this->getOutput()->writeln(sprintf('%s: %s', $key, is_bool($value) ? ($value ? 'yes' : 'no') : $value));
        }

        if (!$status['ready']) {
            $this->getOutput()->writeln('');
            $this->getOutput()->writeln('<comment>Install the runner with:</comment>');
            $this->getOutput()->writeln('cd plugins/TrackingAssistant/playwright-runner && npm install && npx playwright install chromium');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
