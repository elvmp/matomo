<?php

namespace Piwik\Plugins\TrackingAssistant;

use Piwik\Common;
use Piwik\Plugin;
use Piwik\Plugins\TrackingAssistant\Access\Capability\RunTrackingDiagnostics;
use Piwik\Plugins\TrackingAssistant\Dao\ArtifactsDao;
use Piwik\Plugins\TrackingAssistant\Dao\AuditDao;
use Piwik\Plugins\TrackingAssistant\Dao\FindingsDao;
use Piwik\Plugins\TrackingAssistant\Dao\ProposalOperationsDao;
use Piwik\Plugins\TrackingAssistant\Dao\ProposalsDao;
use Piwik\Plugins\TrackingAssistant\Dao\RunsDao;

class TrackingAssistant extends Plugin
{
    public function registerEvents()
    {
        return [
            'Access.Capability.addCapabilities' => 'addCapabilities',
            'Db.getTablesInstalled' => 'getTablesInstalled',
        ];
    }

    public function install()
    {
        foreach ($this->getAllDaos() as $dao) {
            $dao->install();
        }
    }

    public function uninstall()
    {
        foreach (array_reverse($this->getAllDaos()) as $dao) {
            $dao->uninstall();
        }
    }

    public function addCapabilities(&$capabilities)
    {
        $capabilities[] = new RunTrackingDiagnostics();
    }

    public function getTablesInstalled(&$allTablesInstalled)
    {
        foreach ([
            'tracking_assistant_run',
            'tracking_assistant_finding',
            'tracking_assistant_proposal',
            'tracking_assistant_proposal_operation',
            'tracking_assistant_audit',
            'tracking_assistant_artifact',
        ] as $table) {
            $allTablesInstalled[] = Common::prefixTable($table);
        }
    }

    private function getAllDaos(): array
    {
        return [
            new RunsDao(),
            new FindingsDao(),
            new ProposalsDao(),
            new ProposalOperationsDao(),
            new AuditDao(),
            new ArtifactsDao(),
        ];
    }
}
