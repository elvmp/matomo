<?php

namespace Piwik\Plugins\TrackingAssistant\Dao;

use Piwik\DbHelper;

class ProposalOperationsDao extends AbstractDao
{
    protected $tableName = 'tracking_assistant_proposal_operation';

    public function install(): void
    {
        $table = "`idoperation` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `idproposal` BIGINT UNSIGNED NOT NULL,
                  `position` SMALLINT UNSIGNED NOT NULL,
                  `entity_type` VARCHAR(30) NOT NULL,
                  `operation` VARCHAR(30) NOT NULL,
                  `entity_id` VARCHAR(255) NULL,
                  `before_json` MEDIUMTEXT NULL,
                  `after_json` MEDIUMTEXT NULL,
                  `status` VARCHAR(40) NOT NULL,
                  PRIMARY KEY (`idoperation`),
                  KEY `idx_tracking_assistant_operation_proposal` (`idproposal`, `position`)";
        DbHelper::createTable($this->tableName, $table);
    }

    public function insertOperation(int $idProposal, int $position, array $operation): int
    {
        $this->getDb()->insert($this->tableNamePrefixed, [
            'idproposal' => $idProposal,
            'position' => $position,
            'entity_type' => (string) ($operation['entity'] ?? ''),
            'operation' => (string) ($operation['operation'] ?? ''),
            'entity_id' => isset($operation['id']) ? (string) $operation['id'] : null,
            'before_json' => isset($operation['before']) ? json_encode($operation['before'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'after_json' => isset($operation['after']) ? json_encode($operation['after'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'status' => 'pending',
        ]);
        return (int) $this->getDb()->lastInsertId();
    }

    public function getForProposal(int $idProposal, bool $reverse = false): array
    {
        $direction = $reverse ? 'DESC' : 'ASC';
        return $this->getDb()->fetchAll(
            "SELECT * FROM {$this->tableNamePrefixed} WHERE idproposal = ? ORDER BY position {$direction}, idoperation {$direction}",
            [$idProposal]
        );
    }

    public function setStatus(int $idOperation, string $status): void
    {
        $this->getDb()->update($this->tableNamePrefixed, ['status' => $status], 'idoperation = ' . (int) $idOperation);
    }
}
