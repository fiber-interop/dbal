<?php

namespace fiber\dbal\V3\Mysqli;

use Doctrine\DBAL\Cache\ArrayResult;
use Doctrine\DBAL\Driver\Mysqli\Connection as MysqliConnection;
use Doctrine\DBAL\Driver\Mysqli\Exception\ConnectionError;
use Doctrine\DBAL\Driver\ServerInfoAwareConnection;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/**
 * DBAL 3 counterpart of fiber\dbal\Mysqli\Connection: query(), exec() and transaction control go
 * through fiber\mysqli\query(); errors are DBAL's ConnectionError. The rest is DBAL's own.
 */
final class Connection implements ServerInfoAwareConnection
{
    public function __construct(private readonly MysqliConnection $inner)
    {
    }

    public function prepare(string $sql): Statement
    {
        return $this->inner->prepare($sql);
    }

    public function query(string $sql): ArrayResult
    {
        $r = $this->run($sql);
        if (!$r instanceof \mysqli_result) {
            return new ArrayResult([]);
        }
        // DBAL 3's ArrayResult takes associative rows.
        $rows = $r->fetch_all(\MYSQLI_ASSOC);
        $r->free();

        return new ArrayResult($rows);
    }

    public function quote($value, $type = ParameterType::STRING)
    {
        return $this->inner->quote($value, $type);
    }

    public function exec(string $sql): int
    {
        $r = $this->run($sql);
        if ($r instanceof \mysqli_result) {
            $r->free();
        }

        return (int) $this->inner->getNativeConnection()->affected_rows;
    }

    public function lastInsertId($name = null)
    {
        return $this->inner->lastInsertId($name);
    }

    public function beginTransaction(): bool
    {
        $this->run('START TRANSACTION');

        return true;
    }

    public function commit(): bool
    {
        $this->run('COMMIT');

        return true;
    }

    public function rollBack(): bool
    {
        $this->run('ROLLBACK');

        return true;
    }

    public function getServerVersion(): string
    {
        return $this->inner->getServerVersion();
    }

    public function getNativeConnection(): \mysqli
    {
        return $this->inner->getNativeConnection();
    }

    private function run(string $sql): \mysqli_result|true
    {
        $m = $this->inner->getNativeConnection();
        try {
            $r = \fiber\mysqli\query($m, $sql);
        } catch (\mysqli_sql_exception $e) {
            throw ConnectionError::upcast($e);
        }

        return $r === false ? throw ConnectionError::new($m) : $r;
    }
}
