<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Proposal;

use Piwik\Plugins\TrackingAssistant\Dao\AuditDao;
use Piwik\Plugins\TrackingAssistant\Dao\ProposalOperationsDao;
use Piwik\Plugins\TrackingAssistant\Dao\ProposalsDao;
use Piwik\Plugins\TrackingAssistant\Service\TagManager\ContainerGraph;
use Piwik\Plugins\TrackingAssistant\Service\TagManager\TagManagerAdapter;

class RollbackManager
{
    private $adapter;
    private $canonical;
    private $operations;
    private $proposals;
    private $audit;

    public function __construct(?TagManagerAdapter $adapter = null, ?CanonicalJson $canonical = null)
    {
        $this->adapter = $adapter ?: new TagManagerAdapter();
        $this->canonical = $canonical ?: new CanonicalJson();
        $this->operations = new ProposalOperationsDao();
        $this->proposals = new ProposalsDao();
        $this->audit = new AuditDao();
    }

    public function rollback(array $proposalRow, string $login, string $reason): void
    {
        $idProposal = (int) $proposalRow['idproposal'];
        $idRun = (int) $proposalRow['idrun'];
        $idSite = (int) $proposalRow['idsite'];
        $idContainer = (string) $proposalRow['idcontainer'];
        $draftId = (int) $proposalRow['idcontainerversion'];
        $this->audit->record($idRun, $idProposal, $login, 'rollback_started', ['reason' => $reason]);

        try {
            foreach ($this->operations->getForProposal($idProposal, true) as $operationRow) {
                if (($operationRow['status'] ?? '') !== 'applied') {
                    continue;
                }
                if (($operationRow['entity_type'] ?? '') !== 'trigger' || ($operationRow['operation'] ?? '') !== 'update') {
                    throw new \RuntimeException('Unsupported rollback operation.');
                }
                $before = json_decode((string) $operationRow['before_json'], true);
                $after = json_decode((string) $operationRow['after_json'], true);
                if (!is_array($before) || !is_array($after)) {
                    throw new \RuntimeException('Rollback operation snapshot is invalid.');
                }
                $current = ContainerGraph::triggerMutationView($this->adapter->getTrigger(
                    $idSite,
                    $idContainer,
                    $draftId,
                    (int) $operationRow['entity_id']
                ));
                if ($this->canonical->hash($current) !== $this->canonical->hash($after)) {
                    throw new \RuntimeException('The modified trigger changed again before rollback.');
                }
                $this->adapter->updateTrigger($idSite, $idContainer, $draftId, $before);
                $this->operations->setStatus((int) $operationRow['idoperation'], 'rolled_back');
            }
        } catch (\Throwable $inverseError) {
            $snapshot = (string) ($proposalRow['snapshot_json'] ?? '');
            if ($snapshot === '') {
                throw $inverseError;
            }
            $this->adapter->importDraft($idSite, $idContainer, $snapshot);
            $this->audit->record($idRun, $idProposal, $login, 'rollback_snapshot_restored', [
                'inverseError' => substr($inverseError->getMessage(), 0, 500),
            ]);
        }

        $this->proposals->setStatus($idProposal, 'rolled_back');
        $this->audit->record($idRun, $idProposal, $login, 'rollback_completed', ['reason' => $reason]);
    }
}
