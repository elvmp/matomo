<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Proposal;

use Piwik\Plugins\TrackingAssistant\Dao\AuditDao;
use Piwik\Plugins\TrackingAssistant\Dao\ProposalOperationsDao;
use Piwik\Plugins\TrackingAssistant\Dao\ProposalsDao;
use Piwik\Plugins\TrackingAssistant\Dao\RunsDao;
use Piwik\Plugins\TrackingAssistant\Service\TagManager\TagManagerAdapter;
use Piwik\Plugins\TrackingAssistant\SystemSettings;

class ProposalService
{
    private $proposals;
    private $operations;
    private $runs;
    private $audit;
    private $adapter;
    private $validator;

    public function __construct(?TagManagerAdapter $adapter = null, ?ProposalValidator $validator = null)
    {
        $this->proposals = new ProposalsDao();
        $this->operations = new ProposalOperationsDao();
        $this->runs = new RunsDao();
        $this->audit = new AuditDao();
        $this->adapter = $adapter ?: new TagManagerAdapter();
        $this->validator = $validator ?: new ProposalValidator($this->adapter);
    }

    public function createFromFinding(array $run, int $idFinding, array $finding): ?int
    {
        $recommendation = $finding['recommendation'] ?? null;
        if (!is_array($recommendation) || ($recommendation['target'] ?? '') !== 'tag_manager' || empty($run['idcontainer'])) {
            return null;
        }

        foreach ($this->proposals->getForRun((int) $run['idrun']) as $existing) {
            $json = json_decode((string) $existing['proposal_json'], true);
            if (is_array($json) && (int) ($json['sourceFindingId'] ?? 0) === $idFinding) {
                return (int) $existing['idproposal'];
            }
        }

        $recommendation['sourceFindingId'] = $idFinding;
        $draftId = $this->adapter->getDraftId((int) $run['idsite'], (string) $run['idcontainer']);
        $idProposal = $this->proposals->create([
            'idrun' => (int) $run['idrun'],
            'idsite' => (int) $run['idsite'],
            'idcontainer' => (string) $run['idcontainer'],
            'idcontainerversion' => $draftId,
            'status' => 'ready',
            'summary' => (string) ($recommendation['summary'] ?? $finding['summary']),
            'proposal' => $recommendation,
            'created_by' => (string) $run['login'],
        ]);

        foreach ((array) ($recommendation['operations'] ?? []) as $position => $operation) {
            if (is_array($operation)) {
                $this->operations->insertOperation($idProposal, (int) $position, $operation);
            }
        }
        $this->audit->record((int) $run['idrun'], $idProposal, (string) $run['login'], 'proposal_generated', [
            'sourceFindingId' => $idFinding,
            'risk' => $recommendation['risk'] ?? null,
        ]);
        return $idProposal;
    }

    public function approve(array $proposal, string $login): bool
    {
        $approved = $this->proposals->approve((int) $proposal['idproposal'], $login);
        if ($approved) {
            $this->audit->record((int) $proposal['idrun'], (int) $proposal['idproposal'], $login, 'proposal_approved');
        }
        return $approved;
    }

    public function dismiss(array $proposal, string $login): bool
    {
        $dismissed = $this->proposals->dismiss((int) $proposal['idproposal']);
        if ($dismissed) {
            $this->audit->record((int) $proposal['idrun'], (int) $proposal['idproposal'], $login, 'proposal_dismissed');
        }
        return $dismissed;
    }

    public function apply(array $proposalRow, string $login): array
    {
        $settings = new SystemSettings();
        if (!(bool) $settings->allowMtmDraftModifications->getValue()) {
            throw new \RuntimeException('Tag Manager draft modifications are disabled by the administrator.');
        }
        if (($proposalRow['status'] ?? '') !== 'approved') {
            throw new \RuntimeException('Proposal must be explicitly approved before it can be applied.');
        }

        $proposal = $this->validator->validateStoredProposal($proposalRow);
        $idProposal = (int) $proposalRow['idproposal'];
        $idSite = (int) $proposalRow['idsite'];
        $idContainer = (string) $proposalRow['idcontainer'];
        $draftId = (int) $proposalRow['idcontainerversion'];
        $baselineRun = $this->runs->get((int) $proposalRow['idrun']);
        if (!$baselineRun || ($baselineRun['status'] ?? '') !== 'completed') {
            throw new \RuntimeException('The baseline diagnostic is not available for validation replay.');
        }

        $this->proposals->setStatus($idProposal, 'applying');
        $snapshot = $this->adapter->exportDraft($idSite, $idContainer);
        $this->proposals->storeSnapshot($idProposal, $snapshot);
        $previousPreviewVersion = $this->adapter->getPreviewVersionId($idSite, $idContainer);
        $applied = false;

        try {
            $operationRows = $this->operations->getForProposal($idProposal);
            $operations = (array) $proposal['operations'];
            foreach ($operations as $position => $operation) {
                $after = $operation['after'];
                $this->adapter->updateTrigger($idSite, $idContainer, $draftId, $after);
                if (isset($operationRows[$position])) {
                    $this->operations->setStatus((int) $operationRows[$position]['idoperation'], 'applied');
                }
                $this->audit->record((int) $proposalRow['idrun'], $idProposal, $login, 'draft_modified', [
                    'entity' => 'trigger',
                    'entityId' => (int) $operation['id'],
                    'operation' => 'update',
                ]);
            }
            $applied = true;
            $this->proposals->setStatus($idProposal, 'applied');

            $this->adapter->enablePreview($idSite, $idContainer, $draftId);
            $scenario = json_decode((string) $baselineRun['scenario_json'], true);
            if (!is_array($scenario)) {
                throw new \RuntimeException('Baseline scenario could not be loaded.');
            }
            $scenario['_trackingAssistant'] = [
                'proposalId' => $idProposal,
                'baselineRunId' => (int) $baselineRun['idrun'],
                'previousPreviewVersionId' => $previousPreviewVersion,
            ];

            $validationRunId = $this->runs->createQueuedRun([
                'idsite' => $idSite,
                'idcontainer' => $idContainer,
                'login' => $login,
                'type' => 'validation',
                'target_url' => (string) $baselineRun['target_url'],
                'target_url_redacted' => (string) $baselineRun['target_url_redacted'],
                'browser' => 'chromium',
                'scenario' => $scenario,
                'expires_date' => $baselineRun['expires_date'],
            ]);
            $proposal['validationRunId'] = $validationRunId;
            $proposal['previousPreviewVersionId'] = $previousPreviewVersion;
            $this->proposals->updateProposalJson($idProposal, $proposal);
            $this->proposals->setStatus($idProposal, 'validating');
            $this->audit->record($validationRunId, $idProposal, $login, 'validation_started', [
                'baselineRunId' => (int) $baselineRun['idrun'],
            ]);
            return ['validationRunId' => $validationRunId, 'status' => 'validating'];
        } catch (\Throwable $e) {
            if ($applied) {
                $fresh = $this->proposals->get($idProposal);
                if ($fresh) {
                    (new RollbackManager($this->adapter))->rollback($fresh, $login, 'apply_or_preview_failed');
                }
            } else {
                $this->proposals->setStatus($idProposal, 'failed');
            }
            try {
                $this->adapter->restorePreview($idSite, $idContainer, $previousPreviewVersion);
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }
    }
}
