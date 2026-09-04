<?php

namespace Piwik\Plugins\TrackingAssistant\Dao;

use Piwik\Common;
use Piwik\Db;

abstract class AbstractDao
{
    protected $tableName;
    protected $tableNamePrefixed;

    public function __construct()
    {
        $this->tableNamePrefixed = Common::prefixTable($this->tableName);
    }

    protected function getDb()
    {
        return Db::get();
    }

    public function getPrefixedTableName(): string
    {
        return $this->tableNamePrefixed;
    }

    abstract public function install(): void;

    public function uninstall(): void
    {
        Db::dropTables([$this->tableNamePrefixed]);
    }
}
