<?php

declare(strict_types=1);

use Cecureus\Support\Log;

test('log entries are written as one clean line per call', function (): void {
    [$dir, $cleanup] = tempLogDir();
    try {
        Log::error('boom happened', new RuntimeException('details'));
        $line = (string) file_get_contents($dir . '/app.log');
        check(str_contains($line, 'ERROR boom happened | RuntimeException: details'), 'error details missing');
        check(!str_contains($line, "\nERROR"), 'entry not on one line');
        Log::message('SMS to +91******3210 code is 123456');
        check(str_contains((string) file_get_contents($dir . '/messages.log'), 'code is 123456'), 'message log missing');
    } finally {
        $cleanup();
    }
});

test('rotate moves big logs aside and leaves small ones alone', function (): void {
    [$dir, $cleanup] = tempLogDir();
    try {
        putenv('LOG_ROTATE_BYTES=300');
        try {
            for ($i = 0; $i < 6; $i++) {
                Log::info(str_repeat('x', 100));
            }
            check((int) filesize($dir . '/app.log') > 300, 'test log not big enough');
            Log::message('small');
            Log::rotate();
            check(is_file($dir . '/app.log.1'), 'big log not rotated');
            check(!is_file($dir . '/app.log'), 'rotated log still in place');
            check((int) filesize($dir . '/app.log.1') > 300, 'rotated copy incomplete');
            check(!is_file($dir . '/messages.log.1'), 'small log rotated');
            check(is_file($dir . '/messages.log'), 'small log lost');
            Log::info('fresh');
            check(is_file($dir . '/app.log') && (int) filesize($dir . '/app.log') < 300, 'writing after rotate did not start a fresh file');
            Log::rotate();
            check(!is_file($dir . '/app.log.1') || (int) filesize($dir . '/app.log.1') > 300, 'small fresh log overwrote the old copy');
        } finally {
            putenv('LOG_ROTATE_BYTES');
        }
    } finally {
        $cleanup();
    }
});

/** @return array{0: string, 1: callable} */
function tempLogDir(): array
{
    $dir = sys_get_temp_dir() . '/cecureus-logtest-' . bin2hex(random_bytes(4));
    putenv('LOG_DIR=' . $dir);
    return [$dir, static function () use ($dir): void {
        putenv('LOG_DIR');
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink((string) $file);
        }
        @rmdir($dir);
    }];
}
