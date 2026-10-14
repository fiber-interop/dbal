<?php

namespace async\dbal\V3\Mysqli;

use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Doctrine\DBAL\Driver\Mysqli\Driver as MysqliDriver;
use SensitiveParameter;

/**
 * DBAL 3 counterpart of async\dbal\Mysqli\Driver: DBAL's own mysqli driver connects, and the
 * returned Connection runs query()/exec()/transactions through async\mysqli\query(). Prepared
 * statements stay DBAL's (blocking). async\dbal\params() picks this class when DBAL 3 is installed.
 */
final class Driver extends AbstractMySQLDriver
{
    public function connect(#[SensitiveParameter] array $params): Connection
    {
        $inner = (new MysqliDriver())->connect($params);
        $inner->getNativeConnection()->options(\MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);

        return new Connection($inner);
    }
}
