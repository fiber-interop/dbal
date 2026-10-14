<?php

namespace async\dbal\V3\PgSQL;

use Doctrine\DBAL\Driver\PgSQL\Exception\UnknownParameter;
use Doctrine\DBAL\Driver\PgSQL\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use PgSql\Connection as PgSqlConnection;

/** DBAL 3's PgSQL\Statement (copied, as it is final) executing through Connection::result(). */
final class Statement implements StatementInterface
{
    /** @var array<int, mixed> */
    private array $parameters = [];

    /** @var array<int, int> */
    private array $parameterTypes = [];

    /** @param array<array-key, int> $parameterMap */
    public function __construct(
        private readonly PgSqlConnection $connection,
        private readonly string $name,
        private readonly array $parameterMap,
    ) {
    }

    public function __destruct()
    {
        @\pg_query($this->connection, 'DEALLOCATE ' . \pg_escape_identifier($this->connection, $this->name));
    }

    public function bindValue($param, $value, $type = ParameterType::STRING): bool
    {
        if (!isset($this->parameterMap[$param])) {
            throw UnknownParameter::new((string) $param);
        }
        $this->parameters[$this->parameterMap[$param]] = $value;
        $this->parameterTypes[$this->parameterMap[$param]] = $type;

        return true;
    }

    /** Deprecated in DBAL 3 and gone in 4; kept because the DBAL 3 interface requires it. */
    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null): bool
    {
        if (!isset($this->parameterMap[$param])) {
            throw UnknownParameter::new((string) $param);
        }
        $this->parameters[$this->parameterMap[$param]] = &$variable;
        $this->parameterTypes[$this->parameterMap[$param]] = $type;

        return true;
    }

    public function execute($params = null): Result
    {
        foreach ($params ?? [] as $param => $value) {
            $this->bindValue(\is_int($param) ? $param + 1 : $param, $value);
        }
        \ksort($this->parameters);

        $escaped = [];
        foreach ($this->parameters as $i => $value) {
            // Types are resolved here rather than in bindValue() so bindParam() references work.
            $escaped[] = match ($value === null ? ParameterType::NULL : $this->parameterTypes[$i]) {
                ParameterType::BOOLEAN => (bool) $value === false ? 'f' : 't',
                ParameterType::BINARY, ParameterType::LARGE_OBJECT => \pg_escape_bytea(
                    $this->connection,
                    \is_resource($value) ? \stream_get_contents($value) : $value,
                ),
                default => $value,
            };
        }

        return new Result(Connection::result($this->connection, @\pg_send_execute($this->connection, $this->name, $escaped)));
    }
}
