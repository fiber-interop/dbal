<?php
/**
 * FI_RUNTIME=revolt|amp|react|phasync|swoole selects the adapter (default revolt).
 * MYSQLI_HOST/PORT/USER/PASSWORD and PG_HOST/PORT/USER/PASSWORD/DBNAME pick the test servers
 * (defaults match the mysqli and pg packages' docker commands).
 */
require __DIR__ . '/../vendor/autoload.php';
if (!class_exists('Scope')) {
    require_once __DIR__ . '/../vendor/fiber-interop/api/tests/Support/ScopeStub.php';
}
$runtime = getenv('FI_RUNTIME') ?: 'revolt';
$class = 'FiberInterop\\' . ucfirst($runtime) . '\\Adapter';
async\setup(new $class());
fwrite(STDERR, "runtime=$runtime\n");
