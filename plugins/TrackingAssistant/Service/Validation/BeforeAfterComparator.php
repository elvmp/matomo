<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Validation;

use Piwik\Plugins\TrackingAssistant\Service\Diagnostic\AssertionEvaluator;

class BeforeAfterComparator
{
    private $assertions;

    public function __construct(?AssertionEvaluator $assertions = null)
    {
        $this->assertions = $assertions ?: new AssertionEvaluator();
    }

    public function compare(array $scenario, array $baseline, array $validation): array
    {
        $expectedSteps = count((array) ($scenario['steps'] ?? []));
        if ((int) ($validation['scenarioResult']['completedSteps'] ?? -1) !== $expectedSteps) {
            return ['outcome' => 'INCONCLUSIVE', 'assertions' => [], 'regressions' => ['scenario_not_reproduced']];
        }

        $assertions = $this->assertions->evaluate($scenario, $validation);
        $allPassed = true;
        foreach ($assertions as $assertion) {
            if (empty($assertion['passed'])) {
                $allPassed = false;
                break;
            }
        }

        $regressions = [];
        $baselinePageviews = $this->countType($baseline, 'pageview');
        $validationPageviews = $this->countType($validation, 'pageview');
        if ($validationPageviews > $baselinePageviews) {
            $regressions[] = 'pageview_count_increased';
        }
        if ($this->countConsoleErrors($validation) > $this->countConsoleErrors($baseline)) {
            $regressions[] = 'console_errors_increased';
        }
        if ($this->countTrackingHttpErrors($validation) > $this->countTrackingHttpErrors($baseline)) {
            $regressions[] = 'tracking_http_errors_increased';
        }

        return [
            'outcome' => $allPassed && !$regressions ? 'PASS' : 'FAIL',
            'assertions' => $assertions,
            'regressions' => $regressions,
            'baseline' => [
                'pageviews' => $baselinePageviews,
                'consoleErrors' => $this->countConsoleErrors($baseline),
                'trackingHttpErrors' => $this->countTrackingHttpErrors($baseline),
            ],
            'validation' => [
                'pageviews' => $validationPageviews,
                'consoleErrors' => $this->countConsoleErrors($validation),
                'trackingHttpErrors' => $this->countTrackingHttpErrors($validation),
            ],
        ];
    }

    private function countType(array $result, string $type): int
    {
        $count = 0;
        foreach ((array) ($result['trackingRequests'] ?? []) as $request) {
            if (is_array($request) && ($request['type'] ?? '') === $type) $count++;
        }
        return $count;
    }

    private function countConsoleErrors(array $result): int
    {
        $count = count((array) ($result['errors'] ?? []));
        foreach ((array) ($result['observations'] ?? []) as $observation) {
            if (is_array($observation) && ($observation['type'] ?? '') === 'console' && ($observation['level'] ?? '') === 'error') $count++;
        }
        return $count;
    }

    private function countTrackingHttpErrors(array $result): int
    {
        $count = 0;
        foreach ((array) ($result['trackingRequests'] ?? []) as $request) {
            if (is_array($request) && isset($request['responseStatus']) && (int) $request['responseStatus'] >= 400) $count++;
        }
        return $count;
    }
}
