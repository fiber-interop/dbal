<?php

namespace fiber\dbal\V3\PgSQL;

use Doctrine\DBAL\Driver\PgSQL\Connection as DbalConnection;
use Doctrine\DBAL\Driver\PgSQL\ConvertParameters;
use Doctrine\DBAL\Driver\PgSQL\Exception;
use Doctrine\DBAL\Driver\PgSQL\Result;
use Doctrine\DBAL\Driver\ServerInfoAwareConnection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\SQL\Parser;
use PgSql\Connection as PgSqlConnection;
use PgSql\Result as PgSqlResult;

/**
 * DBAL 3 counterpart of fiber\dbal\PgSQL\Connection: DBAL's PgSQL\Connection logic with
 * pg_send_* and a cooperative wait. connect, quote and server version are DBAL's own.
 */
final class Connection implements ServerInfoAwareConnection
{
    private readonly PgSqlConnection $connection;
    private readonly Parser $parser;

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

    public function quote($value, $type = ParameterType::STRING)
    {
        return $this->inner->quote($value, $type);
    }

    public function exec(string $sql): int
    {
        return $this->query($sql)->rowCount();
    }

    public function lastInsertId($name = null)
    {
        return $name === null
            ? $this->query('SELECT LASTVAL()')->fetchOne()
            : $this->query('SELECT CURRVAL(' . $this->quote($name) . ')')->fetchOne();
    }

    public function beginTransaction(): bool
    {
        $this->exec('BEGIN');

        return true;
    }

    public function commit(): bool
    {
        $this->exec('COMMIT');

        return true;
    }

    public function rollBack(): bool
    {
        $this->exec('ROLLBACK');

        return true;
    }

    public function getServerVersion(): string
    {
        return $this->inner->getServerVersion();
    }

    public function getNativeConnection(): PgSqlConnection
    {
        return $this->connection;
    }

    /** Same as fiber\dbal\PgSQL\Connection::result() (that class cannot load under DBAL 3). */
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
