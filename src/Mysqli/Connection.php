<?php

namespace fiber\dbal\Mysqli;

use Doctrine\DBAL\Cache\ArrayResult;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Mysqli\Connection as MysqliConnection;
use Doctrine\DBAL\Driver\Mysqli\Exception\ConnectionError;
use Doctrine\DBAL\Driver\Statement;

/**
 * Wraps DBAL's Mysqli\Connection. query(), exec() and transaction control go through
 * fiber\mysqli\query() (only the calling task waits inside fiber\run()); errors are raised as
 * DBAL's ConnectionError, so DBAL's exception conversion applies unchanged. prepare(), quote(),
 * lastInsertId() and the rest are DBAL's own.
 */
final class Connection implements ConnectionInterface
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
            return new ArrayResult([], []);
        }
        $columns = \array_map(static fn(object $f) => $f->name, $r->fetch_fields());
        $rows = $r->fetch_all(\MYSQLI_NUM);
        $r->free();
        return new ArrayResult($columns, $rows);
    }

    public function quote(string $value): string
    {
        return $this->inner->quote($value);
    }

    public function exec(string $sql): int|string
    {
        $r = $this->run($sql);
        if ($r instanceof \mysqli_result) {
            $r->free();
        }
        return $this->inner->getNativeConnection()->affected_rows;
    }

    public function lastInsertId(): int|string
    {
        return $this->inner->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->run('START TRANSACTION');
    }

    public function commit(): void
    {
        $this->run('COMMIT');
    }

    public function rollBack(): void
    {
        $this->run('ROLLBACK');
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
