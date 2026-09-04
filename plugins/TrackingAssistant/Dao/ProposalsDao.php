<?php

namespace Piwik\Plugins\TrackingAssistant\Dao;

use Piwik\DbHelper;

class ProposalsDao extends AbstractDao
{
    protected $tableName = 'tracking_assistant_proposal';

    public function install(): void
    {
        $table = "`idproposal` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `idrun` BIGINT UNSIGNED NOT NULL,
                  `idsite` INT UNSIGNED NOT NULL,
                  `idcontainer` VARCHAR(255) NOT NULL,
                  `idcontainerversion` BIGINT UNSIGNED NULL,
                  `status` VARCHAR(40) NOT NULL,
                  `summary` TEXT NOT NULL,
                  `proposal_json` MEDIUMTEXT NOT NULL,
                  `snapshot_json` MEDIUMTEXT NULL,
                  `snapshot_hash` CHAR(64) NULL,
                  `created_by` VARCHAR(100) NOT NULL,
                  `approved_by` VARCHAR(100) NULL,
                  `created_date` DATETIME NOT NULL,
                  `approved_date` DATETIME NULL,
                  `applied_date` DATETIME NULL,
                  `validated_date` DATETIME NULL,
                  PRIMARY KEY (`idproposal`),
                  KEY `idx_tracking_assistant_proposal_run` (`idrun`),
                  KEY `idx_tracking_assistant_proposal_site_container` (`idsite`, `idcontainer`)";
        DbHelper::createTable($this->tableName, $table);
    }

    public function create(array $proposal): int
    {
        $this->getDb()->insert($this->tableNamePrefixed, [
            'idrun' => (int) $proposal['idrun'],
            'idsite' => (int) $proposal['idsite'],
            'idcontainer' => (string) $proposal['idcontainer'],
            'idcontainerversion' => (int) $proposal['idcontainerversion'],
            'status' => (string) ($proposal['status'] ?? 'ready'),
            'summary' => (string) $proposal['summary'],
            'proposal_json' => json_encode($proposal['proposal'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_by' => (string) $proposal['created_by'],
            'created_date' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $this->getDb()->lastInsertId();
    }

    public function get(int $idProposal): ?array
    {
        $row = $this->getDb()->fetchRow("SELECT * FROM {$this->tableNamePrefixed} WHERE idproposal = ?", [$idProposal]);
        return $row ?: null;
    }

    public function getForRun(int $idRun): array
    {
        return $this->getDb()->fetchAll("SELECT * FROM {$this->tableNamePrefixed} WHERE idrun = ? ORDER BY idproposal ASC", [$idRun]);
    }

    public function setStatus(int $idProposal, string $status): void
    {
        $fields = ['status' => $status];
        if ($status === 'applied') {
            $fields['applied_date'] = gmdate('Y-m-d H:i:s');
        }
        if (in_array($status, ['passed', 'failed', 'rolled_back'], true)) {
            $fields['validated_date'] = gmdate('Y-m-d H:i:s');
        }
        $this->getDb()->update($this->tableNamePrefixed, $fields, 'idproposal = ' . (int) $idProposal);
    }

    public function approve(int $idProposal, string $login): bool
    {
        $stmt = $this->getDb()->query(
            "UPDATE {$this->tableNamePrefixed} SET status = 'approved', approved_by = ?, approved_date = ? WHERE idproposal = ? AND status = 'ready'",
            [$login, gmdate('Y-m-d H:i:s'), $idProposal]
        );
        return $stmt->rowCount() === 1;
    }

    public function dismiss(int $idProposal): bool
    {
        $stmt = $this->getDb()->query(
            "UPDATE {$this->tableNamePrefixed} SET status = 'dismissed' WHERE idproposal = ? AND status IN ('draft','ready')",
            [$idProposal]
        );
        return $stmt->rowCount() === 1;
    }

    public function storeSnapshot(int $idProposal, string $snapshot): void
    {
        $this->getDb()->update($this->tableNamePrefixed, [
            'snapshot_json' => $snapshot,
            'snapshot_hash' => hash('sha256', $snapshot),
        ], 'idproposal = ' . (int) $idProposal);
    }

    public function updateProposalJson(int $idProposal, array $proposal): void
    {
        $this->getDb()->update($this->tableNamePrefixed, [
            'proposal_json' => json_encode($proposal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], 'idproposal = ' . (int) $idProposal);
    }
}
