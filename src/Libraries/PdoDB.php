<?php

namespace TurnkeyAgentic\Core\Libraries;

use PDO;
use PDOException;

class PdoDB
{
    private PDO $pdo;

    /**
     * PdoDB can be constructed in two ways:
     *
     *   1. With an existing PDO instance — preferred for Laravel / any host that
     *      already has a configured connection. Pass `new PdoDB($pdo)`.
     *   2. With no argument — legacy CI4 auto-connect path. Reads `\Config\Database`
     *      and builds its own PDO.
     *
     * The auto-connect path is guarded by class_exists so hosts without CI4
     * don't blow up on the `\Config\Database::connect()` call.
     */
    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
            return;
        }
        $this->connect();
    }

    private function connect(): void
    {
        if (! class_exists('\\Config\\Database')) {
            throw new \RuntimeException('PdoDB auto-connect requires CodeIgniter\'s \\Config\\Database. On non-CI hosts, inject a PDO instance into the constructor.');
        }

        $db  = \Config\Database::connect();
        $dsn = "mysql:host={$db->hostname};dbname={$db->database};charset=utf8mb4";

        $this->pdo = new PDO($dsn, $db->username, $db->password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 30,
        ]);
    }

    private function reconnect(): void
    {
        if (function_exists('log_message')) {
            log_message('info', 'PdoDB: reconnecting...');
        } else {
            error_log('PdoDB: reconnecting...');
        }
        $this->connect();
    }

    private function isGoneAwayError(PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'MySQL server has gone away')
            || str_contains($e->getMessage(), 'Lost connection to MySQL')
            || $e->getCode() == 2006
            || $e->getCode() == 2013;
    }

    private function execute(string $sql, array $params, callable $run): mixed
    {
        try {
            return $run($this->pdo->prepare($sql), $params);
        } catch (PDOException $e) {
            if ($this->isGoneAwayError($e)) {
                $this->reconnect();
                try {
                    return $run($this->pdo->prepare($sql), $params);
                } catch (PDOException $e2) {
                    $this->logError('PdoDB: retry failed - ' . $e2->getMessage() . ' | SQL: ' . $sql);
                    throw $e2;
                }
            }
            $this->logError('PdoDB: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw $e;
        }
    }

    private function logError(string $msg): void
    {
        if (function_exists('log_message')) {
            log_message('error', $msg);
        } else {
            error_log($msg);
        }
    }

    public function pdo(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params, function ($sth, $params) {
            $sth->execute(count($params) ? $params : []);
            return $sth->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    public function pdosingle(string $sql, array $params = []): array
    {
        $result = $this->pdo($sql, $params);
        return count($result) > 0 ? $result[0] : [];
    }

    public function ex(string $sql, array $params = []): string|false
    {
        return $this->execute($sql, $params, function ($sth, $params) {
            $sth->execute(count($params) ? $params : []);
            return $this->pdo->lastInsertId();
        });
    }

    public function updateEx(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params, function ($sth, $params) {
            $sth->execute(count($params) ? $params : []);
            return $sth->rowCount();
        });
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function getByIndex(string $sql, string $index, array $params = [], bool $toupper = false): array
    {
        $results = $this->pdo($sql, $params);
        $this->reindexByColumn($results, $index, $toupper);
        return $results;
    }

    public function reindexByColumn(array &$ar, string $col, bool $toupper = false): void
    {
        $r = [];
        foreach ($ar as $row) {
            if (!isset($row[$col]) || strlen($row[$col]) === 0) {
                continue;
            }

            $key   = $toupper ? strtoupper($row[$col]) : $row[$col];
            $r[$key] = $row;
        }
        $ar = $r;
    }

    public function getTableFields(string $table): array
    {
        $sth = $this->pdo->prepare("SELECT * FROM `{$table}` WHERE 1 = 0");
        $sth->execute();
        $keys = [];
        for ($i = 0; $meta = $sth->getColumnMeta($i); $i++) {
            $keys[] = $meta['name'];
        }
        return $keys;
    }

    public function insertIntoEntity(array $values, string $table, bool $removeIdField = true): string|false
    {
        $values = $removeIdField ? array_filter($values, fn($k) => strtoupper($k) !== 'ID', ARRAY_FILTER_USE_KEY) : $values;
        $keys   = array_keys($values);
        $sql    = "INSERT INTO `{$table}` (" . implode(', ', array_map(fn($k) => "`{$k}`", $keys)) . ")"
            . " VALUES (" . implode(', ', array_map(fn($k) => ":{$k}", $keys)) . ")";

        return $this->ex($sql, $this->tokenize($values, false));
    }

    public function updateEntity(array $values, string $table): int
    {
        $sets = [];
        foreach (array_keys($values) as $key) {
            if (strtoupper($key) === 'ID') {
                continue;
            }
            $sets[] = "`{$key}` = :{$key}";
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `id` = :ID";

        return $this->updateEx($sql, $this->tokenize($values, false));
    }

    public function tokenize(array $row, bool $removeIdField = true): array
    {
        if ($removeIdField) {
            $row = array_filter($row, fn($k) => strtoupper($k) !== 'ID', ARRAY_FILTER_USE_KEY);
        }
        $tokenized = [];
        foreach ($row as $k => $v) {
            $tokenized[":{$k}"] = $v;
        }
        return $tokenized;
    }
}
