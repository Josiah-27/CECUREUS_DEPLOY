<?php

declare(strict_types=1);

namespace Cecureus\Controllers;

use Cecureus\Config;
use Cecureus\Db;
use Cecureus\Http\ApiException;
use Cecureus\Http\Request;
use Cecureus\Http\Response;
use Cecureus\Notify\Channels;
use Cecureus\Repo\Employees;
use Cecureus\Repo\Organisations;
use Cecureus\Repo\Registrations;
use Cecureus\Security\Otp;
use Cecureus\Security\Passwords;
use Cecureus\Security\RateLimiter;
use Cecureus\Security\Sessions;
use Cecureus\Support\Log;
use Cecureus\Support\Text;

/**
 * Sign-up: email code → mobile code → name + password (docs/API_CONTRACT.md §1).
 * Sign-in: user ID (email | mobile | employee ID) + password.
 *
 * The OTP is generated, stored (hashed), sent and checked HERE. The app never sees the SMS key
 * or the code itself — it only forwards what the person typed.
 */
final class AuthController
{
    private const OTP_IP_LIMIT_PER_HOUR = 20;

    /** POST /auth/register/email/request {email, userType} */
    public static function registerEmailRequest(Request $request): Response
    {
        $body = $request->json();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $userType = ($body['userType'] ?? '') === 'corporate' ? 'corporate' : 'individual';

        if (!Text::isEmail($email)) {
            throw ApiException::validation('Enter a valid email address.', 'INVALID_EMAIL');
        }
        if (Employees::emailTaken($email)) {
            throw new ApiException(409, 'EMAIL_TAKEN', 'An account with this email already exists. Please sign in instead.');
        }
        $company = '';
        if ($userType === 'corporate') {
            $org = Organisations::forEmail($email);
            if ($org === null) {
                throw ApiException::validation(
                    'Your organisation is not registered with CecureUs yet. Check your work email or continue as an individual.',
                    'COMPANY_NOT_REGISTERED',
                );
            }
            $company = $org['name'];
        }
        self::limitOtpPerIp($request);

        $otp = Otp::issue('register_email', $email, null, ['userType' => $userType, 'company' => $company]);
        try {
            Channels::mail()->send(
                $email,
                'Your CecureUs verification code',
                "Your CecureUs verification code is {$otp['code']}.\n\nIt is valid for 10 minutes. Do not share this code with anyone.\n\nIf you did not request it, you can ignore this email.",
            );
        } catch (\Throwable $e) {
            Otp::discard($otp['requestId']);
            Log::error('Email OTP to ' . Text::maskEmail($email) . ' failed', $e);
            throw new ApiException(503, 'OTP_SEND_FAILED', "We couldn't send the code. Please try again.");
        }
        return Response::ok(self::otpResponse($otp['requestId']));
    }

    /** POST /auth/register/email/verify {requestId, otp} → {registrationToken} */
    public static function registerEmailVerify(Request $request): Response
    {
        $body = $request->json();
        $row = Otp::verify($body['requestId'] ?? null, $body['otp'] ?? null, 'register_email');
        $meta = json_decode((string) $row['meta'], true) ?: [];
        $email = (string) $row['target'];
        if (Employees::emailTaken($email)) {
            throw new ApiException(409, 'EMAIL_TAKEN', 'An account with this email already exists. Please sign in instead.');
        }
        $token = Registrations::create(
            $email,
            ($meta['userType'] ?? '') === 'corporate' ? 'corporate' : 'individual',
            (string) ($meta['company'] ?? ''),
        );
        return Response::ok(['registrationToken' => $token]);
    }

    /** POST /auth/register/phone/request {registrationToken, mobile} */
    public static function registerPhoneRequest(Request $request): Response
    {
        $body = $request->json();
        $registration = Registrations::find($body['registrationToken'] ?? null);
        $mobile = Text::normalizeMobile($body['mobile'] ?? null);
        if ($mobile === null) {
            throw ApiException::validation('Enter a valid 10-digit Indian mobile number.', 'INVALID_MOBILE');
        }
        if (Employees::mobileTaken($mobile)) {
            throw new ApiException(409, 'MOBILE_TAKEN', 'This mobile number is already linked to an account. Please sign in instead.');
        }
        self::limitOtpPerIp($request);

        Registrations::setMobile((string) $registration['token_hash'], $mobile);
        $otp = Otp::issue('register_phone', $mobile, (string) $registration['token_hash']);
        $message = str_replace(
            '{otp}',
            $otp['code'],
            Config::get('SMS_OTP_MESSAGE', 'Your CecureUs verification code is {otp}. It is valid for 10 minutes. Do not share this code with anyone.') ?? '',
        );
        try {
            Channels::sms()->send($mobile, $message, Config::get('SMS_OTP_TEMPLATE_ID', '') ?? '');
        } catch (\Throwable $e) {
            Otp::discard($otp['requestId']);
            Log::error('SMS OTP to ' . Text::maskMobile($mobile) . ' failed', $e);
            throw new ApiException(503, 'OTP_SEND_FAILED', "We couldn't send the code. Please try again.");
        }
        return Response::ok(self::otpResponse($otp['requestId']));
    }

    /** POST /auth/register/phone/verify {registrationToken, requestId, otp} → null */
    public static function registerPhoneVerify(Request $request): Response
    {
        $body = $request->json();
        $registration = Registrations::find($body['registrationToken'] ?? null);
        $row = Otp::verify($body['requestId'] ?? null, $body['otp'] ?? null, 'register_phone', (string) $registration['token_hash']);
        if ($row['target'] !== $registration['mobile']) {
            throw ApiException::validation('This code is not valid.', 'OTP_INVALID');
        }
        Registrations::markPhoneVerified((string) $registration['token_hash']);
        return Response::ok(null);
    }

