<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Diagnostic;

use Piwik\Plugins\TrackingAssistant\Service\Proposal\CanonicalJson;
use Piwik\Plugins\TrackingAssistant\Service\TagManager\ContainerGraph;

class DiagnosisEngine
{
    private $assertions;
    private $canonical;

    public function __construct(?AssertionEvaluator $assertions = null, ?CanonicalJson $canonical = null)
    {
        $this->assertions = $assertions ?: new AssertionEvaluator();
        $this->canonical = $canonical ?: new CanonicalJson();
    }

    public function diagnose(array $run, array $result, ?ContainerGraph $graph = null): array
    {
        $scenario = json_decode((string) ($run['scenario_json'] ?? ''), true);
        if (!is_array($scenario)) {
            $scenario = ['steps' => [], 'assertions' => []];
        }

        $findings = [];
        $detection = (array) ($result['matomoDetection'] ?? []);
        if (empty($detection['matomoJsLoaded']) && empty($detection['directQueuePresent']) && empty($detection['mtmContainerLoaded'])) {
            $findings[] = $this->finding('MATOMO_JS_NOT_LOADED', 'error', 95,
                'Matomo JavaScript was not detected on the reproduced page.',
                [['type' => 'matomo_detection', 'value' => $detection]]);
        }
        if (!empty($run['idcontainer']) && empty($detection['mtmContainerLoaded'])) {
            $findings[] = $this->finding('MTM_CONTAINER_NOT_LOADED', 'error', 95,
                'The selected Matomo Tag Manager container was not observed loading on the page.',
                [['type' => 'mtm_detection', 'value' => $detection]]);
        }

        foreach ((array) ($result['trackingRequests'] ?? []) as $request) {
            if (is_array($request) && isset($request['responseStatus']) && (int) $request['responseStatus'] >= 400) {
                $findings[] = $this->finding('MATOMO_REQUEST_HTTP_ERROR', 'error', 98,
                    'A Matomo tracking request was sent but the tracking endpoint returned an HTTP error.',
                    [['type' => 'tracking_request', 'value' => [
                        'type' => $request['type'] ?? null,
                        'status' => (int) $request['responseStatus'],
                        'endpoint' => $request['endpoint'] ?? null,
                    ]]]);
                break;
            }
        }

        $evaluated = $this->assertions->evaluate($scenario, $result);
        foreach ($evaluated as $assertionResult) {
            if ($assertionResult['passed']) {
                continue;
            }
            $assertion = $assertionResult['assertion'];
            if (($assertion['type'] ?? '') === 'matomo.event.count') {
                $code = $assertionResult['actual'] > $assertionResult['expected'] ? 'EVENT_DUPLICATED' : 'EVENT_MISSING';
                $summary = $code === 'EVENT_DUPLICATED'
                    ? 'The expected Matomo event fired more times than requested.'
                    : 'The expected Matomo event was not observed during the reproduced interaction.';
                $recommendation = null;
                if ($code === 'EVENT_MISSING' && $assertionResult['actual'] === 0 && $graph) {
                    $recommendation = $this->buildClickTriggerRecommendation($assertion, $result, $graph);
                }
                $findings[] = $this->finding($code, 'error', $recommendation ? 98 : 92, $summary, [[
                    'type' => 'assertion',
                    'value' => $assertionResult,
                ]], $recommendation);
            } elseif (($assertion['type'] ?? '') === 'matomo.pageview.count') {
                $code = $assertionResult['actual'] > $assertionResult['expected'] ? 'PAGEVIEW_DUPLICATED' : 'PAGEVIEW_MISSING';
                $findings[] = $this->finding($code, 'error', 94,
                    $code === 'PAGEVIEW_DUPLICATED' ? 'The pageview count is higher than expected.' : 'The expected pageview was not observed.',
                    [['type' => 'assertion', 'value' => $assertionResult]]);
            }
        }

        return $findings;
    }

