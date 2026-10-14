# fiber-interop/dbal

Doctrine DBAL 4 drivers whose queries are cooperative inside `async\run()` on any
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

$conn = DriverManager::getConnection(async\dbal\params([
    'driver' => 'pgsql', 'host' => 'db', 'user' => 'app', 'password' => '...', 'dbname' => 'app',
]));
// or directly: ['driverClass' => async\dbal\PgSQL\Driver::class, ...] / async\dbal\Mysqli\Driver::class

async\run(function () use ($conn) {
    $users = $conn->fetchAllAssociative('SELECT * FROM users WHERE active = ?', [true]);
});
```

`async\dbal\params()` swaps `driver` => `mysqli` / `pgsql` for the matching `driverClass`;
`pdo_*` drivers throw (PDO has no non-blocking API). Outside `async\run()` everything is plain
blocking DBAL.

## How it waits

Both drivers let DBAL's own driver connect (same params, same errors) and reuse DBAL's results and
exceptions, so `Doctrine\DBAL\Exception\*` mapping (SQLSTATE, `SyntaxErrorException`, ...),
transactions, `lastInsertId()` and fetch modes behave as with `driver => mysqli/pgsql`.

- **pgsql**: everything, prepared statements included, is sent with `pg_send_query()` /
  `pg_send_prepare()` / `pg_send_execute()` as DBAL does, and the task waits on `pg_socket()`
  before `pg_get_result()`.
- **mysqli**: `query()`, `exec()` and `BEGIN`/`COMMIT`/`ROLLBACK` go through
  [`async\mysqli\query()`](https://github.com/fiber-interop/mysqli) (`MYSQLI_ASYNC` + `mysqli_poll()`).

## Prepared statements on mysqli block

PHP has no asynchronous `mysqli_stmt::execute()`, so on mysqli a query **with parameters**
(`fetchOne($sql, [$id])`, `insert()`, `update()`, every ORM write) is DBAL's normal prepared
statement and blocks the whole thread for its duration. It is correct, just not cooperative.
Queries are not emulated by client-side interpolation. Under the phasync extension, or Swoole with
its runtime hook, mysqli's socket I/O is cooperative anyway, so these do not block there. The fix
belongs in php-src (`MYSQLI_ASYNC` for `mysqli_stmt::execute()`). pgsql has no such limitation.

## Limits

- Connecting is DBAL's blocking `pg_connect()` / `mysqli::real_connect()` (DBAL connects lazily,
  on the first query).
- One DBAL connection runs one query at a time; give each concurrent task its own connection.
- Under Swoole, mysqli is cooperative only on connections opened inside a coroutine, so let DBAL
  connect lazily inside `async\run()`.
- On mysqli, `query()` buffers the whole result (`MYSQLI_STORE_RESULT`), as DBAL's driver does.
- DBAL 4 only (DBAL 3's driver interfaces differ: `Result`/`Statement` signatures and
  `ParameterType` as int constants).

## License

MIT
