<?php

declare(strict_types=1);

/*
 * Corporate sign-up: work-email domain → partner organisation (BACKEND_BLOCKERS B-03).
 * A corporate sign-up is accepted only when the email's domain is listed here.
 * sessionsPerYear = sessions included per employee per calendar year (null = no limit shown).
 *
 * Replace the examples with the real partner companies.
 */
return [
    // 'hyundai.com'  => ['name' => 'Hyundai', 'sessionsPerYear' => 6],

    // Demo organisation for testing — ignored when APP_ENV=production.
    'democorp.com' => ['name' => 'Demo Corp', 'sessionsPerYear' => 8, 'demoOnly' => true],
];
