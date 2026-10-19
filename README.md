# fiber-interop/dbal

Doctrine DBAL 3 and 4 drivers whose queries are cooperative inside `fiber\run()` on any
[fiber-interop](https://fiber-interop.github.io) adapter (Revolt, Amp, ReactPHP, phasync, Swoole).
DBAL-based code (Symfony, Doctrine ORM) keeps working unchanged; while one task waits for the
database, the others run.

## Install

```
composer require fiber-interop/dbal
```

## Use

```php
use Doctrine\DBAL\DriverManager;

$conn = DriverManager::getConnection(fiber\dbal\params([
    'driver' => 'pgsql', 'host' => 'db', 'user' => 'app', 'password' => '...', 'dbname' => 'app',
]));
// or directly: ['driverClass' => fiber\dbal\PgSQL\Driver::class, ...] / fiber\dbal\Mysqli\Driver::class

fiber\run(function () use ($conn) {
    $users = $conn->fetchAllAssociative('SELECT * FROM users WHERE active = ?', [true]);
});
```

`fiber\dbal\params()` swaps `driver` => `mysqli` / `pgsql` for the matching `driverClass`;
`pdo_*` drivers throw (PDO has no non-blocking API). Outside `fiber\run()` everything is plain
blocking DBAL.

## How it waits

Both drivers let DBAL's own driver connect (same params, same errors) and reuse DBAL's results and
exceptions, so `Doctrine\DBAL\Exception\*` mapping (SQLSTATE, `SyntaxErrorException`, ...),
transactions, `lastInsertId()` and fetch modes behave as with `driver => mysqli/pgsql`.

- **pgsql**: everything, prepared statements included, is sent with `pg_send_query()` /
  `pg_send_prepare()` / `pg_send_execute()` as DBAL does, and the task waits on `pg_socket()`
  before `pg_get_result()`.
- **mysqli**: `query()`, `exec()` and `BEGIN`/`COMMIT`/`ROLLBACK` go through
  [`fiber\mysqli\query()`](https://github.com/fiber-interop/mysqli) (`MYSQLI_ASYNC` + `mysqli_poll()`).

## Prepared statements on mysqli block

PHP has no asynchronous `mysqli_stmt::execute()`, so on mysqli a query **with parameters**
(`fetchOne($sql, [$id])`, `insert()`, `update()`, every ORM write) is DBAL's normal prepared
statement and blocks the whole thread for its duration. It is correct, just not cooperative.
Queries are not emulated by client-side interpolation. Under the phasync extension, or Swoole with
its runtime hook, mysqli's socket I/O is cooperative anyway, so these do not block there. The fix
belongs in php-src (`MYSQLI_ASYNC` for `mysqli_stmt::execute()`). pgsql has no such limitation.

## DBAL versions

`doctrine/dbal: ^3.8 || ^4.0`. The two majors' driver interfaces differ (`ParameterType` is an
int in 3 and an enum in 4; `quote()`, `lastInsertId()`, `bindParam()` and the transaction methods
changed signatures), so there are two parallel sets of classes with the same behaviour:

| DBAL | mysqli | pgsql |
|---|---|---|
| 4 | `fiber\dbal\Mysqli\Driver` | `fiber\dbal\PgSQL\Driver` |
| 3.8+ | `fiber\dbal\V3\Mysqli\Driver` | `fiber\dbal\V3\PgSQL\Driver` |

`fiber\dbal\params()` picks the set for the installed major (DBAL 3 is detected by
`Doctrine\DBAL\Driver\ServerInfoAwareConnection`, which 4 removed). Pass a `driverClass`
yourself only if you pin a major. Each set wraps that major's own DBAL classes (connect, result,
exception conversion); a class from the other set fails to load, but is never autoloaded unless
named. The test suite runs under both majors in CI.

## Limits

- Connecting is DBAL's blocking `pg_connect()` / `mysqli::real_connect()` (DBAL connects lazily,
  on the first query).
- One DBAL connection runs one query at a time; give each concurrent task its own connection.
- Under Swoole, mysqli is cooperative only on connections opened inside a coroutine, so let DBAL
  connect lazily inside `fiber\run()`.
- On mysqli, `query()` buffers the whole result (`MYSQLI_STORE_RESULT`), as DBAL's driver does.

## License

MIT
