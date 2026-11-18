<?php

declare(strict_types=1);

/*
 * Every endpoint of the mobile API, relative to BASE_PATH (default /api/mobile/v1).
 * Matches docs/API_CONTRACT.md and the app's src/api/endpoints.ts one-to-one.
 */

use Cecureus\Controllers\AssessmentController;
use Cecureus\Controllers\AuthController;
use Cecureus\Controllers\BookingController;
use Cecureus\Controllers\ContentController;
use Cecureus\Controllers\CounsellorController;
use Cecureus\Controllers\ProfileController;
use Cecureus\Controllers\ReferenceController;
use Cecureus\Http\Response;
use Cecureus\Http\Router;

$router = new Router();

// Health check for monitoring (no data).
$router->get('health', static fn () => Response::ok(['ok' => true]));

// AUTH (no login needed, except logout)
$router->post('auth/register/email/request', [AuthController::class, 'registerEmailRequest']);
$router->post('auth/register/email/verify', [AuthController::class, 'registerEmailVerify']);
$router->post('auth/register/phone/request', [AuthController::class, 'registerPhoneRequest']);
$router->post('auth/register/phone/verify', [AuthController::class, 'registerPhoneVerify']);
$router->post('auth/register/complete', [AuthController::class, 'registerComplete']);
$router->post('auth/login', [AuthController::class, 'login']);
$router->post('auth/logout', [AuthController::class, 'logout']);

// PROFILE (login needed)
$router->get('me', [ProfileController::class, 'show']);
$router->patch('me', [ProfileController::class, 'update']);
$router->delete('me', [ProfileController::class, 'destroy']);

// LISTS (no login needed)
$router->get('issues', [ReferenceController::class, 'issues']);
$router->get('languages', [ReferenceController::class, 'languages']);

// COUNSELLORS (login needed)
$router->get('counsellors', [CounsellorController::class, 'index']);
$router->get('counsellors/{id}', [CounsellorController::class, 'show']);
$router->get('counsellors/{id}/availability', [CounsellorController::class, 'availability']);

// BOOKINGS (login needed)
$router->post('bookings', [BookingController::class, 'create']);
$router->get('bookings', [BookingController::class, 'index']);
$router->post('bookings/{id}/cancel', [BookingController::class, 'cancel']);

// CONTENT (login needed)
$router->get('articles', [ContentController::class, 'articles']);
$router->get('articles/{id}', [ContentController::class, 'article']);
$router->get('videos', [ContentController::class, 'videos']);

// ASSESSMENTS (login needed)
$router->get('assessments', [AssessmentController::class, 'index']);
$router->get('assessments/{id}', [AssessmentController::class, 'show']);
$router->post('assessments/{id}/submit', [AssessmentController::class, 'submit']);

return $router;
