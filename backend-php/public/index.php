<?php

declare(strict_types=1);

/*
 * Front controller for https://eap.cecureus.com/api/mobile/v1/*
 *
 * Option A (recommended): point the web server at backend-php/public for /api/mobile/v1.
 * Option B (shared hosting): copy this file and .htaccess into public_html/api/mobile/v1/
 * and change $backendRoot to the folder that contains src/ (keep that folder OUTSIDE public_html).
 */
$backendRoot = __DIR__ . '/..';

require $backendRoot . '/src/bootstrap.php';

Cecureus\App::run();