    /** POST /auth/register/complete {registrationToken, firstName, lastName, password, employeeId} → 201 AuthSession */
    public static function registerComplete(Request $request): Response
    {
        $body = $request->json();
        $registration = Registrations::find($body['registrationToken'] ?? null);
        if ($registration['phone_verified_at'] === null || $registration['mobile'] === null) {
            throw ApiException::validation('Please verify your email and mobile number first.', 'VERIFICATION_REQUIRED');
        }
        $firstName = Text::clean($body['firstName'] ?? '', 100);
        $lastName = Text::clean($body['lastName'] ?? '', 100);
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $corporate = $registration['user_type'] === 'corporate';
        $employeeId = $corporate ? Text::clean($body['employeeId'] ?? '', 50) : '';

        if ($firstName === '') {
            throw ApiException::validation('First name is required.');
        }
        if (!Passwords::strongEnough($password)) {
            throw ApiException::validation('Password must be at least 8 characters with a letter and a number.', 'WEAK_PASSWORD');
        }

        // Serialise account creation so two parallel requests can't create duplicate accounts.
        $lock = 'cecureus_register';
        if (!Db::lock($lock)) {
            throw new ApiException(503, 'BUSY', 'The server is busy. Please try again.');
        }
        try {
            $email = (string) $registration['email'];
            $mobile = (string) $registration['mobile'];
            if (Employees::emailTaken($email)) {
                throw new ApiException(409, 'EMAIL_TAKEN', 'An account with this email already exists. Please sign in instead.');
            }
            if (Employees::mobileTaken($mobile)) {
                throw new ApiException(409, 'MOBILE_TAKEN', 'This mobile number is already linked to an account. Please sign in instead.');
            }
            if ($employeeId !== '' && Employees::employeeIdTaken($employeeId, (string) $registration['company_name'])) {
                throw new ApiException(409, 'EMPLOYEE_ID_TAKEN', 'This employee ID is already registered.');
            }
            $empId = Employees::create([
                'emp_name' => $firstName,
                'emp_lname' => $lastName,
                'emp_email' => $email,
                'emp_mobile' => Text::mobile10($mobile),
                'emp_password' => Passwords::hash($password),
                'emp_option' => $corporate ? 'Corporate' : 'Individual',
                'emp_companyname' => $corporate ? (string) $registration['company_name'] : '',
                'emp_organisationname' => $corporate ? (string) $registration['company_name'] : '',
                'emp_companyid' => $employeeId,
                'emp_agree' => 'yes',
            ]);
        } finally {
            Db::unlock($lock);
        }
        Registrations::delete((string) $registration['token_hash']);

        $employee = Employees::findActiveById($empId);
        if ($employee === null) {
            throw new \RuntimeException("New account {$empId} not readable after insert");
        }
        Log::info("Account {$empId} created from the app");
        return Response::ok(Sessions::create($employee, $request), 201);
    }

    /** POST /auth/login {userId, password} → AuthSession */
    public static function login(Request $request): Response
    {
        $body = $request->json();
        $userId = trim((string) (is_scalar($body['userId'] ?? null) ? $body['userId'] : ''));
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $invalid = ApiException::validation('Incorrect user ID or password.', 'INVALID_CREDENTIALS');
        if ($userId === '' || $password === '' || strlen($password) > 128) {
            throw $invalid;
        }

        // Failed attempts only: 5 per user ID and 100 per IP (offices share one IP) per 15 minutes.
        $key = 'login:' . strtolower($userId);
        $ipKey = 'login-ip:' . $request->ip();
        RateLimiter::check($key, 5, 900, 'LOGIN_LOCKED', 'Too many failed attempts. Please try again in 15 minutes.');
        RateLimiter::check($ipKey, 100, 900, 'LOGIN_LOCKED', 'Too many failed attempts. Please try again in 15 minutes.');

        $match = null;
        $candidates = Employees::loginCandidates($userId);
        foreach ($candidates as $row) {
            if (Passwords::verify((string) $row['emp_password'], $password)) {
                $match = $row;
                break;
            }
        }
        if ($candidates === []) {
            Passwords::burn($password);
        }
        if ($match === null) {
            RateLimiter::hit($key, 900);
            RateLimiter::hit($ipKey, 900);
            // Same answer whether the user ID or the password is wrong (no account enumeration).
            throw $invalid;
        }
        RateLimiter::reset($key);

        // Upgrade legacy MD5 (or older-cost) hashes on the first successful login.
        if (Passwords::needsRehash((string) $match['emp_password'])) {
            Employees::update((int) $match['emp_id'], ['emp_password' => Passwords::hash($password)]);
        }
        return Response::ok(Sessions::create($match, $request));
    }

    /** POST /auth/logout (login needed) */
    public static function logout(Request $request): Response
    {
        Sessions::authenticate($request);
        Sessions::revoke($request->bearerToken());
        return Response::ok(null);
    }

    private static function otpResponse(string $requestId): array
    {
        return ['requestId' => $requestId, 'expiresInSeconds' => Otp::TTL_SECONDS, 'resendAfterSeconds' => Otp::RESEND_SECONDS];
    }

    private static function limitOtpPerIp(Request $request): void
    {
        RateLimiter::consume(
            'otp-ip:' . $request->ip(),
            self::OTP_IP_LIMIT_PER_HOUR,
            3600,
            'OTP_LIMIT',
            'Too many codes requested. Please try again later.',
        );
    }
}
