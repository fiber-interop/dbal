<?php

namespace async\dbal\Mysqli;

use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Doctrine\DBAL\Driver\Mysqli\Driver as MysqliDriver;
use SensitiveParameter;

/**
 * DBAL's mysqli driver with cooperative queries: DBAL's own driver connects (same params,
 * initializers and errors), and the returned Connection runs query()/exec()/transactions through
 * async\mysqli\query(). Prepared statements stay DBAL's (blocking: PHP has no async
 * mysqli_stmt::execute()).
 */
final class Driver extends AbstractMySQLDriver
{
    public function connect(#[SensitiveParameter] array $params): Connection
    {
        $inner = (new MysqliDriver())->connect($params);
        // query() reads the text protocol; native ints/floats keep values typed as DBAL's
        // prepared-statement results are.
        $inner->getNativeConnection()->options(\MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        return new Connection($inner);
    }
}
