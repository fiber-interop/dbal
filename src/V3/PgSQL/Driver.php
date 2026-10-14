<?php

namespace async\dbal\V3\PgSQL;

use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\PgSQL\Driver as PgSQLDriver;
use SensitiveParameter;

/**
 * DBAL 3 counterpart of async\dbal\PgSQL\Driver (DBAL has a native pgsql driver since 3.5): DBAL's
 * own driver connects; queries and prepared statements wait cooperatively.
 */
final class Driver extends AbstractPostgreSQLDriver
{
    public function connect(#[SensitiveParameter] array $params): Connection
    {
        return new Connection((new PgSQLDriver())->connect($params));
    }
}
