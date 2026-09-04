<?php

namespace Piwik\Plugins\TrackingAssistant\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\TrackingAssistant\Service\Diagnostic\DiagnosisEngine;
use Piwik\Plugins\TrackingAssistant\Service\TagManager\ContainerGraph;

class DiagnosisEngineTest extends TestCase
{
    public function testCreatesLowRiskSelectorUpdateFromBrowserEvidence(): void
    {
        $trigger = [
            'idtrigger' => 17,
            'type' => 'Click',
            'name' => 'Donation Click',
            'description' => '',
            'parameters' => [],
            'conditions' => [[
                'actual' => '{{ClickElement}}',
                'comparison' => 'match_css_selector',
                'expected' => '.donate-btn',
            ]],
        ];
        $graph = new ContainerGraph(
            ['draft' => ['idcontainerversion' => 3]],
            3,
            [[
                'idtag' => 21,
                'type' => 'Matomo',
                'name' => 'Donation Event',
                'parameters' => [
                    'trackingType' => 'event',
                    'eventCategory' => 'Donation',
                    'eventAction' => 'Click',
                    'eventName' => 'CTA',
                ],
                'fire_trigger_ids' => [17],
            ]],
            [$trigger],
            []
        );
        $run = [
            'idcontainer' => 'abc123',
            'scenario_json' => json_encode([
                'steps' => [['action' => 'click', 'locator' => ['type' => 'css', 'value' => '[data-action="donate"]']]],
                'assertions' => [[
                    'type' => 'matomo.event.count',
                    'category' => 'Donation',
                    'action' => 'Click',
                    'name' => 'CTA',
                    'count' => 1,
                ]],
            ]),
        ];
        $result = [
            'trackingRequests' => [],
            'matomoDetection' => ['matomoJsLoaded' => true, 'mtmContainerLoaded' => true],
            'observations' => [[
                'type' => 'click_element',
                'selectorMatches' => ['trigger:17:condition:0' => false],
                'selectorCandidates' => [[
                    'selector' => '[data-action="donate"]',
                    'score' => 96,
                    'matchesTarget' => true,
                    'documentCount' => 1,
                ]],
            ]],
        ];

        $findings = (new DiagnosisEngine())->diagnose($run, $result, $graph);
        $eventFinding = null;
        foreach ($findings as $finding) {
            if ($finding['code'] === 'EVENT_MISSING') {
                $eventFinding = $finding;
                break;
            }
        }

        self::assertNotNull($eventFinding);
        self::assertSame(98, $eventFinding['confidence']);
        self::assertSame('tag_manager', $eventFinding['recommendation']['target']);
        self::assertSame('low', $eventFinding['recommendation']['risk']);
        self::assertSame('[data-action="donate"]', $eventFinding['recommendation']['operations'][0]['after']['conditions'][0]['expected']);
        self::assertSame('.donate-btn', $eventFinding['recommendation']['operations'][0]['before']['conditions'][0]['expected']);
    }

    public function testDoesNotAutoFixWhenMultipleMatchingEventTagsExist(): void
    {
        $trigger = [
            'idtrigger' => 17,
            'type' => 'Click',
            'name' => 'Donation Click',
            'description' => '',
            'parameters' => [],
            'conditions' => [['actual' => '{{ClickElement}}','comparison' => 'match_css_selector','expected' => '.donate-btn']],
        ];
        $tag = [
            'idtag' => 21,
            'type' => 'Matomo',
            'name' => 'Donation Event',
            'parameters' => ['trackingType' => 'event', 'eventCategory' => 'Donation', 'eventAction' => 'Click'],
            'fire_trigger_ids' => [17],
        ];
        $second = $tag;
        $second['idtag'] = 22;
        $graph = new ContainerGraph(['draft' => ['idcontainerversion' => 3]], 3, [$tag, $second], [$trigger], []);
        $run = ['idcontainer' => 'abc123','scenario_json' => json_encode(['steps' => [],'assertions' => [['type' => 'matomo.event.count', 'category' => 'Donation', 'action' => 'Click', 'count' => 1]]])];
        $result = ['trackingRequests' => [],'matomoDetection' => ['matomoJsLoaded' => true, 'mtmContainerLoaded' => true],'observations' => [['type' => 'click_element','selectorMatches' => ['trigger:17:condition:0' => false],'selectorCandidates' => [['selector' => '#donate', 'score' => 90, 'matchesTarget' => true, 'documentCount' => 1]]]]];

        $findings = (new DiagnosisEngine())->diagnose($run, $result, $graph);
        foreach ($findings as $finding) {
            if ($finding['code'] === 'EVENT_MISSING') {
                self::assertNull($finding['recommendation']);
                return;
            }
        }
        self::fail('Expected EVENT_MISSING finding.');
    }
}
