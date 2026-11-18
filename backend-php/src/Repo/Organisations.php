<?php

declare(strict_types=1);

namespace Cecureus\Repo;

use Cecureus\Config;
use Cecureus\Db;
use Cecureus\Support\Time;

use const Cecureus\ROOT;

/**
 * Corporate eligibility and entitlement (BACKEND_BLOCKERS B-03).
 *
 * The `organization` table has no email-domain or sessions-per-year column, so the mapping lives in
 * config/organisations.php:  'company.com' => ['name' => 'Company', 'sessionsPerYear' => 6].
 * Replace this class if you keep that information elsewhere (e.g. a new table).
 */
final class Organisations
{
    /** @return array{name: string, sessionsPerYear: int|null}|null */
    public static function forEmail(string $email): ?array
    {
        $domain = strtolower(substr((string) strrchr($email, '@'), 1));
        return $domain === '' ? null : (self::all()[$domain] ?? null);
    }

    /** @return array{name: string, sessionsPerYear: int|null}|null */
    public static function byName(string $name): ?array
    {
        foreach (self::all() as $org) {
            if (strcasecmp($org['name'], trim($name)) === 0) {
                return $org;
            }
        }
        return null;
    }

    /** Sessions left this calendar year for a corporate employee; null when no entitlement is configured. */
    public static function sessionsRemaining(array $employee): ?int
    {
        $org = self::forEmail((string) $employee['emp_email'])
            ?? self::byName((string) $employee['emp_companyname'])
            ?? self::byName((string) $employee['emp_organisationname']);
        if ($org === null || $org['sessionsPerYear'] === null) {
            return null;
        }
        $year = substr(Time::istParts(time())['ymd'], 0, 4);
        $statuses = Db::all(
            'SELECT status FROM booking_appointment WHERE customer_id = ? AND booknewdate LIKE ?',
            [(string) $employee['emp_id'], $year . '-%'],
        );
        $used = count(array_filter($statuses, static fn (array $b) => !Bookings::isCancelledStatus($b['status'])));
        return max(0, $org['sessionsPerYear'] - $used);
    }

    /** @return array<string, array{name: string, sessionsPerYear: int|null}> */
    private static function all(): array
    {
        static $orgs = null;
        if ($orgs === null) {
            $orgs = [];
            $file = ROOT . '/config/organisations.php';
            $config = is_file($file) ? require $file : [];
            foreach (is_array($config) ? $config : [] as $domain => $org) {
                if (!is_array($org) || !isset($org['name']) || (!empty($org['demoOnly']) && Config::isProduction())) {
                    continue;
                }
                $orgs[strtolower((string) $domain)] = [
                    'name' => (string) $org['name'],
                    'sessionsPerYear' => isset($org['sessionsPerYear']) ? (int) $org['sessionsPerYear'] : null,
                ];
            }
        }
        return $orgs;
    }
}
