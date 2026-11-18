<?php

declare(strict_types=1);

namespace Cecureus\Repo;

use Cecureus\Db;
use Cecureus\Http\ApiException;
use Cecureus\Support\Media;
use Cecureus\Support\Text;

/**
 * Self-assessments from quiz_category / quiz_list / quiz_answer / percentage.
 * Scores (quiz_answer.quiz_ansfourtick) never leave the server; the result is computed here.
 *
 * CONFIRM WITH THE WEBSITE TEAM how the `percentage` table is meant to be read. This implementation
 * treats per_title / per_titleto as the lower / upper % bound of a band, `session` (or `sessiontwo`)
 * as the band's label and per_content as its summary, and falls back to Low / Moderate / High bands.
 */
final class Assessments
{
    private const ACTIVE = "LOWER(TRIM(status)) = 'active'";

    /** @return list<array<string, mixed>> */
    public static function list(): array
    {
        $rows = Db::all(
            "SELECT c.quizcat_id, c.quiz_category, c.quiz_content, c.quiz_image,
                    (SELECT COUNT(*) FROM quiz_list q
                      WHERE q.quizcat_id = c.quizcat_id AND LOWER(TRIM(q.status)) = 'active') AS question_count
               FROM quiz_category c
              WHERE LOWER(TRIM(c.status)) = 'active'
              ORDER BY c.quizcat_id",
        );
        $out = [];
        foreach ($rows as $row) {
            if ((int) $row['question_count'] > 0 && trim((string) $row['quiz_category']) !== '') {
                $out[] = self::summary($row, (int) $row['question_count']);
            }
        }
        return $out;
    }

    public static function find(int $categoryId): ?array
    {
        $category = Db::one('SELECT * FROM quiz_category WHERE quizcat_id = ? AND ' . self::ACTIVE, [$categoryId]);
        if ($category === null) {
            return null;
        }
        $questions = [];
        // Note: PHP turns numeric-string array keys into ints, so ids are cast back to strings here.
        foreach (self::questions($categoryId) as $qid => $q) {
            $options = [];
            foreach ($q['options'] as $oid => $o) {
                $options[] = ['id' => (string) $oid, 'text' => $o['text']];
            }
            $questions[] = ['id' => (string) $qid, 'text' => $q['text'], 'options' => $options];
        }
        if ($questions === []) {
            return null;
        }
        return self::summary($category, count($questions)) + ['questions' => $questions];
    }

    /**
     * @param mixed $answers [{questionId, optionId}, …] from the request body
     * @return array{percentage: int, level: string, summary: string|null}
     */
    public static function score(int $categoryId, mixed $answers): array
    {
        $questions = self::questions($categoryId);
        if ($questions === []) {
            throw ApiException::notFound('Assessment not found.');
        }
        $chosen = [];
        foreach (is_array($answers) ? $answers : [] as $answer) {
            $qid = is_array($answer) ? (string) ($answer['questionId'] ?? '') : '';
            $oid = is_array($answer) ? (string) ($answer['optionId'] ?? '') : '';
            if (!isset($questions[$qid]['options'][$oid])) {
                throw ApiException::validation('One of the answers is not valid.', 'INVALID_ANSWER');
            }
            $chosen[$qid] = $oid;
        }
        if (count($chosen) !== count($questions)) {
            throw ApiException::validation('Please answer every question.', 'INCOMPLETE');
        }
        $score = 0.0;
        $max = 0.0;
        foreach ($questions as $qid => $q) {
            $score += $q['options'][$chosen[$qid]]['score'];
            $max += max(array_column($q['options'], 'score'));
        }
        $percentage = $max > 0 ? (int) round($score / $max * 100) : 0;
        return ['percentage' => $percentage] + self::band($categoryId, $percentage);
    }

    /**
     * @return array<string, array{text: string, options: array<string, array{text: string, score: float}>}>
     */
    private static function questions(int $categoryId): array
    {
        $rows = Db::all(
            'SELECT quiz_id, quiz_question FROM quiz_list WHERE quizcat_id = ? AND ' . self::ACTIVE . ' ORDER BY quiz_id',
            [(string) $categoryId],
        );
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r) => (string) $r['quiz_id'], $rows);
        $answers = Db::all(
            'SELECT quiz_ans_id, quiz_id, quiz_ansfour, quiz_ansfourtick FROM quiz_answer
              WHERE quiz_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY quiz_ans_id',
            $ids,
        );
        $options = [];
        foreach ($answers as $a) {
            $text = Text::plain($a['quiz_ansfour']);
            if ($text !== '') {
                $options[(string) $a['quiz_id']][(string) $a['quiz_ans_id']] = [
                    'text' => $text,
                    'score' => is_numeric($a['quiz_ansfourtick']) ? (float) $a['quiz_ansfourtick'] : 0.0,
                ];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $qid = (string) $r['quiz_id'];
            $text = Text::plain($r['quiz_question']);
            if ($text !== '' && count($options[$qid] ?? []) >= 2) {
                $out[$qid] = ['text' => $text, 'options' => $options[$qid]];
            }
        }
        return $out;
    }

    /** @return array{level: string, summary: string|null} */
    private static function band(int $categoryId, int $percentage): array
    {
        $rows = Db::all('SELECT * FROM percentage WHERE quizcat_id = ? ORDER BY per_id', [(string) $categoryId]);
        foreach ($rows as $row) {
            $low = $row['per_title'];
            $high = $row['per_titleto'];
            if (is_numeric($low) && is_numeric($high) && $percentage >= (float) $low && $percentage <= (float) $high) {
                $label = '';
                foreach ([$row['session'], $row['sessiontwo']] as $candidate) {
                    $candidate = trim((string) $candidate);
                    if ($candidate !== '' && !is_numeric($candidate)) {
                        $label = $candidate;
                        break;
                    }
                }
                return [
                    'level' => $label !== '' ? $label : self::fallbackLevel($percentage),
                    'summary' => Text::orNull(Text::plain($row['per_content'])),
                ];
            }
        }
        return ['level' => self::fallbackLevel($percentage), 'summary' => null];
    }

    private static function fallbackLevel(int $percentage): string
    {
        return $percentage < 34 ? 'Low' : ($percentage < 67 ? 'Moderate' : 'High');
    }

    private static function summary(array $row, int $questionCount): array
    {
        return [
            'id' => (string) $row['quizcat_id'],
            'title' => trim((string) $row['quiz_category']),
            'description' => Text::orNull(mb_substr(Text::plain($row['quiz_content']), 0, 300)),
            'imageUrl' => Media::url($row['quiz_image'], 'MEDIA_QUIZ_BASE_URL'),
            'questionCount' => $questionCount,
            'estimatedMinutes' => max(1, (int) ceil($questionCount / 2)),
        ];
    }
}
