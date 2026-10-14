<?php

declare(strict_types=1);

namespace async\dbal\PgSQL;

use Doctrine\DBAL\Driver\PgSQL\Result;

use Doctrine\DBAL\Driver\PgSQL\Exception\UnknownParameter;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use PgSql\Connection as PgSqlConnection;

/** DBAL's PgSQL\Statement (copied, as it is final) executing through Connection::result(). */
final class Statement implements StatementInterface
{
    /** @var array<int, mixed> */
    private array $parameters = [];

    /** @phpstan-var array<int, ParameterType> */
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
        // @phpstan-ignore isset.initializedProperty
        if (isset($this->connection)) {
            @\pg_query(
                $this->connection,
                'DEALLOCATE ' . \pg_escape_identifier($this->connection, $this->name),
            );
        }
    }

    /** {@inheritDoc} */
    public function bindValue(int|string $param, mixed $value, ParameterType $type = ParameterType::STRING): void
    {
        if (! isset($this->parameterMap[$param])) {
            throw UnknownParameter::new((string) $param);
        }

        if ($value === null) {
            $type = ParameterType::NULL;
        }

        if ($type === ParameterType::BOOLEAN) {
            $this->parameters[$this->parameterMap[$param]]     = (bool) $value === false ? 'f' : 't';
            $this->parameterTypes[$this->parameterMap[$param]] = ParameterType::STRING;
        } else {
            $this->parameters[$this->parameterMap[$param]]     = $value;
            $this->parameterTypes[$this->parameterMap[$param]] = $type;
        }
    }

    /** {@inheritDoc} */
    public function execute(): Result
    {
        \ksort($this->parameters);

        $escapedParameters = [];
        foreach ($this->parameters as $parameter => $value) {
            $escapedParameters[] = match ($this->parameterTypes[$parameter]) {
                ParameterType::BINARY, ParameterType::LARGE_OBJECT => $value === null
                    ? null
                    : \pg_escape_bytea($this->connection, \is_resource($value) ? \stream_get_contents($value) : $value),
                default => $value,
            };
        }

        return new Result(Connection::result($this->connection, @\pg_send_execute($this->connection, $this->name, $escapedParameters)));
    }
}
