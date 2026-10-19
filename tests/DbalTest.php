<?php

namespace fiber\dbal\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function fiber\{run, go, await, await_all};

final class DbalTest extends TestCase
{
    public static function backends(): array
    {
        return ['mysqli' => ['mysqli'], 'pgsql' => ['pgsql']];
    }

    public static function setUpBeforeClass(): void
    {
        $e = static fn(string $k, string $d) => getenv($k) ?: $d;
        (new \mysqli($e('MYSQLI_HOST', '127.0.0.1'), $e('MYSQLI_USER', 'root'), $e('MYSQLI_PASSWORD', 'test'), '', (int) $e('MYSQLI_PORT', '13307')))
            ->query('CREATE DATABASE IF NOT EXISTS fi');
    }

    /** DBAL connects lazily, so these connect inside run() (needed for Swoole's hook on mysqli). */
    private static function conn(string $driver): Connection
    {
        $e = static fn(string $k, string $d) => getenv($k) ?: $d;
        $c = DriverManager::getConnection(\fiber\dbal\params($driver === 'mysqli' ? [
            'driver' => 'mysqli', 'host' => $e('MYSQLI_HOST', '127.0.0.1'), 'port' => (int) $e('MYSQLI_PORT', '13307'),
            'user' => $e('MYSQLI_USER', 'root'), 'password' => $e('MYSQLI_PASSWORD', 'test'), 'dbname' => 'fi',
        ] : [
            'driver' => 'pgsql', 'host' => $e('PG_HOST', '127.0.0.1'), 'port' => (int) $e('PG_PORT', '15432'),
            'user' => $e('PG_USER', 'fi'), 'password' => $e('PG_PASSWORD', 'fi'), 'dbname' => $e('PG_DBNAME', 'fi'),
        ]));
        return $c;
    }

    private static function sleep(string $driver, float $s): string
    {
        return $driver === 'mysqli' ? "SELECT SLEEP($s)" : "SELECT pg_sleep($s)";
    }

    #[DataProvider('backends')]
    public function testOverlap(string $d): void
    {
        $conns = array_map(fn() => self::conn($d), range(1, 10));
        $t = run(function () use ($conns, $d) {
            $t = microtime(true);
            await_all(array_map(fn($c) => go(fn() => $c->fetchOne(self::sleep($d, 0.2))), $conns));
            return microtime(true) - $t;
        });
        $this->assertLessThan(1.5, $t);
    }

    #[DataProvider('backends')]
    public function testOtherTasksRunDuringQuery(string $d): void
    {
        $c = self::conn($d);
        $ticks = run(function () use ($c, $d) {
            $n = 0;
            $done = false;
            $q = go(function () use ($c, $d, &$done) { $c->fetchOne(self::sleep($d, 0.2)); $done = true; });
            $t = go(function () use (&$n, &$done) {
                while (!$done) { $n++; \fiber\sleep(0.01); }
            });
            await_all([$q, $t]);
            return $n;
        });
        $this->assertGreaterThan(5, $ticks);
    }

    #[DataProvider('backends')]
    public function testFetch(string $d): void
    {
        $c = self::conn($d);
        [$rows, $one] = run(fn() => [
            $c->fetchAllAssociative('SELECT 1 AS a, ' . $c->quote("x'y") . ' AS b UNION ALL SELECT 2, ' . $c->quote('z')),
            $c->fetchOne('SELECT 40 + 2'),
        ]);
        $this->assertEquals([['a' => 1, 'b' => "x'y"], ['a' => 2, 'b' => 'z']], $rows);
        $this->assertEquals(42, $one);
    }

    #[DataProvider('backends')]
    public function testPreparedWithParams(string $d): void
    {
        $c = self::conn($d);
        [$a, $b] = run(fn() => [
            $c->fetchOne($d === 'mysqli' ? 'SELECT ? + ?' : 'SELECT ?::int + ?::int', [20, 22], [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER]),
            $c->fetchAssociative($d === 'mysqli' ? 'SELECT CONCAT(:x, :y) AS s' : 'SELECT :x::text || :y::text AS s', ['x' => 'a', 'y' => 'b']),
        ]);
        $this->assertEquals(42, $a);
        $this->assertSame(['s' => 'ab'], $b);
    }

    #[DataProvider('backends')]
    public function testTransactionsAndLastInsertId(string $d): void
    {
        $c = self::conn($d);
        $c->executeStatement('DROP TABLE IF EXISTS dbal_t');
        $c->executeStatement($d === 'mysqli'
            ? 'CREATE TABLE dbal_t (id INT AUTO_INCREMENT PRIMARY KEY, v INT) ENGINE=InnoDB'
            : 'CREATE TABLE dbal_t (id SERIAL PRIMARY KEY, v INT)');
        [$id, $count] = run(function () use ($c) {
            $id = $c->transactional(function ($c) { $c->insert('dbal_t', ['v' => 1]); return $c->lastInsertId(); });
            try {
                $c->transactional(function ($c) { $c->executeStatement('INSERT INTO dbal_t (v) VALUES (2)'); throw new \RuntimeException('x'); });
            } catch (\RuntimeException) {}
            $c->beginTransaction();
            $this->assertSame(1, $c->executeStatement('UPDATE dbal_t SET v = 3'));
            $c->rollBack();
            return [$id, $c->fetchAllNumeric('SELECT v FROM dbal_t')];
        });
        $this->assertEquals(1, $id);
        $this->assertEquals([[1]], $count);
    }

    #[DataProvider('backends')]
    public function testSyntaxErrorIsMapped(string $d): void
    {
        $c = self::conn($d);
        foreach ([fn() => $c->fetchOne('SELEC 1'), fn() => $c->executeStatement('SELEC 1'), fn() => $c->fetchOne('SELEC ?', [1])] as $f) {
            try {
                run($f);
                $this->fail('no exception');
            } catch (SyntaxErrorException $e) {
                $this->assertNotNull($e->getSQLState());
            }
        }
        $this->assertEquals(1, run(fn() => $c->fetchOne('SELECT 1')));
    }

    #[DataProvider('backends')]
    public function testBlockingOutsideRun(string $d): void
    {
        $c = self::conn($d);
        $this->assertEquals(7, $c->fetchOne('SELECT 7'));
        $this->assertEquals(8, $c->fetchOne('SELECT ?', [8]));
        $this->expectException(SyntaxErrorException::class);
        $c->fetchOne('SELEC 1');
    }

    public function testParams(): void
    {
        $ns = \interface_exists(\Doctrine\DBAL\Driver\ServerInfoAwareConnection::class) ? 'fiber\\dbal\\V3\\' : 'fiber\\dbal\\';
        $this->assertSame(['host' => 'h', 'driverClass' => $ns . 'PgSQL\\Driver'], \fiber\dbal\params(['driver' => 'pgsql', 'host' => 'h']));
        $this->assertSame($ns . 'Mysqli\\Driver', \fiber\dbal\params(['driver' => 'mysqli'])['driverClass']);
        $this->assertTrue(\class_exists(\fiber\dbal\params(['driver' => 'mysqli'])['driverClass']));
        $this->assertSame(['driverClass' => 'X'], \fiber\dbal\params(['driverClass' => 'X']));
        $this->expectExceptionMessage('PDO has no non-blocking API');
        \fiber\dbal\params(['driver' => 'pdo_mysql']);
    }
}
