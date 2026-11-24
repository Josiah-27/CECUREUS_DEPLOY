<?php

declare(strict_types=1);

/*
 * Daily maintenance for the mobile API: deletes expired sessions, OTP requests, sign-ups and
 * rate-limit windows, and rotates the log files. Cron it once a day, e.g.:
 *
 *   0 3 * * *  php /home/cecureus/mobile-api/bin/housekeeping.php
 *
 * Needs the same DB settings as the API (backend-php/.env or the environment).
 */

require __DIR__ . '/../src/bootstrap.php';

use Cecureus\Repo\Housekeeping;
use Cecureus\Support\Log;

try {
    foreach (Housekeeping::cleanTables() as $table => $deleted) {
        echo "{$table}: {$deleted} row(s) deleted" . PHP_EOL;
    }
    Log::rotate();
} catch (Throwable $e) {
    Log::error('Housekeeping failed', $e);
    fwrite(STDERR, 'Housekeeping failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
