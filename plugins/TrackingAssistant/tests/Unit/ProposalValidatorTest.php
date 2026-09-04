<?php

namespace Piwik\Plugins\TrackingAssistant\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\TrackingAssistant\Service\Proposal\CanonicalJson;
use Piwik\Plugins\TrackingAssistant\Service\Proposal\ProposalValidator;
use Piwik\Plugins\TrackingAssistant\Service\TagManager\TagManagerAdapter;

class ProposalValidatorTest extends TestCase
{
    public function testRejectsConcurrentTriggerChange(): void
    {
        $before = $this->trigger('.old');
        $after = $this->trigger('[data-action="donate"]');
        $adapter = new ProposalValidatorFakeAdapter($this->trigger('.changed'));
        $canonical = new CanonicalJson();
        $row = $this->proposalRow($before, $after, $canonical->hash($before));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('changed after this recommendation');
        (new ProposalValidator($adapter, $canonical))->validateStoredProposal($row);
    }

    public function testRejectsAnyDeltaBeyondApprovedSelector(): void
    {
        $before = $this->trigger('.old');
        $after = $this->trigger('[data-action="donate"]');
        $after['name'] = 'Unexpected renamed trigger';
        $adapter = new ProposalValidatorFakeAdapter($before);
        $canonical = new CanonicalJson();
        $row = $this->proposalRow($before, $after, $canonical->hash($before));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('modify more than the approved CSS selector field');
        (new ProposalValidator($adapter, $canonical))->validateStoredProposal($row);
    }

    private function proposalRow(array $before, array $after, string $hash): array
    {
        return ['idsite' => 1,'idcontainer' => 'abc','idcontainerversion' => 3,'proposal_json' => json_encode(['target' => 'tag_manager','operations' => [['operation' => 'update','entity' => 'trigger','id' => 17,'conditionIndex' => 0,'before' => $before,'after' => $after,'beforeHash' => $hash]]])];
    }

    private function trigger(string $selector): array
    {
        return ['idtrigger' => 17,'type' => 'Click','name' => 'Donation Click','description' => '','parameters' => [],'conditions' => [['actual' => '{{ClickElement}}','comparison' => 'match_css_selector','expected' => $selector]]];
    }
}

class ProposalValidatorFakeAdapter extends TagManagerAdapter
{
    private $trigger;
    public function __construct(array $trigger) { $this->trigger = $trigger; }
    public function getDraftId(int $idSite, string $idContainer): int { return 3; }
    public function getTrigger(int $idSite, string $idContainer, int $idContainerVersion, int $idTrigger): array { return $this->trigger; }
}
