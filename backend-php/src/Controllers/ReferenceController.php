<?php

declare(strict_types=1);

namespace Cecureus\Controllers;

use Cecureus\Db;
use Cecureus\Http\Request;
use Cecureus\Http\Response;

/** Public lists used by the booking form (no login needed). */
final class ReferenceController
{
    /** GET /issues → [{id, name}] from issuse_list */
    public static function issues(Request $request): Response
    {
        $rows = Db::all(
            "SELECT issuse_id AS id, TRIM(issuse_name) AS name FROM issuse_list
              WHERE LOWER(TRIM(status)) = 'active' AND TRIM(issuse_name) <> '' ORDER BY issuse_name",
        );
        return Response::ok(array_map(static fn (array $r) => ['id' => (string) $r['id'], 'name' => $r['name']], $rows));
    }

    /** GET /languages → [{id, name}] from language */
    public static function languages(Request $request): Response
    {
        $rows = Db::all("SELECT id, TRIM(language_name) AS name FROM language WHERE TRIM(language_name) <> '' ORDER BY language_name");
        return Response::ok(array_map(static fn (array $r) => ['id' => (string) $r['id'], 'name' => $r['name']], $rows));
    }
}
