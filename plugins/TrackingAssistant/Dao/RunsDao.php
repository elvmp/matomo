<?php

namespace Piwik\Plugins\TrackingAssistant\Dao;

use Piwik\DbHelper;

class RunsDao extends AbstractDao
{
    protected $tableName = 'tracking_assistant_run';

    public function install(): void
    {
        $table = "`idrun` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `idsite` INT UNSIGNED NOT NULL,
                  `idcontainer` VARCHAR(255) NULL,
                  `login` VARCHAR(100) NOT NULL,
                  `type` VARCHAR(40) NOT NULL,
                  `status` VARCHAR(40) NOT NULL,
                  `target_url` TEXT NOT NULL,
                  `target_url_redacted` TEXT NOT NULL,
                  `browser` VARCHAR(30) NOT NULL,
                  `scenario_json` MEDIUMTEXT NULL,
                  `progress_json` MEDIUMTEXT NULL,
                  `worker_id` VARCHAR(191) NULL,
                  `lease_until` DATETIME NULL,
                  `heartbeat_date` DATETIME NULL,
                  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                  `created_date` DATETIME NOT NULL,
                  `started_date` DATETIME NULL,
                  `finished_date` DATETIME NULL,
                  `expires_date` DATETIME NULL,
                  PRIMARY KEY (`idrun`),
                  KEY `idx_tracking_assistant_run_site_created` (`idsite`, `created_date`),
                  KEY `idx_tracking_assistant_run_queue` (`status`, `lease_until`, `created_date`)";

        DbHelper::createTable($this->tableName, $table);
    }

    public function createQueuedRun(array $run): int
    {
        $now = $this->now();
        $data = [
            'idsite' => (int) $run['idsite'],
            'idcontainer' => $run['idcontainer'] ?: null,
            'login' => (string) $run['login'],
            'type' => (string) $run['type'],
            'status' => 'queued',
            'target_url' => (string) $run['target_url'],
            'target_url_redacted' => (string) $run['target_url_redacted'],
            'browser' => (string) $run['browser'],
            'scenario_json' => json_encode($run['scenario'], JSON_UNESCAPED_SLASHES),
            'progress_json' => json_encode(['phase' => 'queued']),
            'created_date' => $now,
            'expires_date' => $run['expires_date'] ?? null,
        ];

        $this->getDb()->insert($this->tableNamePrefixed, $data);
        return (int) $this->getDb()->lastInsertId();
    }

    public function get(int $idRun): ?array
    {
        $row = $this->getDb()->fetchRow(
            "SELECT * FROM {$this->tableNamePrefixed} WHERE idrun = ?",
            [$idRun]
        );

        return $row ?: null;
    }

    public function getForSite(int $idSite, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        return $this->getDb()->fetchAll(
            "SELECT * FROM {$this->tableNamePrefixed} WHERE idsite = ? ORDER BY idrun DESC LIMIT {$limit}",
            [$idSite]
        );
    }

    public function cancel(int $idRun): bool
    {
        $stmt = $this->getDb()->query(
            "UPDATE {$this->tableNamePrefixed}
             SET status = 'cancelled', finished_date = ?, lease_until = NULL
             WHERE idrun = ? AND status IN ('created', 'queued')",
            [$this->now(), $idRun]
        );

        return $stmt->rowCount() === 1;
    }

    public function claimNext(string $workerId, int $leaseSeconds = 90): ?array
    {
        $now = $this->now();
        $candidate = $this->getDb()->fetchRow(
            "SELECT idrun
             FROM {$this->tableNamePrefixed}
             WHERE status = 'queued'
                OR (status = 'running' AND lease_until IS NOT NULL AND lease_until < ?)
             ORDER BY created_date ASC
             LIMIT 1",
            [$now]
        );

        if (!$candidate) {
            return null;
        }

        $leaseUntil = gmdate('Y-m-d H:i:s', time() + max(30, $leaseSeconds));
        $stmt = $this->getDb()->query(
            "UPDATE {$this->tableNamePrefixed}
             SET status = 'running', worker_id = ?, lease_until = ?, heartbeat_date = ?,
                 started_date = COALESCE(started_date, ?), attempts = attempts + 1,
                 progress_json = ?
             WHERE idrun = ?
               AND (status = 'queued' OR (status = 'running' AND lease_until IS NOT NULL AND lease_until < ?))",
            [
                $workerId,
                $leaseUntil,
                $now,
                $now,
                json_encode(['phase' => 'opening_website']),
                (int) $candidate['idrun'],
                $now,
            ]
        );

        if ($stmt->rowCount() !== 1) {
            return null;
        }

        return $this->get((int) $candidate['idrun']);
    }

    public function renewLease(int $idRun, string $workerId, int $leaseSeconds = 90): bool
    {
        $now = $this->now();
        $leaseUntil = gmdate('Y-m-d H:i:s', time() + max(30, $leaseSeconds));
        $stmt = $this->getDb()->query(
            "UPDATE {$this->tableNamePrefixed}
             SET lease_until = ?, heartbeat_date = ?
             WHERE idrun = ? AND status = 'running' AND worker_id = ?",
            [$leaseUntil, $now, $idRun, $workerId]
        );

        return $stmt->rowCount() === 1;
    }

    public function updateProgress(int $idRun, string $workerId, array $progress): void
    {
        $this->getDb()->query(
            "UPDATE {$this->tableNamePrefixed}
             SET progress_json = ?, heartbeat_date = ?
             WHERE idrun = ? AND status = 'running' AND worker_id = ?",
            [json_encode($progress, JSON_UNESCAPED_SLASHES), $this->now(), $idRun, $workerId]
        );
    }

    public function markCompleted(int $idRun, string $workerId, array $result): bool
    {
        $stmt = $this->getDb()->query(
            "UPDATE {$this->tableNamePrefixed}
             SET status = 'completed', progress_json = ?, finished_date = ?,
                 lease_until = NULL, heartbeat_date = ?
             WHERE idrun = ? AND status = 'running' AND worker_id = ?",
            [
                json_encode(['phase' => 'completed', 'result' => $result], JSON_UNESCAPED_SLASHES),
                $this->now(),
                $this->now(),
                $idRun,
                $workerId,
            ]
        );

        return $stmt->rowCount() === 1;
    }

    public function markFailed(int $idRun, string $workerId, string $message): bool
    {
        $stmt = $this->getDb()->query(
            "UPDATE {$this->tableNamePrefixed}
             SET status = 'failed', progress_json = ?, finished_date = ?,
                 lease_until = NULL, heartbeat_date = ?
             WHERE idrun = ? AND status = 'running' AND worker_id = ?",
            [
                json_encode(['phase' => 'failed', 'error' => $message]),
                $this->now(),
                $this->now(),
                $idRun,
                $workerId,
            ]
        );

        return $stmt->rowCount() === 1;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
