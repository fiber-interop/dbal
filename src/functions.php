<?php

namespace fiber\dbal;

/**
 * Returns DriverManager connection params with `driver` => mysqli / pgsql replaced by the
 * matching cooperative driverClass. pdo_* drivers are not supported (PDO has no async API) and
 * throw \InvalidArgumentException; params that already name a driverClass are returned as is.
 */
function params(array $params): array
{
    if (isset($params['driverClass']) || !isset($params['driver'])) {
        return $params;
    }
    // ServerInfoAwareConnection exists only in DBAL 3 (folded into Driver\Connection in 4).
    $v3 = \interface_exists(\Doctrine\DBAL\Driver\ServerInfoAwareConnection::class);
    $class = match ($params['driver']) {
        'mysqli' => $v3 ? V3\Mysqli\Driver::class : Mysqli\Driver::class,
        'pgsql' => $v3 ? V3\PgSQL\Driver::class : PgSQL\Driver::class,
        default => throw new \InvalidArgumentException(\str_starts_with($params['driver'], 'pdo_')
            ? "fiber-interop/dbal: '{$params['driver']}' is not supported: PDO has no non-blocking API. Use 'mysqli' or 'pgsql'."
            : "fiber-interop/dbal: no cooperative driver for '{$params['driver']}'; supported: mysqli, pgsql."),
    };
    unset($params['driver']);
    return $params + ['driverClass' => $class];
}
