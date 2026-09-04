<?php

namespace Piwik\Plugins\TrackingAssistant\Dao;

use Piwik\DbHelper;

class AuditDao extends AbstractDao
{
    protected $tableName = 'tracking_assistant_audit';

    public function install(): void
    {
        $table = "`idaudit` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `idrun` BIGINT UNSIGNED NULL,
                  `idproposal` BIGINT UNSIGNED NULL,
                  `login` VARCHAR(100) NOT NULL,
                  `action` VARCHAR(100) NOT NULL,
                  `metadata_json` MEDIUMTEXT NULL,
                  `created_date` DATETIME NOT NULL,
                  PRIMARY KEY (`idaudit`),
                  KEY `idx_tracking_assistant_audit_run` (`idrun`),
                  KEY `idx_tracking_assistant_audit_proposal` (`idproposal`)";
        DbHelper::createTable($this->tableName, $table);
    }

    public function record(?int $idRun, ?int $idProposal, string $login, string $action, array $metadata = []): void
    {
        $this->getDb()->insert($this->tableNamePrefixed, [
            'idrun' => $idRun,
            'idproposal' => $idProposal,
            'login' => $login,
            'action' => $action,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            'created_date' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
