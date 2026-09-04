<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Proposal;

use Piwik\Plugins\TrackingAssistant\Service\TagManager\ContainerGraph;
use Piwik\Plugins\TrackingAssistant\Service\TagManager\TagManagerAdapter;

class ProposalValidator
{
    private $adapter;
    private $canonical;

    public function __construct(?TagManagerAdapter $adapter = null, ?CanonicalJson $canonical = null)
    {
        $this->adapter = $adapter ?: new TagManagerAdapter();
        $this->canonical = $canonical ?: new CanonicalJson();
    }

    public function validateStoredProposal(array $row): array
    {
        $proposal = json_decode((string) ($row['proposal_json'] ?? ''), true);
        if (!is_array($proposal) || ($proposal['target'] ?? '') !== 'tag_manager') {
            throw new \RuntimeException('Proposal schema is invalid.');
        }
        $operations = isset($proposal['operations']) && is_array($proposal['operations']) ? $proposal['operations'] : [];
        if (count($operations) !== 1) {
            throw new \RuntimeException('This vertical slice only permits one deterministic MTM operation per proposal.');
        }
        $operation = $operations[0];
        if (($operation['operation'] ?? '') !== 'update' || ($operation['entity'] ?? '') !== 'trigger') {
            throw new \RuntimeException('This vertical slice only permits updating an existing trigger.');
        }

        $idSite = (int) $row['idsite'];
        $idContainer = (string) $row['idcontainer'];
        $draftId = $this->adapter->getDraftId($idSite, $idContainer);
        if ($draftId !== (int) $row['idcontainerversion']) {
            throw new \RuntimeException('The Tag Manager draft changed after this recommendation was generated. No change was made.');
        }

        $current = ContainerGraph::triggerMutationView($this->adapter->getTrigger(
            $idSite,
            $idContainer,
            $draftId,
            (int) $operation['id']
        ));
        $before = $operation['before'] ?? null;
        $after = $operation['after'] ?? null;
        if (!is_array($before) || !is_array($after)) {
            throw new \RuntimeException('Proposal before/after values are invalid.');
        }
        $expectedHash = (string) ($operation['beforeHash'] ?? '');
        if ($expectedHash === '' || $this->canonical->hash($current) !== $expectedHash || $this->canonical->hash($before) !== $expectedHash) {
            throw new \RuntimeException('The Tag Manager trigger changed after this recommendation was generated. No change was made.');
        }

        $this->validateTriggerDelta($before, $after, (int) ($operation['conditionIndex'] ?? -1));
        return $proposal;
    }

    private function validateTriggerDelta(array $before, array $after, int $conditionIndex): void
    {
        if ($conditionIndex < 0 || !isset($before['conditions'][$conditionIndex], $after['conditions'][$conditionIndex])) {
            throw new \RuntimeException('Proposal condition reference is invalid.');
        }

        $beforeCondition = $before['conditions'][$conditionIndex];
        $afterCondition = $after['conditions'][$conditionIndex];
        if (!is_array($beforeCondition) || !is_array($afterCondition)
            || ($beforeCondition['comparison'] ?? '') !== 'match_css_selector'
            || ($afterCondition['comparison'] ?? '') !== 'match_css_selector'
            || !ContainerGraph::isClickElement($beforeCondition['actual'] ?? null)
            || !ContainerGraph::isClickElement($afterCondition['actual'] ?? null)) {
            throw new \RuntimeException('Only a Click Element CSS selector condition may be changed automatically.');
        }

        $selector = $afterCondition['expected'] ?? null;
        if (!is_string($selector) || $selector === '' || strlen($selector) > 1000) {
            throw new \RuntimeException('Proposed CSS selector is invalid.');
        }

        $copy = $after;
        $copy['conditions'][$conditionIndex]['expected'] = $beforeCondition['expected'] ?? null;
        if ($this->canonical->hash($copy) !== $this->canonical->hash($before)) {
            throw new \RuntimeException('Proposal attempts to modify more than the approved CSS selector field.');
        }
    }
}
