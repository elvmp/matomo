<?php

namespace Piwik\Plugins\TrackingAssistant\Dao;

use Piwik\DbHelper;

class ArtifactsDao extends AbstractDao
{
    protected $tableName = 'tracking_assistant_artifact';

    public function install(): void
    {
        $table = "`idartifact` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `idrun` BIGINT UNSIGNED NOT NULL,
                  `type` VARCHAR(40) NOT NULL,
                  `storage_reference` TEXT NOT NULL,
                  `redaction_level` VARCHAR(40) NOT NULL,
                  `created_date` DATETIME NOT NULL,
                  `expires_date` DATETIME NULL,
                  PRIMARY KEY (`idartifact`),
                  KEY `idx_tracking_assistant_artifact_run` (`idrun`),
                  KEY `idx_tracking_assistant_artifact_expiry` (`expires_date`)";
        DbHelper::createTable($this->tableName, $table);
    }
}
