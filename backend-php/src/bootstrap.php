<?php

declare(strict_types=1);

/*
 * CecureUs mobile API — reference implementation (PHP 8.1+, PDO MySQL, no framework).
 *
 * Every request goes: public/index.php → App::run() → Routes (src/routes.php) → a Controller method.
 * Controllers validate input, call the Repo classes (all SQL lives there), and return Response::ok().
 * Any ApiException thrown anywhere becomes {"status":false,"message":…,"code":…} with its HTTP status.
 */

namespace Cecureus;

const ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Cecureus\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Optional Composer packages (PHPMailer for SMTP). The API works without them.
if (is_file(ROOT . '/vendor/autoload.php')) {
    require ROOT . '/vendor/autoload.php';
}

Config::load(ROOT . '/.env');
