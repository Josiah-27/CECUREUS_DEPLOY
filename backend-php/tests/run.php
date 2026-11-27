<?php

declare(strict_types=1);

/*
 * Backend unit and integration tests. No framework, no Composer needed:
 *
 *     php tests/run.php
 *
 * Integration tests use the same database the API runs on (DB_* in backend-php/.env) with the
 * CecureUs tables loaded (README, "Testing"). When no database is reachable they are skipped,
 * so the unit tests run everywhere.
 */

use Cecureus\Db;
use Cecureus\Http\ApiException;

require __DIR__ . '/../src/bootstrap.php';

if (getenv('APP_SECRET') === false) {
    putenv('APP_SECRET=tests-secret-0123456789abcdef0123456789abcdef');
}
if (getenv('APP_ENV') === false) {
    putenv('APP_ENV=development');
}

final class Skip extends RuntimeException
{
}

$tests = 0;
$failures = 0;
$skips = 0;

function test(string $name, callable $fn): void
{
    global $tests, $failures, $skips;
    $tests++;
    try {
        $fn();
        echo "  ok   {$name}\n";
    } catch (Skip $e) {
        $skips++;
        echo "  SKIP {$name} ({$e->getMessage()})\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  FAIL {$name}" . PHP_EOL;
        echo '       ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
        if ($e instanceof ApiException) {
            echo "       HTTP {$e->httpStatus} {$e->errorCode}" . PHP_EOL;
        }
    }
}

function dbAvailable(): bool
{
    static $available = null;
    if ($available === null) {
        try {
            Db::pdo()->query('SELECT 1');
            $available = true;
        } catch (Throwable) {
            $available = false;
        }
    }
    return $available;
}

/** Ends the test with SKIP when the database is not reachable (unit tests never call this). */
function needDb(): void
{
    if (!dbAvailable()) {
        throw new Skip('no database reachable (set DB_* in backend-php/.env and load the tables)');
    }
}

require __DIR__ . '/helpers.php';

echo 'CecureUs backend tests' . (dbAvailable() ? '' : ' (no database: integration tests skipped)') . PHP_EOL . PHP_EOL;
foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    echo basename($file, '.php') . ':' . PHP_EOL;
    require $file;
    echo PHP_EOL;
}

echo ($failures === 0 ? 'OK ' : 'FAILED ') . "{$tests} tests, {$failures} failure(s), {$skips} skipped" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
