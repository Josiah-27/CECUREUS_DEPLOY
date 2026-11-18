<?php

declare(strict_types=1);

namespace Cecureus\Controllers;

use Cecureus\Http\ApiException;
use Cecureus\Http\Request;
use Cecureus\Http\Response;
use Cecureus\Repo\Assessments;
use Cecureus\Security\RateLimiter;
use Cecureus\Security\Sessions;
use Cecureus\Support\Text;

/** Results are private to the user and are never shown to employers/HR. */
final class AssessmentController
{
    /** GET /assessments */
    public static function index(Request $request): Response
    {
        Sessions::authenticate($request);
        return Response::ok(Assessments::list());
    }

    /** GET /assessments/{id} */
    public static function show(Request $request, array $params): Response
    {
        Sessions::authenticate($request);
        $id = $params['id'] ?? '';
        $assessment = Text::isId($id) ? Assessments::find((int) $id) : null;
        if ($assessment === null) {
            throw ApiException::notFound('Assessment not found.');
        }
        return Response::ok($assessment);
    }

    /** POST /assessments/{id}/submit {answers: [{questionId, optionId}]} → {percentage, level, summary} */
    public static function submit(Request $request, array $params): Response
    {
        $employee = Sessions::authenticate($request);
        RateLimiter::consume('assessment:' . $employee['emp_id'], 30, 3600);
        $id = $params['id'] ?? '';
        if (!Text::isId($id)) {
            throw ApiException::notFound('Assessment not found.');
        }
        return Response::ok(Assessments::score((int) $id, $request->json()['answers'] ?? null));
    }
}