    private function buildClickTriggerRecommendation(array $assertion, array $result, ContainerGraph $graph): ?array
    {
        $matchingTags = [];
        foreach ($graph->getTags() as $tag) {
            if (!is_array($tag) || ($tag['type'] ?? '') !== 'Matomo') {
                continue;
            }
            $parameters = isset($tag['parameters']) && is_array($tag['parameters']) ? $tag['parameters'] : [];
            if (($parameters['trackingType'] ?? '') !== 'event') {
                continue;
            }
            if (!$this->scalarEquals($parameters['eventCategory'] ?? null, $assertion['category'] ?? null)
                || !$this->scalarEquals($parameters['eventAction'] ?? null, $assertion['action'] ?? null)) {
                continue;
            }
            if (array_key_exists('name', $assertion) && $assertion['name'] !== null
                && !$this->scalarEquals($parameters['eventName'] ?? null, $assertion['name'])) {
                continue;
            }
            $matchingTags[] = $tag;
        }

        if (count($matchingTags) !== 1) {
            return null;
        }
        $tag = $matchingTags[0];
        $fireIds = isset($tag['fire_trigger_ids']) && is_array($tag['fire_trigger_ids']) ? $tag['fire_trigger_ids'] : [];
        if (count($fireIds) !== 1) {
            return null;
        }
        $trigger = $graph->getTrigger($fireIds[0]);
        if (!$trigger) {
            return null;
        }

        $conditions = isset($trigger['conditions']) && is_array($trigger['conditions']) ? $trigger['conditions'] : [];
        $candidateConditions = [];
        foreach ($conditions as $index => $condition) {
            if (!is_array($condition)
                || ($condition['comparison'] ?? '') !== 'match_css_selector'
                || !ContainerGraph::isClickElement($condition['actual'] ?? null)
                || !is_string($condition['expected'] ?? null)) {
                continue;
            }
            $candidateConditions[] = [$index, $condition];
        }
        if (count($candidateConditions) !== 1) {
            return null;
        }

        list($conditionIndex, $condition) = $candidateConditions[0];
        $inspectionKey = ContainerGraph::inspectionKey($trigger['idtrigger'], (int) $conditionIndex);
        $click = $this->lastClickEvidence($result);
        if (!$click || !array_key_exists($inspectionKey, (array) ($click['selectorMatches'] ?? []))) {
            return null;
        }
        if ($click['selectorMatches'][$inspectionKey] !== false) {
            return null;
        }

        $newSelector = $this->chooseSelector((array) ($click['selectorCandidates'] ?? []));
        $oldSelector = (string) $condition['expected'];
        if ($newSelector === null || $newSelector === $oldSelector) {
            return null;
        }

        $before = ContainerGraph::triggerMutationView($trigger);
        $after = $before;
        $after['conditions'][$conditionIndex]['expected'] = $newSelector;

        return [
            'target' => 'tag_manager',
            'reason' => 'The existing Click Element CSS selector did not match the element that was actually clicked during reproduction.',
            'risk' => 'low',
            'summary' => 'Update the existing trigger selector instead of creating another trigger.',
            'operations' => [[
                'operation' => 'update',
                'entity' => 'trigger',
                'id' => (int) $trigger['idtrigger'],
                'conditionIndex' => (int) $conditionIndex,
                'before' => $before,
                'after' => $after,
                'beforeHash' => $this->canonical->hash($before),
            ]],
            'validation' => [
                'assertions' => [$assertion],
            ],
            'evidence' => [
                'tagId' => (int) ($tag['idtag'] ?? 0),
                'tagName' => (string) ($tag['name'] ?? ''),
                'triggerId' => (int) $trigger['idtrigger'],
                'triggerName' => (string) ($trigger['name'] ?? ''),
                'oldSelector' => $oldSelector,
                'newSelector' => $newSelector,
                'oldSelectorMatchedClickedElement' => false,
            ],
        ];
    }

    private function scalarEquals($actual, $expected): bool
    {
        if (!(is_string($actual) || is_numeric($actual)) || !(is_string($expected) || is_numeric($expected))) {
            return false;
        }
        return (string) $actual === (string) $expected;
    }

    private function lastClickEvidence(array $result): ?array
    {
        $clicks = array_values(array_filter((array) ($result['observations'] ?? []), function ($observation) {
            return is_array($observation) && ($observation['type'] ?? '') === 'click_element';
        }));
        return $clicks ? $clicks[count($clicks) - 1] : null;
    }

    private function chooseSelector(array $candidates): ?string
    {
        usort($candidates, function ($a, $b) {
            $scoreA = (int) ($a['score'] ?? 0);
            $scoreB = (int) ($b['score'] ?? 0);
            if ($scoreA !== $scoreB) {
                return $scoreB - $scoreA;
            }
            return (int) ($a['documentCount'] ?? 99999) - (int) ($b['documentCount'] ?? 99999);
        });
        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || empty($candidate['matchesTarget'])) {
                continue;
            }
            $selector = $candidate['selector'] ?? null;
            $count = (int) ($candidate['documentCount'] ?? 0);
            if (is_string($selector) && $selector !== '' && strlen($selector) <= 1000 && $count >= 1 && $count <= 20) {
                return $selector;
            }
        }
        return null;
    }

    private function finding(string $code, string $severity, int $confidence, string $summary, array $evidence, ?array $recommendation = null): array
    {
        return compact('code', 'severity', 'confidence', 'summary', 'evidence', 'recommendation');
    }
}
