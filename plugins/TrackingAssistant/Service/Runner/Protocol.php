<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Runner;

use Piwik\Plugins\TrackingAssistant\Service\Security\UrlPolicy;
use Piwik\Plugins\TrackingAssistant\SystemSettings;

class Protocol
{
    public const VERSION = 1;

    private $settings;
    private $urlPolicy;

    public function __construct(?SystemSettings $settings = null, ?UrlPolicy $urlPolicy = null)
    {
        $this->settings = $settings ?: new SystemSettings();
        $this->urlPolicy = $urlPolicy ?: new UrlPolicy($this->settings);
    }

    public function buildJob(array $run, array $inspection = [], array $preview = []): array
    {
        $scenario = json_decode((string) ($run['scenario_json'] ?? ''), true);
        if (!is_array($scenario)) {
            $scenario = ['steps' => [], 'assertions' => []];
        }
        $scenario = [
            'steps' => isset($scenario['steps']) && is_array($scenario['steps']) ? $scenario['steps'] : [],
            'assertions' => isset($scenario['assertions']) && is_array($scenario['assertions']) ? $scenario['assertions'] : [],
        ];

        $cssSelectors = isset($inspection['cssSelectors']) && is_array($inspection['cssSelectors'])
            ? $inspection['cssSelectors']
            : $inspection;
        if (!is_array($cssSelectors)) {
            $cssSelectors = [];
        }

        $idSite = (int) $run['idsite'];
        return [
            'protocolVersion' => self::VERSION,
            'runId' => (int) $run['idrun'],
            'target' => [
                'url' => (string) $run['target_url'],
            ],
            'browser' => (string) $run['browser'],
            'scenario' => $scenario,
            'capture' => [
                'network' => true,
                'console' => true,
                'screenshots' => false,
                'trace' => false,
            ],
            'privacy' => [
                'captureBodies' => false,
                'redactQueryValues' => true,
            ],
            'inspection' => [
                'cssSelectors' => array_values(array_slice($cssSelectors, 0, 100)),
            ],
            'preview' => $preview,
            'networkPolicy' => [
                'allowedNavigationHosts' => $this->urlPolicy->getAllowedNavigationHosts($idSite),
                'allowPrivateNetworkTargets' => $this->urlPolicy->isPrivateNetworkAllowed(),
            ],
            'timeoutMs' => max(10, (int) $this->settings->runTimeout->getValue()) * 1000,
        ];
    }

    public function validateResult(array $result, int $expectedRunId): void
    {
        if (($result['protocolVersion'] ?? null) !== self::VERSION) {
            throw new \RuntimeException('Runner protocol version mismatch.');
        }
        if ((int) ($result['runId'] ?? 0) !== $expectedRunId) {
            throw new \RuntimeException('Runner response runId does not match the claimed job.');
        }
        if (!in_array(($result['status'] ?? ''), ['completed', 'failed'], true)) {
            throw new \RuntimeException('Runner response has an invalid status.');
        }
    }
}
