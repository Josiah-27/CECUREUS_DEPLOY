<?php

declare(strict_types=1);

namespace Cecureus\Repo;

use Cecureus\Db;
use Cecureus\Support\Media;
use Cecureus\Support\Text;
use Cecureus\Support\Time;

/**
 * Articles from `blog_post`.
 * CONFIRM WITH THE WEBSITE TEAM: blog_post has no title column; `post_category` appears to hold the
 * post title and `blog_cateory` the category name. Change TITLE_COLUMN if that is wrong.
 */
final class Articles
{
    public const PAGE_SIZE = 20;
    private const TITLE_COLUMN = 'post_category';
    private const ACTIVE = "LOWER(TRIM(status)) = 'active'";

    /** @return array{items: list<array<string, mixed>>, nextPage: int|null} */
    public static function list(string $category, string $search, int $page): array
    {
        $where = [self::ACTIVE];
        $params = [];
        if ($category !== '') {
            $where[] = 'LOWER(TRIM(blog_cateory)) = LOWER(?)';
            $params[] = $category;
        }
        if ($search !== '') {
            $like = '%' . Text::likeEscape($search) . '%';
            $where[] = '(' . self::TITLE_COLUMN . ' LIKE ? OR blog_cateory LIKE ?)';
            array_push($params, $like, $like);
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $rows = Db::all(
            'SELECT post_id, blog_cateory, ' . self::TITLE_COLUMN . ' AS title, post_image, created_at,
                    LEFT(post_content, 4000) AS content_head, LENGTH(post_content) AS content_length
               FROM blog_post WHERE ' . implode(' AND ', $where)
            . ' ORDER BY post_id DESC LIMIT ' . (self::PAGE_SIZE + 1) . ' OFFSET ' . $offset,
            $params,
        );
        $hasMore = count($rows) > self::PAGE_SIZE;
        $items = [];
        foreach (array_slice($rows, 0, self::PAGE_SIZE) as $row) {
            $summary = self::summary($row, (string) $row['content_head'], max(1, (int) round((int) $row['content_length'] / 1500)));
            if ($summary !== null) {
                $items[] = $summary;
            }
        }
        return ['items' => $items, 'nextPage' => $hasMore ? $page + 1 : null];
    }

    public static function find(int $postId): ?array
    {
        $row = Db::one(
            'SELECT post_id, blog_cateory, ' . self::TITLE_COLUMN . ' AS title, post_image, created_at, post_content
               FROM blog_post WHERE post_id = ? AND ' . self::ACTIVE,
            [$postId],
        );
        if ($row === null) {
            return null;
        }
        $html = (string) $row['post_content'];
        $words = str_word_count(Text::plain($html));
        $summary = self::summary($row, $html, max(1, (int) round($words / 200)));
        // The app renders this as plain text; it never executes scripts.
        return $summary === null ? null : $summary + ['contentHtml' => $html];
    }

    private static function summary(array $row, string $htmlHead, int $readMinutes): ?array
    {
        $title = Text::plain($row['title']);
        if ($title === '') {
            return null;
        }
        $excerpt = mb_substr(Text::plain($htmlHead), 0, 180);
        return [
            'id' => (string) $row['post_id'],
            'title' => $title,
            'category' => Text::orNull($row['blog_cateory']),
            'excerpt' => $excerpt === '' ? null : $excerpt,
            'imageUrl' => Media::url($row['post_image'], 'MEDIA_BLOG_BASE_URL'),
            'readMinutes' => $readMinutes,
            'publishedAt' => Time::legacyToIso($row['created_at']),
        ];
    }
}
