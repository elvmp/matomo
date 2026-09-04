<?php

namespace Piwik\Plugins\TrackingAssistant\Service\Validation;

use Piwik\Plugins\TrackingAssistant\Dao\AuditDao;
use Piwik\Plugins\TrackingAssistant\Dao\ProposalsDao;
use Piwik\Plugins\TrackingAssistant\Dao\RunsDao;
use Piwik\Plugins\TrackingAssistant\Service\Proposal\RollbackManager;
use Piwik\Plugins\TrackingAssistant\Service\TagManager\TagManagerAdapter;

class ValidationFinalizer
{
    private $runs;
    private $proposals;
    private $adapter;
    private $comparator;
    private $audit;

    public function __construct(?TagManagerAdapter $adapter = null, ?BeforeAfterComparator $comparator = null)
    {
        $this->runs = new RunsDao();
        $this->proposals = new ProposalsDao();
        $this->adapter = $adapter ?: new TagManagerAdapter();
        $this->comparator = $comparator ?: new BeforeAfterComparator();
        $this->audit = new AuditDao();
    }

    public function finalise(array $validationRun, array $validationResult): array
    {
        $scenario = json_decode((string) $validationRun['scenario_json'], true);
        $meta = is_array($scenario) ? ($scenario['_trackingAssistant'] ?? null) : null;
        if (!is_array($meta)) {
            throw new \RuntimeException('Validation run metadata is missing.');
        }
        $proposal = $this->proposals->get((int) $meta['proposalId']);
        $baselineRun = $this->runs->get((int) $meta['baselineRunId']);
        if (!$proposal || !$baselineRun) {
            throw new \RuntimeException('Validation proposal or baseline run is missing.');
        }
        $baselineProgress = json_decode((string) $baselineRun['progress_json'], true);
        $baseline = is_array($baselineProgress) ? ($baselineProgress['result'] ?? null) : null;
        if (!is_array($baseline)) {
            throw new \RuntimeException('Baseline browser result is missing.');
        }

        unset($scenario['_trackingAssistant']);
        $comparison = $this->comparator->compare($scenario, $baseline, $validationResult);
        $idSite = (int) $proposal['idsite'];
        $idContainer = (string) $proposal['idcontainer'];
        $previousPreview = isset($meta['previousPreviewVersionId']) && $meta['previousPreviewVersionId'] !== null
            ? (int) $meta['previousPreviewVersionId'] : null;
        $login = (string) $validationRun['login'];

        try {
            if ($comparison['outcome'] === 'PASS') {
                $this->proposals->setStatus((int) $proposal['idproposal'], 'passed');
                $this->audit->record((int) $validationRun['idrun'], (int) $proposal['idproposal'], $login, 'validation_passed', $comparison);
            } else {
                (new RollbackManager($this->adapter))->rollback($proposal, $login, 'validation_' . strtolower($comparison['outcome']));
                $this->audit->record((int) $validationRun['idrun'], (int) $proposal['idproposal'], $login, 'validation_failed', $comparison);
            }
        } finally {
            $this->adapter->restorePreview($idSite, $idContainer, $previousPreview);
        }
        return $comparison;
    }

    public function failAndRollback(array $validationRun, string $message): void
    {
        $scenario = json_decode((string) $validationRun['scenario_json'], true);
        $meta = is_array($scenario) ? ($scenario['_trackingAssistant'] ?? null) : null;
        if (!is_array($meta)) {
            return;
        }
        $proposal = $this->proposals->get((int) $meta['proposalId']);
        if (!$proposal) {
            return;
        }
        $previousPreview = isset($meta['previousPreviewVersionId']) && $meta['previousPreviewVersionId'] !== null
            ? (int) $meta['previousPreviewVersionId'] : null;
        try {
            (new RollbackManager($this->adapter))->rollback($proposal, (string) $validationRun['login'], 'validation_inconclusive');
        } finally {
            $this->adapter->restorePreview((int) $proposal['idsite'], (string) $proposal['idcontainer'], $previousPreview);
        }
        $this->audit->record((int) $validationRun['idrun'], (int) $proposal['idproposal'], (string) $validationRun['login'], 'validation_inconclusive', [
            'message' => substr($message, 0, 500),
        ]);
    }
}
