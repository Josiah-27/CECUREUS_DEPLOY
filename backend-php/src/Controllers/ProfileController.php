<?php

declare(strict_types=1);

namespace Cecureus\Controllers;

use Cecureus\Config;
use Cecureus\Http\ApiException;
use Cecureus\Http\Request;
use Cecureus\Http\Response;
use Cecureus\Repo\Bookings;
use Cecureus\Repo\Employees;
use Cecureus\Security\RateLimiter;
use Cecureus\Security\Sessions;
use Cecureus\Support\Log;
use Cecureus\Support\Text;

final class ProfileController
{
    /** GET /me */
    public static function show(Request $request): Response
    {
        return Response::ok(Employees::toUser(Sessions::authenticate($request)));
    }

    /** PUT /me {firstName, lastName, age} — email and mobile are verified at sign-up and not editable. */
    public static function update(Request $request): Response
    {
        $employee = Sessions::authenticate($request);
        $empId = (int) $employee['emp_id'];
        RateLimiter::consume("profile:{$empId}", 20, 3600);
        $body = $request->json();

        $firstName = Text::clean($body['firstName'] ?? '', 100);
        if ($firstName === '') {
            throw ApiException::validation('First name is required.');
        }
        $age = $body['age'] ?? null;
        if ($age !== null && (!is_int($age) || $age < 18 || $age > 100)) {
            throw ApiException::validation('Enter an age between 18 and 100.', 'INVALID_AGE');
        }
        Employees::update($empId, [
            'emp_name' => $firstName,
            'emp_lname' => Text::clean($body['lastName'] ?? '', 100),
            'emp_age' => $age === null ? '' : (string) $age,
        ]);
        return Response::ok(Employees::toUser(Employees::findActiveById($empId) ?? $employee));
    }

    /**
     * DELETE /me — account deletion (Google Play requirement).
     * Cancels upcoming bookings, signs out every device and marks the row deleted. With
     * ANONYMISE_ON_DELETE=true (default) personal fields are also cleared; past bookings are kept
     * for records, linked only by emp_id. Confirm the retention policy (BACKEND_BLOCKERS B-13).
     */
    public static function destroy(Request $request): Response
    {
        $employee = Sessions::authenticate($request);
        $empId = (int) $employee['emp_id'];
        RateLimiter::consume("delete:{$empId}", 3, 86400);

        Bookings::cancelUpcomingFor($empId, 'Account deleted');
        $fields = ['status' => Config::get('DELETED_STATUS', 'Cancel') ?? 'Cancel', 'emp_password' => '', 'emp_otp' => ''];
        if (Config::bool('ANONYMISE_ON_DELETE', true)) {
            $fields += [
                'emp_name' => 'Deleted',
                'emp_lname' => 'User',
                'emp_email' => '',
                'emp_mobile' => '',
                'emp_companyid' => '',
                'emp_dob' => '',
                'emp_age' => '',
                'emp_gender' => '',
                'emp_location' => '',
                'emp_maritalstatus' => '',
                'emp_emargenumber' => '',
            ];
        }
        Employees::update($empId, $fields);
        Sessions::revokeAll($empId);
        Log::info("Account {$empId} deleted from the app");
        return Response::ok(null);
    }
}
