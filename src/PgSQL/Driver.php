<?php

namespace fiber\dbal\PgSQL;

use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\PgSQL\Driver as PgSQLDriver;
use SensitiveParameter;

/**
 * DBAL's pgsql driver with cooperative queries: DBAL's own driver connects (same params, same
 * errors), and the returned Connection waits on the libpq socket inside fiber\run().
 */
final class Driver extends AbstractPostgreSQLDriver
{
    public function connect(#[SensitiveParameter] array $params): Connection
    {
        return new Connection((new PgSQLDriver())->connect($params));
    }
}
