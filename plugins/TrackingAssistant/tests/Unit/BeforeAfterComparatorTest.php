<?php

namespace Piwik\Plugins\TrackingAssistant\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\TrackingAssistant\Service\Validation\BeforeAfterComparator;

class BeforeAfterComparatorTest extends TestCase
{
    public function testPassesWhenExpectedEventAppearsWithoutRegression(): void
    {
        $scenario = [
            'steps' => [['action' => 'click']],
            'assertions' => [[
                'type' => 'matomo.event.count',
                'category' => 'Donation',
                'action' => 'Click',
                'count' => 1,
            ]],
        ];
        $baseline = [
            'trackingRequests' => [['type' => 'pageview', 'responseStatus' => 204]],
            'observations' => [],
            'errors' => [],
        ];
        $validation = [
            'trackingRequests' => [
                ['type' => 'pageview', 'responseStatus' => 204],
                ['type' => 'event', 'category' => 'Donation', 'action' => 'Click', 'responseStatus' => 204],
            ],
            'observations' => [],
            'errors' => [],
            'scenarioResult' => ['completedSteps' => 1],
        ];

        $result = (new BeforeAfterComparator())->compare($scenario, $baseline, $validation);
        self::assertSame('PASS', $result['outcome']);
        self::assertSame([], $result['regressions']);
    }

    public function testFailsWhenFixDuplicatesPageview(): void
    {
        $scenario = ['steps' => [], 'assertions' => []];
        $baseline = ['trackingRequests' => [['type' => 'pageview']], 'observations' => [], 'errors' => []];
        $validation = [
            'trackingRequests' => [['type' => 'pageview'], ['type' => 'pageview']],
            'observations' => [],
            'errors' => [],
            'scenarioResult' => ['completedSteps' => 0],
        ];

        $result = (new BeforeAfterComparator())->compare($scenario, $baseline, $validation);
        self::assertSame('FAIL', $result['outcome']);
        self::assertContains('pageview_count_increased', $result['regressions']);
    }
}
