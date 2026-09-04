<?php

namespace Piwik\Plugins\TrackingAssistant\Dao;

use Piwik\DbHelper;

class FindingsDao extends AbstractDao
{
    protected $tableName = 'tracking_assistant_finding';

    public function install(): void
    {
        $table = "`idfinding` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `idrun` BIGINT UNSIGNED NOT NULL,
                  `code` VARCHAR(100) NOT NULL,
                  `severity` VARCHAR(20) NOT NULL,
                  `confidence` TINYINT UNSIGNED NOT NULL,
                  `summary` TEXT NOT NULL,
                  `evidence_json` MEDIUMTEXT NULL,
                  `recommendation_json` MEDIUMTEXT NULL,
                  `created_date` DATETIME NOT NULL,
                  PRIMARY KEY (`idfinding`),
                  KEY `idx_tracking_assistant_finding_run` (`idrun`)";
        DbHelper::createTable($this->tableName, $table);
    }

    public function insertFinding(int $idRun, array $finding): int
    {
        $this->getDb()->insert($this->tableNamePrefixed, [
            'idrun' => $idRun,
            'code' => (string) $finding['code'],
            'severity' => (string) $finding['severity'],
            'confidence' => max(0, min(100, (int) $finding['confidence'])),
            'summary' => (string) $finding['summary'],
            'evidence_json' => json_encode($finding['evidence'] ?? [], JSON_UNESCAPED_SLASHES),
            'recommendation_json' => json_encode($finding['recommendation'] ?? null, JSON_UNESCAPED_SLASHES),
            'created_date' => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $this->getDb()->lastInsertId();
    }

    public function get(int $idFinding): ?array
    {
        $row = $this->getDb()->fetchRow(
            "SELECT * FROM {$this->tableNamePrefixed} WHERE idfinding = ?",
            [$idFinding]
        );
        return $row ?: null;
    }

    public function getForRun(int $idRun): array
    {
        return $this->getDb()->fetchAll(
            "SELECT * FROM {$this->tableNamePrefixed} WHERE idrun = ? ORDER BY idfinding ASC",
            [$idRun]
        );
    }
}
