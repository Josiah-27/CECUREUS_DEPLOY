<?php

declare(strict_types=1);

namespace Cecureus\Controllers;

use Cecureus\Http\ApiException;
use Cecureus\Http\Request;
use Cecureus\Http\Response;
use Cecureus\Repo\Articles;
use Cecureus\Repo\Videos;
use Cecureus\Security\Sessions;
use Cecureus\Support\Text;

final class ContentController
{
    /** GET /articles?category=&search=&page= */
    public static function articles(Request $request): Response
    {
        Sessions::authenticate($request);
        return Response::ok(Articles::list(
            Text::clean($request->query['category'] ?? '', 100),
            Text::clean($request->query['search'] ?? '', 100),
            CounsellorController::page($request),
        ));
    }

    /** GET /articles/{id} */
    public static function article(Request $request, array $params): Response
    {
        Sessions::authenticate($request);
        $id = $params['id'] ?? '';
        $article = Text::isId($id) ? Articles::find((int) $id) : null;
        if ($article === null) {
            throw ApiException::notFound('Article not found.');
        }
        return Response::ok($article);
    }

    /** GET /videos?category=&page= */
    public static function videos(Request $request): Response
    {
        Sessions::authenticate($request);
        return Response::ok(Videos::list(Text::clean($request->query['category'] ?? '', 100), CounsellorController::page($request)));
    }
}
