<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Diagnostic;

class AssertionEvaluator
{
    public function evaluate(array $scenario, array $result): array
    {
        $evaluated = [];
        foreach ((array) ($scenario['assertions'] ?? []) as $assertion) {
            if (!is_array($assertion)) {
                continue;
            }
            $actual = $this->countForAssertion($assertion, (array) ($result['trackingRequests'] ?? []));
            $expected = (int) ($assertion['count'] ?? 0);
            $evaluated[] = [
                'type' => (string) ($assertion['type'] ?? ''),
                'expected' => $expected,
                'actual' => $actual,
                'passed' => $actual === $expected,
                'assertion' => $assertion,
            ];
        }
        return $evaluated;
    }

    public function countForAssertion(array $assertion, array $trackingRequests): int
    {
        $type = (string) ($assertion['type'] ?? '');
        $count = 0;
        foreach ($trackingRequests as $request) {
            if (!is_array($request)) {
                continue;
            }
            if ($type === 'matomo.pageview.count' && ($request['type'] ?? '') === 'pageview') {
                $count++;
                continue;
            }
            if ($type === 'matomo.event.count' && ($request['type'] ?? '') === 'event') {
                if ((string) ($request['category'] ?? '') !== (string) ($assertion['category'] ?? '')) {
                    continue;
                }
                if ((string) ($request['action'] ?? '') !== (string) ($assertion['action'] ?? '')) {
                    continue;
                }
                if (array_key_exists('name', $assertion) && $assertion['name'] !== null
                    && (string) ($request['name'] ?? '') !== (string) $assertion['name']) {
                    continue;
                }
                $count++;
            }
        }
        return $count;
    }
}
