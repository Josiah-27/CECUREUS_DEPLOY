<?php

declare(strict_types=1);

use Cecureus\Config;

use const Cecureus\ROOT;

/*
 * The tests load a throw-away settings file, so the real .env is reloaded afterwards: later test
 * files (Passwords, Log, …) must keep seeing the runner's configuration. Fictional key names are
 * used so nothing here can change how the other tests read the real settings.
 */
$originalSecret = getenv('APP_SECRET');
$testFile = tempnam(sys_get_temp_dir(), 'cecureus-config-');
try {
    file_put_contents($testFile, implode("\n", [
        '# comment line',
        'CECUREUS_TEST_TEXT=from-file',
        'CECUREUS_TEST_QUOTED="double quoted"',
        'CECUREUS_TEST_SINGLE=\'single\'',
        'CECUREUS_TEST_EQUALS=a=b',
        'CECUREUS_TEST_LIST= one, two , ,three ',
    ]));
    Config::load($testFile);

    test('file values are read, comments skipped and quotes stripped', function (): void {
        check(Config::get('CECUREUS_TEST_TEXT') === 'from-file', 'plain value not read');
        check(Config::get('CECUREUS_TEST_QUOTED') === 'double quoted', 'double quotes not stripped');
        check(Config::get('CECUREUS_TEST_SINGLE') === 'single', 'single quotes not stripped');
        check(Config::get('CECUREUS_TEST_EQUALS') === 'a=b', 'value cut at the inner =');
        check(Config::get('CECUREUS_TEST_MISSING') === null, 'missing key not null');
        check(Config::get('CECUREUS_TEST_MISSING', 'fallback') === 'fallback', 'default not used');
    });

    test('a real environment variable beats the file, an empty one does not', function (): void {
        putenv('CECUREUS_TEST_TEXT=from-env');
        try {
            check(Config::get('CECUREUS_TEST_TEXT') === 'from-env', 'environment did not win');
        } finally {
            putenv('CECUREUS_TEST_TEXT');
        }
        putenv('CECUREUS_TEST_TEXT=');
        try {
            check(Config::get('CECUREUS_TEST_TEXT') === 'from-file', 'empty environment value beat the file');
        } finally {
            putenv('CECUREUS_TEST_TEXT');
        }
    });

    test('require throws for a missing setting', function (): void {
        try {
            Config::require('CECUREUS_TEST_MISSING');
            throw new RuntimeException('require did not throw');
        } catch (RuntimeException $e) {
            check(str_contains($e->getMessage(), 'CECUREUS_TEST_MISSING'), 'message does not name the setting');
        }
    });

    test('typed readers coerce or fall back', function (): void {
        putenv('CECUREUS_TEST_INT=42');
        putenv('CECUREUS_TEST_BAD_INT=nope');
        putenv('CECUREUS_TEST_FLAG=On');
        putenv('CECUREUS_TEST_NO=no');
        try {
            check(Config::int('CECUREUS_TEST_INT', 0) === 42, 'int not parsed');
            check(Config::int('CECUREUS_TEST_BAD_INT', 7) === 7, 'garbage int not replaced by default');
            check(Config::int('CECUREUS_TEST_MISSING', 5) === 5, 'missing int not replaced by default');
            check(Config::bool('CECUREUS_TEST_FLAG', false) === true, 'On not true');
            check(Config::bool('CECUREUS_TEST_NO', true) === false, 'no not false');
            check(Config::bool('CECUREUS_TEST_MISSING', true) === true, 'missing bool not replaced by default');
            check(Config::list('CECUREUS_TEST_LIST') === ['one', 'two', 'three'], 'list not trimmed and de-emptied');
            check(Config::list('CECUREUS_TEST_MISSING') === [], 'missing list not empty');
        } finally {
            putenv('CECUREUS_TEST_INT');
            putenv('CECUREUS_TEST_BAD_INT');
            putenv('CECUREUS_TEST_FLAG');
            putenv('CECUREUS_TEST_NO');
        }
    });

    test('secret refuses weak values', function (): void {
        putenv('APP_SECRET=short');
        try {
            try {
                Config::secret();
                throw new RuntimeException('short secret accepted');
            } catch (RuntimeException $e) {
                check(str_contains($e->getMessage(), 'APP_SECRET'), 'message does not name the setting');
            }
        } finally {
            putenv('APP_SECRET=change-me-' . str_repeat('x', 40));
        }
        try {
            try {
                Config::secret();
                throw new RuntimeException('change-me prefix accepted');
            } catch (RuntimeException $e) {
                check(str_contains($e->getMessage(), 'APP_SECRET'), 'message does not name the setting');
            }
        } finally {
            putenv('APP_SECRET');
        }
    });

    test('the environment name follows APP_ENV and only production is production', function (): void {
        putenv('APP_ENV=staging');
        try {
            check(Config::env() === 'staging', 'env name not read');
            check(Config::isProduction() === false, 'staging treated as production');
        } finally {
            putenv('APP_ENV=development');
        }
    });
} finally {
    if ($originalSecret !== false) {
        putenv('APP_SECRET=' . $originalSecret);
    }
    Config::load(ROOT . '/.env');
    @unlink($testFile);
}
