<?php

declare(strict_types=1);

use Cecureus\Db;

/*
 * The identifier guard runs before any SQL is built, so these checks need no database: a bad name
 * must throw instead of reaching MySQL.
 */
test('insert refuses table and column names that are not plain identifiers', function (): void {
    try {
        Db::insert('employee_list; DROP TABLE x', []);
        throw new RuntimeException('bad table accepted');
    } catch (InvalidArgumentException $e) {
        check(str_contains($e->getMessage(), 'Bad SQL identifier'), 'unexpected message: ' . $e->getMessage());
    }
    try {
        Db::insert('employee_list', ['emp_name' => 'a', 'x` DROP' => 'b']);
        throw new RuntimeException('bad column accepted');
    } catch (InvalidArgumentException $e) {
        check(str_contains($e->getMessage(), 'Bad SQL identifier'), 'unexpected message: ' . $e->getMessage());
    }
});

test('update refuses table and column names that are not plain identifiers', function (): void {
    try {
        Db::update('booking_appointment where 1=1', [], 'book_id = ?', [1]);
        throw new RuntimeException('bad table accepted');
    } catch (InvalidArgumentException $e) {
        check(str_contains($e->getMessage(), 'Bad SQL identifier'), 'unexpected message: ' . $e->getMessage());
    }
    try {
        Db::update('booking_appointment', ['status" = "x' => 'Cancel'], 'book_id = ?', [1]);
        throw new RuntimeException('bad column accepted');
    } catch (InvalidArgumentException $e) {
        check(str_contains($e->getMessage(), 'Bad SQL identifier'), 'unexpected message: ' . $e->getMessage());
    }
});
