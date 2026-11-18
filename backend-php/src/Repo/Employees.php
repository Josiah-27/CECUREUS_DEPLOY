<?php

declare(strict_types=1);

namespace Cecureus\Repo;

use Cecureus\Db;
use Cecureus\Support\Text;
use Cecureus\Support\Time;

/**
 * The existing `employee_list` table (no columns added or changed).
 *
 * Formats follow the existing rows: status 'Active' / 'DeActive' / 'Cancel', emp_option
 * 'Corporate' / 'Individual', emp_mobile as 10 digits, timestamps "DD-MM-YYYY hh:mm:ss AM" (IST).
 * Comparisons are case-insensitive and tolerant of the legacy mobile formats.
 */
final class Employees
{
    public const ACTIVE = "LOWER(TRIM(status)) = 'active'";

    /** emp_mobile with spaces, dashes, dots and + removed, last 10 digits. */
    private const MOBILE_10 = "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(emp_mobile, ' ', ''), '-', ''), '+', ''), '.', ''), 10)";

    /** Every column of employee_list except the auto-increment emp_id (all NOT NULL varchar). */
    private const COLUMNS = [
        'emp_otp', 'emp_companyname', 'emp_name', 'emp_lname', 'emp_companyid', 'emp_email', 'emp_password',
        'emp_mobile', 'emp_familyoption', 'emp_dob', 'emp_age', 'emp_gender', 'emp_country', 'emp_option',
        'emp_location', 'emp_jobtitle', 'emp_department', 'emp_doj', 'emp_agree', 'emp_organisationname',
        'emp_maritalstatus', 'emp_emargenumber', 'plan_date', 'reg_date', 'emp_role', 'created_at', 'updated_at',
        'status', 'position',
    ];

    public static function findActiveById(int $empId): ?array
    {
        return Db::one('SELECT * FROM employee_list WHERE emp_id = ? AND ' . self::ACTIVE, [$empId]);
    }

    public static function emailTaken(string $email): bool
    {
        return Db::value(
            'SELECT 1 FROM employee_list WHERE LOWER(TRIM(emp_email)) = ? AND ' . self::ACTIVE . ' LIMIT 1',
            [strtolower($email)],
        ) !== null;
    }

    public static function mobileTaken(string $mobileE164): bool
    {
        return Db::value(
            'SELECT 1 FROM employee_list WHERE ' . self::MOBILE_10 . ' = ? AND ' . self::ACTIVE . ' LIMIT 1',
            [Text::mobile10($mobileE164)],
        ) !== null;
    }

    public static function employeeIdTaken(string $employeeId, string $companyName): bool
    {
        return Db::value(
            'SELECT 1 FROM employee_list WHERE TRIM(emp_companyid) = ? AND (emp_companyname = ? OR emp_organisationname = ?) AND '
            . self::ACTIVE . ' LIMIT 1',
            [$employeeId, $companyName, $companyName],
        ) !== null;
    }

    /**
     * Active rows a sign-in "user ID" could refer to: email, mobile number or employee ID.
     * Legacy data can have the same mobile on two rows, so the caller checks the password on each.
     * @return list<array<string, string|null>>
     */
    public static function loginCandidates(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '' || strlen($userId) > 250) {
            return [];
        }
        if (str_contains($userId, '@')) {
            return Db::all(
                'SELECT * FROM employee_list WHERE LOWER(TRIM(emp_email)) = ? AND ' . self::ACTIVE . ' LIMIT 5',
                [strtolower($userId)],
            );
        }
        $mobile = Text::normalizeMobile($userId);
        if ($mobile !== null) {
            return Db::all(
                'SELECT * FROM employee_list WHERE (' . self::MOBILE_10 . " = ? OR (TRIM(emp_companyid) = ? AND emp_companyid <> ''))"
                . ' AND ' . self::ACTIVE . ' LIMIT 5',
                [Text::mobile10($mobile), $userId],
            );
        }
        return Db::all(
            "SELECT * FROM employee_list WHERE TRIM(emp_companyid) = ? AND emp_companyid <> '' AND " . self::ACTIVE . ' LIMIT 5',
            [$userId],
        );
    }

    /** Inserts a new account row in the same shape as existing rows. Returns emp_id. */
    public static function create(array $fields): int
    {
        $now = Time::legacyTimestamp(time());
        $row = array_fill_keys(self::COLUMNS, '');
        $row = array_merge($row, [
            'emp_familyoption' => 'Self',
            'position' => '0',
            'reg_date' => substr($now, 0, 10),
            'created_at' => $now,
            'updated_at' => $now,
            'status' => 'Active',
        ], $fields);
        return Db::insert('employee_list', array_intersect_key($row, array_flip(self::COLUMNS)));
    }

    public static function update(int $empId, array $fields): void
    {
        $fields['updated_at'] = Time::legacyTimestamp(time());
        Db::update('employee_list', $fields, 'emp_id = ?', [$empId]);
    }

    public static function isCorporate(array $row): bool
    {
        return strtolower(trim((string) $row['emp_option'])) === 'corporate'
            || strtolower(trim((string) $row['emp_role'])) === 'corporate';
    }

    /**
     * employee_list row → the API's User object. Only these fields ever leave the server
     * (never emp_password, emp_otp, dob, gender, emergency number, …).
     */
    public static function toUser(array $row): array
    {
        $email = strtolower(trim((string) $row['emp_email']));
        $age = trim((string) $row['emp_age']);
        $corporate = self::isCorporate($row);
        return [
            'id' => (string) $row['emp_id'],
            'firstName' => trim((string) $row['emp_name']),
            'lastName' => Text::orNull($row['emp_lname']),
            // Some legacy rows have no valid email; the app accepts null.
            'email' => Text::isEmail($email) ? $email : null,
            'mobile' => Text::normalizeMobile($row['emp_mobile']),
            'userType' => $corporate ? 'corporate' : 'individual',
            'companyName' => Text::orNull($row['emp_companyname']) ?? Text::orNull($row['emp_organisationname']),
            'sessionsRemaining' => $corporate ? Organisations::sessionsRemaining($row) : null,
            'age' => ctype_digit($age) ? (int) $age : null,
        ];
    }
}
