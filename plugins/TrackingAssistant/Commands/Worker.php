<?php

namespace Piwik\Plugins\TrackingAssistant\Commands;

use Piwik\Access;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\TrackingAssistant\Dao\AuditDao;
use Piwik\Plugins\TrackingAssistant\Dao\FindingsDao;
use Piwik\Plugins\TrackingAssistant\Dao\RunsDao;
use Piwik\Plugins\TrackingAssistant\Service\Diagnostic\DiagnosisEngine;
use Piwik\Plugins\TrackingAssistant\Service\Proposal\ProposalService;
use Piwik\Plugins\TrackingAssistant\Service\Runner\RunnerClient;
use Piwik\Plugins\TrackingAssistant\Service\TagManager\TagManagerAdapter;
use Piwik\Plugins\TrackingAssistant\Service\Validation\ValidationFinalizer;
use Piwik\Plugins\TrackingAssistant\SystemSettings;

class Worker extends ConsoleCommand
{
    protected function configure()
    {
        $this->setName('tracking-assistant:worker');
        $this->setDescription('Process queued Tracking Assistant browser diagnostics.');
        $this->addNoValueOption('once', null, 'Process at most one queued diagnostic and exit.');
    }

    protected function doExecute(): int
    {
        $once = (bool) $this->getInput()->getOption('once');
        $output = $this->getOutput();
        $settings = new SystemSettings();

        if (!(bool) $settings->enabled->getValue()) {
            $output->writeln('<comment>Tracking Assistant is disabled.</comment>');
            return self::SUCCESS;
        }

        $workerId = sprintf('%s:%d:%s', gethostname() ?: 'worker', getmypid(), bin2hex(random_bytes(3)));
        $leaseSeconds = max(90, (int) $settings->runTimeout->getValue() + 30);
        $runs = new RunsDao();
        $runner = new RunnerClient($settings);
        $audit = new AuditDao();
        $adapter = new TagManagerAdapter();
        $diagnosis = new DiagnosisEngine();
        $proposalService = new ProposalService($adapter);
        $validationFinalizer = new ValidationFinalizer($adapter);

        $output->writeln(sprintf('<info>Tracking Assistant worker %s started.</info>', $workerId));

        while (true) {
            $run = $runs->claimNext($workerId, $leaseSeconds);
            if (!$run) {
                if ($once) {
                    return self::SUCCESS;
                }
                sleep(1);
                continue;
            }

            $idRun = (int) $run['idrun'];
            $isValidation = ($run['type'] ?? '') === 'validation';
            $output->writeln(sprintf('Running %s #%d: %s', $isValidation ? 'validation' : 'diagnostic', $idRun, $run['target_url_redacted']));
            $audit->record($idRun, null, $run['login'], 'runner_claimed', ['workerId' => $workerId]);

            try {
                $runs->updateProgress($idRun, $workerId, ['phase' => $isValidation ? 'validating' : 'inspecting_tag_manager']);

                $graph = null;
                $inspection = [];
                if (!$isValidation && !empty($run['idcontainer'])) {
                    // startDiagnostic already verified the requesting user can view this MTM container.
                    // The async CLI worker cannot inherit that web session, so the internal continuation
                    // performs read-only inspection as the system user. It never initiates a draft mutation.
                    $graph = Access::doAsSuperUser(function () use ($adapter, $run) {
                        return $adapter->getGraph((int) $run['idsite'], (string) $run['idcontainer']);
                    });
                    $inspection = ['cssSelectors' => $graph->getClickCssSelectorInspections()];
                }

                $runs->updateProgress($idRun, $workerId, ['phase' => 'reproducing_interaction']);
                $preview = $isValidation && !empty($run['idcontainer'])
                    ? ['containerId' => (string) $run['idcontainer']]
                    : [];
                $result = $runner->execute($run, $inspection, $preview);

                if (($result['status'] ?? '') !== 'completed') {
                    throw new \RuntimeException($this->runnerFailureMessage($result));
                }

                if ($isValidation) {
                    // Validation is the continuation of an explicitly approved mutation. System elevation
                    // here is limited to rollback/Preview restoration so failed changes are never stranded.
                    $comparison = Access::doAsSuperUser(function () use ($validationFinalizer, $run, $result) {
                        return $validationFinalizer->finalise($run, $result);
                    });
                    $result['validation'] = $comparison;
                    $runs->markCompleted($idRun, $workerId, $result);
                    $output->writeln(sprintf('<info>Validation #%d completed: %s.</info>', $idRun, $comparison['outcome']));
                } else {
                    $runs->updateProgress($idRun, $workerId, ['phase' => 'preparing_findings']);
                    $findings = $diagnosis->diagnose($run, $result, $graph);
                    $findingDao = new FindingsDao();
                    $proposalIds = [];

                    foreach ($findings as $finding) {
                        $idFinding = $findingDao->insertFinding($idRun, $finding);
                        if (!empty($finding['recommendation']) && !empty($run['idcontainer'])) {
                            $idProposal = Access::doAsSuperUser(function () use ($proposalService, $run, $idFinding, $finding) {
                                return $proposalService->createFromFinding($run, $idFinding, $finding);
                            });
                            if ($idProposal) {
                                $proposalIds[] = (int) $idProposal;
                            }
                        }
                    }

                    $result['findingCount'] = count($findings);
                    $result['proposalIds'] = $proposalIds;
                    $runs->markCompleted($idRun, $workerId, $result);
                    $audit->record($idRun, null, $run['login'], 'diagnostic_completed', [
                        'trackingRequestCount' => count($result['trackingRequests'] ?? []),
                        'errorCount' => count($result['errors'] ?? []),
                        'findingCount' => count($findings),
                        'proposalCount' => count($proposalIds),
                    ]);
                    $output->writeln(sprintf('<info>Diagnostic #%d completed with %d finding(s).</info>', $idRun, count($findings)));
                }
            } catch (\Throwable $e) {
                $message = $this->safeErrorMessage($e->getMessage());
                if ($isValidation) {
                    try {
                        Access::doAsSuperUser(function () use ($validationFinalizer, $run, $message) {
                            $validationFinalizer->failAndRollback($run, $message);
                        });
                    } catch (\Throwable $rollbackError) {
                        $message .= ' Recovery error: ' . $this->safeErrorMessage($rollbackError->getMessage());
                    }
                }
                $runs->markFailed($idRun, $workerId, $message);
                $audit->record($idRun, null, $run['login'], $isValidation ? 'validation_run_failed' : 'diagnostic_failed', ['message' => $message]);
                $output->writeln(sprintf('<error>%s #%d failed: %s</error>', $isValidation ? 'Validation' : 'Diagnostic', $idRun, $message));
            }

            if ($once) {
                return self::SUCCESS;
            }
        }
    }

    private function runnerFailureMessage(array $result): string
    {
        foreach ((array) ($result['errors'] ?? []) as $error) {
            if (is_array($error) && !empty($error['message'])) {
                return 'Browser diagnostic failed: ' . (string) $error['message'];
            }
        }
        return 'Browser diagnostic failed without a usable error message.';
    }

    private function safeErrorMessage(string $message): string
    {
        $message = preg_replace('/(authorization|cookie|token|password|secret)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $message);
        return substr((string) $message, 0, 1000);
    }
}
