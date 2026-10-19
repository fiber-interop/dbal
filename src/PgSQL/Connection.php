<?php

namespace fiber\dbal\PgSQL;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Exception\NoIdentityValue;
use Doctrine\DBAL\Driver\PgSQL\Connection as DbalConnection;
use Doctrine\DBAL\Driver\PgSQL\ConvertParameters;
use Doctrine\DBAL\Driver\PgSQL\Exception;
use Doctrine\DBAL\Driver\PgSQL\Result;
use Doctrine\DBAL\SQL\Parser;
use PgSql\Connection as PgSqlConnection;
use PgSql\Result as PgSqlResult;

/**
 * DBAL's PgSQL\Connection with the wait for each result made cooperative: queries and prepared
 * statements are sent with pg_send_query() / pg_send_prepare() / pg_send_execute() exactly as
 * DBAL does, and inside fiber\run() the task waits on pg_socket() before pg_get_result().
 * Results, exceptions (SQLSTATE included) and quoting are DBAL's own. Outside run() it is DBAL's
 * blocking behaviour.
 */
final class Connection implements ConnectionInterface
{
    private readonly PgSqlConnection $connection;
    private readonly Parser $parser;

    /** Holds DBAL's connection, which owns the native one (it closes it on destruct). */
    public function __construct(private readonly DbalConnection $inner)
    {
        $this->connection = $inner->getNativeConnection();
        $this->parser = new Parser(false);
    }

    public function prepare(string $sql): Statement
    {
        $visitor = new ConvertParameters();
        $this->parser->parse($sql, $visitor);
        $name = \uniqid('dbal', true);
        self::result($this->connection, @\pg_send_prepare($this->connection, $name, $visitor->getSQL()));
        return new Statement($this->connection, $name, $visitor->getParameterMap());
    }

    public function query(string $sql): Result
    {
        return new Result(self::result($this->connection, @\pg_send_query($this->connection, $sql)));
    }

    public function quote(string $value): string
    {
        return $this->inner->quote($value);
    }

    public function exec(string $sql): int
    {
        return $this->query($sql)->rowCount();
    }

    public function lastInsertId(): int|string
    {
        try {
            return $this->query('SELECT LASTVAL()')->fetchOne();
        } catch (Exception $e) {
            if ($e->getSQLState() === '55000') {
                throw NoIdentityValue::new($e);
            }
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        $this->exec('BEGIN');
    }

    public function commit(): void
    {
        $this->exec('COMMIT');
    }

    public function rollBack(): void
    {
        $this->exec('ROLLBACK');
    }

    public function getServerVersion(): string
    {
        return $this->inner->getServerVersion();
    }

    public function getNativeConnection(): PgSqlConnection
    {
        return $this->connection;
    }

    /** After a pg_send_*(): wait (cooperatively inside run()) and return the result or throw as DBAL does. */
    public static function result(PgSqlConnection $c, bool $sent): PgSqlResult
    {
        if (!$sent) {
            throw new Exception(\pg_last_error($c));
        }
        if (\fiber\Registry::$active !== []) {
            $socket = \pg_socket($c);
            while (\pg_consume_input($c) && \pg_connection_busy($c)) {
                \fiber\readable($socket);
            }
        }
        $result = @\pg_get_result($c);
        \assert($result !== false);
        if ((bool) \pg_result_error($result)) {
            throw Exception::fromResult($result);
        }
        return $result;
    }
}
