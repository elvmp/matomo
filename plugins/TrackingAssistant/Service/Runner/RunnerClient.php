<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Runner;

use Piwik\Plugins\TrackingAssistant\SystemSettings;

class RunnerClient
{
    private $settings;
    private $protocol;

    public function __construct(?SystemSettings $settings = null, ?Protocol $protocol = null)
    {
        $this->settings = $settings ?: new SystemSettings();
        $this->protocol = $protocol ?: new Protocol($this->settings);
    }

    public function execute(array $run, array $inspection = [], array $preview = []): array
    {
        if ((string) $this->settings->runnerMode->getValue() !== 'local') {
            throw new \RuntimeException('Remote runner mode is reserved but not implemented in the foundation build.');
        }

        $script = PIWIK_INCLUDE_PATH . '/plugins/TrackingAssistant/playwright-runner/src/index.js';
        if (!is_file($script)) {
            throw new \RuntimeException('Tracking Assistant runner script is missing.');
        }

        $job = $this->protocol->buildJob($run, $inspection, $preview);
        $timeoutSeconds = max(10, (int) ceil($job['timeoutMs'] / 1000) + 10);
        $result = $this->runNodeProcess($script, $job, $timeoutSeconds);
        $this->protocol->validateResult($result, (int) $run['idrun']);

        if ($result['status'] === 'failed') {
            $message = (string) ($result['errors'][0]['message'] ?? 'Browser diagnostic failed.');
            throw new \RuntimeException($message);
        }

        return $result;
    }

    public function getStatus(): array
    {
        $script = PIWIK_INCLUDE_PATH . '/plugins/TrackingAssistant/playwright-runner/src/index.js';
        $package = PIWIK_INCLUDE_PATH . '/plugins/TrackingAssistant/playwright-runner/node_modules/playwright/package.json';

        return [
            'mode' => (string) $this->settings->runnerMode->getValue(),
            'scriptPresent' => is_file($script),
            'playwrightInstalled' => is_file($package),
            'ready' => (string) $this->settings->runnerMode->getValue() === 'local' && is_file($script) && is_file($package),
        ];
    }

    private function runNodeProcess(string $script, array $job, int $timeoutSeconds): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = 'node ' . escapeshellarg($script);
        $process = proc_open($command, $descriptors, $pipes, PIWIK_INCLUDE_PATH);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start the local Tracking Assistant runner.');
        }

        fwrite($pipes[0], json_encode($job, JSON_UNESCAPED_SLASHES) . "\n");
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $exitCode = null;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);

            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process, 15);
                usleep(250000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                foreach ([$pipes[1], $pipes[2]] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
                throw new \RuntimeException('Browser diagnostic exceeded the configured timeout.');
            }

            usleep(50000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded)) {
            $detail = trim($stderr) !== '' ? trim($stderr) : 'Runner returned invalid JSON.';
            throw new \RuntimeException($detail . ($exitCode !== null ? " (exit {$exitCode})" : ''));
        }

        return $decoded;
    }
}
