<?php

declare(strict_types=1);

namespace Cecureus\Repo;

use const Cecureus\ROOT;

/**
 * There is no videos table in the current database (BACKEND_BLOCKERS B-11), so the list comes from
 * config/videos.php. Replace with a table query when one exists.
 */
final class Videos
{
    public const PAGE_SIZE = 20;

    /** @return array{items: list<array<string, mixed>>, nextPage: int|null} */
    public static function list(string $category, int $page): array
    {
        $file = ROOT . '/config/videos.php';
        $all = is_file($file) ? require $file : [];
        $items = [];
        foreach (is_array($all) ? $all : [] as $video) {
            if (
                !is_array($video)
                || !isset($video['id'], $video['title'], $video['videoUrl'])
                || !str_starts_with((string) $video['videoUrl'], 'https://')
                || ($category !== '' && strcasecmp((string) ($video['category'] ?? ''), $category) !== 0)
            ) {
                continue;
            }
            $items[] = [
                'id' => (string) $video['id'],
                'title' => (string) $video['title'],
                'category' => $video['category'] ?? null,
                'thumbnailUrl' => $video['thumbnailUrl'] ?? null,
                'videoUrl' => (string) $video['videoUrl'],
                'durationSeconds' => isset($video['durationSeconds']) ? (int) $video['durationSeconds'] : null,
            ];
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        return [
            'items' => array_slice($items, $offset, self::PAGE_SIZE),
            'nextPage' => count($items) > $offset + self::PAGE_SIZE ? $page + 1 : null,
        ];
    }
}
