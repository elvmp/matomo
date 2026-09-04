<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Scenario;

class ScenarioValidator
{
    private const ACTIONS = [
        'navigate', 'click', 'fill', 'select', 'check', 'uncheck', 'press', 'submit',
        'waitForElement', 'waitForText', 'waitForUrl', 'waitForNetworkIdle',
    ];

    private const LOCATOR_TYPES = ['role', 'label', 'placeholder', 'text', 'testId', 'css'];
    private const ASSERTION_TYPES = ['matomo.pageview.count', 'matomo.event.count'];

    public function validate($scenario): array
    {
        if ($scenario === null || $scenario === '') {
            return ['steps' => [], 'assertions' => []];
        }

        if (is_string($scenario)) {
            $scenario = json_decode($scenario, true);
            if (!is_array($scenario)) {
                throw new \InvalidArgumentException('scenario must be valid JSON.');
            }
        }

        if (!is_array($scenario)) {
            throw new \InvalidArgumentException('scenario must be an object containing a steps array.');
        }

        $steps = $scenario['steps'] ?? [];
        if (!is_array($steps)) {
            throw new \InvalidArgumentException('scenario.steps must be an array.');
        }
        if (count($steps) > 50) {
            throw new \InvalidArgumentException('A diagnostic scenario may contain at most 50 steps.');
        }

        $normalised = [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                throw new \InvalidArgumentException("Scenario step {$index} must be an object.");
            }

            $action = (string) ($step['action'] ?? '');
            if (!in_array($action, self::ACTIONS, true)) {
                throw new \InvalidArgumentException("Unsupported scenario action: {$action}");
            }

            if ($this->actionNeedsLocator($action)) {
                $this->validateLocator($step['locator'] ?? null, $index);
            }

            if (isset($step['value']) && is_string($step['value']) && strlen($step['value']) > 4096) {
                throw new \InvalidArgumentException("Scenario step {$index} value is too large.");
            }
            if ($action === 'navigate' && empty($step['url'])) {
                throw new \InvalidArgumentException("Navigate step {$index} requires url.");
            }

            unset($step['script'], $step['javascript'], $step['evaluate']);
            $normalised[] = $step;
        }

        return [
            'steps' => $normalised,
            'assertions' => $this->validateAssertions($scenario['assertions'] ?? []),
        ];
    }

    private function validateAssertions($assertions): array
    {
        if (!is_array($assertions)) {
            throw new \InvalidArgumentException('scenario.assertions must be an array.');
        }
        if (count($assertions) > 20) {
            throw new \InvalidArgumentException('A diagnostic scenario may contain at most 20 assertions.');
        }

        $out = [];
        foreach ($assertions as $index => $assertion) {
            if (!is_array($assertion)) {
                throw new \InvalidArgumentException("Scenario assertion {$index} must be an object.");
            }
            $type = (string) ($assertion['type'] ?? '');
            if (!in_array($type, self::ASSERTION_TYPES, true)) {
                throw new \InvalidArgumentException("Unsupported scenario assertion type: {$type}");
            }
            if (!isset($assertion['count']) || !is_numeric($assertion['count'])) {
                throw new \InvalidArgumentException("Scenario assertion {$index} requires a numeric count.");
            }
            $count = (int) $assertion['count'];
            if ($count < 0 || $count > 100) {
                throw new \InvalidArgumentException("Scenario assertion {$index} count must be between 0 and 100.");
            }

            $normalised = ['type' => $type, 'count' => $count];
            if ($type === 'matomo.event.count') {
                foreach (['category', 'action'] as $field) {
                    if (!isset($assertion[$field]) || !is_scalar($assertion[$field]) || (string) $assertion[$field] === '') {
                        throw new \InvalidArgumentException("Matomo event assertion {$index} requires {$field}.");
                    }
                    $normalised[$field] = substr((string) $assertion[$field], 0, 500);
                }
                if (array_key_exists('name', $assertion) && $assertion['name'] !== null) {
                    if (!is_scalar($assertion['name'])) {
                        throw new \InvalidArgumentException("Matomo event assertion {$index} name must be a scalar value.");
                    }
                    $normalised['name'] = substr((string) $assertion['name'], 0, 500);
                }
            }
            $out[] = $normalised;
        }
        return $out;
    }

    private function actionNeedsLocator(string $action): bool
    {
        return in_array($action, [
            'click', 'fill', 'select', 'check', 'uncheck', 'press', 'submit',
            'waitForElement', 'waitForText',
        ], true);
    }

    private function validateLocator($locator, int $index): void
    {
        if (!is_array($locator)) {
            throw new \InvalidArgumentException("Scenario step {$index} requires a locator.");
        }

        $type = (string) ($locator['type'] ?? '');
        if (!in_array($type, self::LOCATOR_TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported locator type in scenario step {$index}.");
        }

        if ($type === 'role') {
            if (empty($locator['role'])) {
                throw new \InvalidArgumentException("Role locator in scenario step {$index} requires role.");
            }
        } elseif (!isset($locator['value']) || $locator['value'] === '') {
            throw new \InvalidArgumentException("Locator in scenario step {$index} requires a value.");
        }
    }
}
