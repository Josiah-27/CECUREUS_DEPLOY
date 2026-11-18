<?php

declare(strict_types=1);

namespace Cecureus\Controllers;

use Cecureus\Http\ApiException;
use Cecureus\Http\Request;
use Cecureus\Http\Response;
use Cecureus\Repo\Counsellors;
use Cecureus\Security\Sessions;
use Cecureus\Support\Text;
use Cecureus\Support\Time;

final class CounsellorController
{
    /** GET /counsellors?search=&expertise=&page= */
    public static function index(Request $request): Response
    {
        Sessions::authenticate($request);
        return Response::ok(Counsellors::list(
            Text::clean($request->query['search'] ?? '', 100),
            Text::clean($request->query['expertise'] ?? '', 100),
            self::page($request),
        ));
    }

    /** GET /counsellors/{id} */
    public static function show(Request $request, array $params): Response
    {
        Sessions::authenticate($request);
        return Response::ok(Counsellors::toCounsellor(self::findOr404($params['id'] ?? '')));
    }

    /** GET /counsellors/{id}/availability?from=YYYY-MM-DD&to=YYYY-MM-DD (max 31 days) */
    public static function availability(Request $request, array $params): Response
    {
        Sessions::authenticate($request);
        $doctor = self::findOr404($params['id'] ?? '');
        $from = $request->query['from'] ?? '';
        $to = $request->query['to'] ?? '';
        if (!Time::isYmd($from) || !Time::isYmd($to) || $to < $from) {
            throw ApiException::validation('Choose a valid date range.', 'INVALID_RANGE');
        }
        if (Time::daysBetween($from, $to) > 31) {
            throw ApiException::validation('Choose a range of at most 31 days.', 'INVALID_RANGE');
        }
        $today = Time::istParts(time())['ymd'];
        if ($to < $today) {
            return Response::ok(['timezone' => Time::TIMEZONE, 'days' => []]);
        }
        return Response::ok(Counsellors::availability($doctor, max($from, $today), $to));
    }

    public static function findOr404(mixed $id): array
    {
        $doctor = Text::isId($id) ? Counsellors::find((int) $id) : null;
        if ($doctor === null) {
            throw ApiException::notFound('Counsellor not found.');
        }
        return $doctor;
    }

    public static function page(Request $request): int
    {
        $page = $request->query['page'] ?? '1';
        return ctype_digit($page) && (int) $page >= 1 && (int) $page <= 1000 ? (int) $page : 1;
    }
}
