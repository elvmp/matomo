<?php

namespace Piwik\Plugins\TrackingAssistant;

use Piwik\Piwik;
use Piwik\Plugins\TrackingAssistant\Access\Capability\RunTrackingDiagnostics;
use Piwik\Plugins\TrackingAssistant\Dao\AuditDao;
use Piwik\Plugins\TrackingAssistant\Dao\FindingsDao;
use Piwik\Plugins\TrackingAssistant\Dao\ProposalOperationsDao;
use Piwik\Plugins\TrackingAssistant\Dao\ProposalsDao;
use Piwik\Plugins\TrackingAssistant\Dao\RunsDao;
use Piwik\Plugins\TrackingAssistant\Service\Proposal\ProposalService;
use Piwik\Plugins\TrackingAssistant\Service\Runner\RunnerClient;
use Piwik\Plugins\TrackingAssistant\Service\Scenario\ScenarioValidator;
use Piwik\Plugins\TrackingAssistant\Service\Security\RedactionService;
use Piwik\Plugins\TrackingAssistant\Service\Security\UrlPolicy;
use Piwik\Plugins\TrackingAssistant\Service\TagManager\TagManagerAdapter;

/**
 * @method static API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    private const TYPES = ['automatic', 'pageview', 'event', 'form', 'goal', 'ecommerce', 'consent', 'spa'];

    public function startDiagnostic($idSite, $targetUrl, $idContainer = '', $type = 'automatic', $browser = 'chromium', $scenario = [])
    {
        $this->requirePost();
        $idSite = (int) $idSite;
        Piwik::checkUserHasCapability($idSite, RunTrackingDiagnostics::ID);

        $settings = new SystemSettings();
        if (!(bool) $settings->enabled->getValue()) {
            throw new \RuntimeException('Tracking Assistant is disabled by the administrator.');
        }

        $type = strtolower((string) $type);
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported diagnostic type.');
        }

        if ((string) $browser !== 'chromium') {
            throw new \InvalidArgumentException('The MVP currently supports Chromium only.');
        }

        $idContainer = trim((string) $idContainer);
        if ($idContainer !== '') {
            // Native Tag Manager read permission is intentionally checked in the user's web request.
            // The async worker receives no Matomo credentials and only continues this authorised read.
            (new TagManagerAdapter())->getContainer($idSite, $idContainer);
        }

        $urlPolicy = new UrlPolicy($settings);
        $urlPolicy->assertAllowedForSite($idSite, (string) $targetUrl);

        $scenario = $this->normaliseArrayInput($scenario, 'scenario');
        $scenarioValidator = new ScenarioValidator();
        $scenario = $scenarioValidator->validate($scenario);

        $redaction = new RedactionService();
        $runs = new RunsDao();
        $login = (string) Piwik::getCurrentUserLogin();
        $idRun = $runs->createQueuedRun([
            'idsite' => $idSite,
            'idcontainer' => $idContainer,
            'login' => $login,
            'type' => $type,
            'target_url' => (string) $targetUrl,
            'target_url_redacted' => $redaction->redactUrl((string) $targetUrl),
            'browser' => 'chromium',
            'scenario' => $scenario,
            'expires_date' => gmdate('Y-m-d H:i:s', time() + 90 * 86400),
        ]);

        (new AuditDao())->record($idRun, null, $login, 'diagnostic_started', [
            'type' => $type,
            'browser' => 'chromium',
            'target' => $redaction->redactUrl((string) $targetUrl),
            'hasContainer' => $idContainer !== '',
        ]);

        return $this->presentRun($runs->get($idRun));
    }

    public function getDiagnostic($idRun)
    {
        $run = $this->requireRun((int) $idRun);
        Piwik::checkUserHasViewAccess((int) $run['idsite']);
        return $this->presentRun($run);
    }

    public function cancelDiagnostic($idRun)
    {
        $this->requirePost();
        $run = $this->requireRun((int) $idRun);
        Piwik::checkUserHasCapability((int) $run['idsite'], RunTrackingDiagnostics::ID);

        $cancelled = (new RunsDao())->cancel((int) $idRun);
        if ($cancelled) {
            (new AuditDao())->record(
                (int) $idRun,
                null,
                (string) Piwik::getCurrentUserLogin(),
                'diagnostic_cancelled'
            );
        }

        return ['cancelled' => $cancelled];
    }

    public function getRuns($idSite, $limit = 50)
    {
        $idSite = (int) $idSite;
        Piwik::checkUserHasViewAccess($idSite);

        return array_map([$this, 'presentRun'], (new RunsDao())->getForSite($idSite, (int) $limit));
    }

    public function getFindings($idRun)
    {
        $run = $this->requireRun((int) $idRun);
        Piwik::checkUserHasViewAccess((int) $run['idsite']);
        $this->assertCanViewSelectedContainer($run);

        $findings = (new FindingsDao())->getForRun((int) $idRun);
        foreach ($findings as &$finding) {
            $finding = $this->presentFinding($finding);
        }
        unset($finding);

        return $findings;
    }

    public function createProposal($idFinding)
    {
        $this->requirePost();
        $findingDao = new FindingsDao();
        $finding = $findingDao->get((int) $idFinding);
        if (!$finding) {
            throw new \InvalidArgumentException('Tracking Assistant finding does not exist.');
        }
        $run = $this->requireRun((int) $finding['idrun']);
        Piwik::checkUserHasCapability((int) $run['idsite'], RunTrackingDiagnostics::ID);
        $this->assertCanViewSelectedContainer($run);

        $presentedFinding = $this->presentFinding($finding);
        $idProposal = (new ProposalService())->createFromFinding($run, (int) $idFinding, $presentedFinding);
        if (!$idProposal) {
            throw new \RuntimeException('This finding does not contain a safe Tag Manager proposal.');
        }
        return $this->presentProposal($this->requireProposal($idProposal));
    }

    public function getProposals($idRun)
    {
        $run = $this->requireRun((int) $idRun);
        Piwik::checkUserHasViewAccess((int) $run['idsite']);
        $this->assertCanViewSelectedContainer($run);

        $result = [];
        foreach ((new ProposalsDao())->getForRun((int) $idRun) as $proposal) {
            $result[] = $this->presentProposal($proposal);
        }
        return $result;
    }

    public function getProposal($idProposal)
    {
        $proposal = $this->requireProposal((int) $idProposal);
        Piwik::checkUserHasViewAccess((int) $proposal['idsite']);
        (new TagManagerAdapter())->getContainer((int) $proposal['idsite'], (string) $proposal['idcontainer']);
        return $this->presentProposal($proposal);
    }

    public function approveProposal($idProposal)
    {
        $this->requirePost();
        $proposal = $this->requireProposal((int) $idProposal);
        Piwik::checkUserHasCapability((int) $proposal['idsite'], RunTrackingDiagnostics::ID);
        (new TagManagerAdapter())->getContainer((int) $proposal['idsite'], (string) $proposal['idcontainer']);

        $login = (string) Piwik::getCurrentUserLogin();
        if (!(new ProposalService())->approve($proposal, $login)) {
            throw new \RuntimeException('Only a ready proposal can be approved.');
        }
        return $this->presentProposal($this->requireProposal((int) $idProposal));
    }

    public function dismissProposal($idProposal)
    {
        $this->requirePost();
        $proposal = $this->requireProposal((int) $idProposal);
        Piwik::checkUserHasCapability((int) $proposal['idsite'], RunTrackingDiagnostics::ID);
        (new TagManagerAdapter())->getContainer((int) $proposal['idsite'], (string) $proposal['idcontainer']);

        $login = (string) Piwik::getCurrentUserLogin();
        if (!(new ProposalService())->dismiss($proposal, $login)) {
            throw new \RuntimeException('Only a draft or ready proposal can be dismissed.');
        }
        return $this->presentProposal($this->requireProposal((int) $idProposal));
    }

    public function applyProposal($idProposal)
    {
        $this->requirePost();
        $proposal = $this->requireProposal((int) $idProposal);
        Piwik::checkUserHasCapability((int) $proposal['idsite'], RunTrackingDiagnostics::ID);

        // ProposalService validates optimistic concurrency and calls native MTM mutation APIs.
        // Those native APIs enforce tagmanager_write in the current user's request context.
        $result = (new ProposalService())->apply($proposal, (string) Piwik::getCurrentUserLogin());
        return [
            'proposal' => $this->presentProposal($this->requireProposal((int) $idProposal)),
            'validation' => $result,
        ];
    }

    public function getRunnerStatus($idSite)
    {
        Piwik::checkUserHasCapability((int) $idSite, RunTrackingDiagnostics::ID);
        return (new RunnerClient())->getStatus();
    }

    private function requireRun(int $idRun): array
    {
        $run = (new RunsDao())->get($idRun);
        if (!$run) {
            throw new \InvalidArgumentException('Tracking Assistant diagnostic run does not exist.');
        }
        return $run;
    }

    private function requireProposal(int $idProposal): array
    {
        $proposal = (new ProposalsDao())->get($idProposal);
        if (!$proposal) {
            throw new \InvalidArgumentException('Tracking Assistant proposal does not exist.');
        }
        return $proposal;
    }

    private function assertCanViewSelectedContainer(array $run): void
    {
        if (!empty($run['idcontainer'])) {
            (new TagManagerAdapter())->getContainer((int) $run['idsite'], (string) $run['idcontainer']);
        }
    }

    private function presentRun(?array $run): array
    {
        if (!$run) {
            return [];
        }

        return [
            'idRun' => (int) $run['idrun'],
            'idSite' => (int) $run['idsite'],
            'idContainer' => $run['idcontainer'],
            'type' => $run['type'],
            'status' => $run['status'],
            'targetUrl' => $run['target_url_redacted'],
            'browser' => $run['browser'],
            'progress' => $this->decodeJson($run['progress_json'] ?? null, []),
            'createdDate' => $run['created_date'],
            'startedDate' => $run['started_date'],
            'finishedDate' => $run['finished_date'],
            'expiresDate' => $run['expires_date'],
        ];
    }

    private function presentFinding(array $finding): array
    {
        $finding['idfinding'] = (int) $finding['idfinding'];
        $finding['idrun'] = (int) $finding['idrun'];
        $finding['confidence'] = (int) $finding['confidence'];
        $finding['evidence'] = $this->decodeJson($finding['evidence_json'] ?? null, []);
        $finding['recommendation'] = $this->decodeJson($finding['recommendation_json'] ?? null, null);
        unset($finding['evidence_json'], $finding['recommendation_json']);
        return $finding;
    }

    private function presentProposal(array $proposal): array
    {
        $json = $this->decodeJson($proposal['proposal_json'] ?? null, []);
        $operations = [];
        foreach ((new ProposalOperationsDao())->getForProposal((int) $proposal['idproposal']) as $operation) {
            $operations[] = [
                'idOperation' => (int) $operation['idoperation'],
                'position' => (int) $operation['position'],
                'entityType' => $operation['entity_type'],
                'operation' => $operation['operation'],
                'entityId' => $operation['entity_id'],
                'before' => $this->decodeJson($operation['before_json'] ?? null, null),
                'after' => $this->decodeJson($operation['after_json'] ?? null, null),
                'status' => $operation['status'],
            ];
        }

        return [
            'idProposal' => (int) $proposal['idproposal'],
            'idRun' => (int) $proposal['idrun'],
            'idSite' => (int) $proposal['idsite'],
            'idContainer' => $proposal['idcontainer'],
            'draftVersionId' => (int) $proposal['idcontainerversion'],
            'status' => $proposal['status'],
            'summary' => $proposal['summary'],
            'risk' => $json['risk'] ?? null,
            'reason' => $json['reason'] ?? null,
            'evidence' => $json['evidence'] ?? null,
            'validation' => $json['validation'] ?? null,
            'validationRunId' => isset($json['validationRunId']) ? (int) $json['validationRunId'] : null,
            'operations' => $operations,
            'createdBy' => $proposal['created_by'],
            'approvedBy' => $proposal['approved_by'],
            'createdDate' => $proposal['created_date'],
            'approvedDate' => $proposal['approved_date'],
            'appliedDate' => $proposal['applied_date'],
            'validatedDate' => $proposal['validated_date'],
        ];
    }

    private function normaliseArrayInput($value, string $name): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if ($value === null || $value === '') {
            return [];
        }
        throw new \InvalidArgumentException($name . ' must be a JSON object or array.');
    }

    private function requirePost(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'POST') {
            throw new \RuntimeException('This Tracking Assistant API method is POST-only.');
        }
    }

    private function decodeJson($json, $default)
    {
        if (!is_string($json) || $json === '') {
            return $default;
        }

        $decoded = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }
}
