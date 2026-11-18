# CecureUs mobile API — PHP reference backend

This folder is a **complete, working example** of the server the CecureUs Android app talks to.
It implements every endpoint in [`docs/API_CONTRACT.md`](../docs/API_CONTRACT.md) on top of the
**existing CecureUs MySQL database**, using the tables the website already uses
(`employee_list`, `doctor_list`, `booking_appointment`, …) in the same row format.

The backend team can deploy it as-is, or use it as a template when adding the endpoints to the
existing PHP site. Either way, the app does not change: it only needs the base URL.

```
Base URL:  https://eap.cecureus.com/api/mobile/v1
Login:     Authorization: Bearer <accessToken>
Success:   {"status": true,  "data": …}
Error:     {"status": false, "message": "…", "code": "…"}   + HTTP 400/401/404/409/422/429/5xx
```

- Plain PHP 8.1+ with PDO. No framework. One file per area, all SQL kept in `src/Repo/`.
- **All secrets stay on the server**: the SMS Integra credentials, SMTP password, DB password and
  `APP_SECRET` live in `backend-php/.env`. The app never has them.
- OTPs are generated, hashed, sent and checked **here**. The app only forwards what the user typed.

> **Status:** written against the staging table structure and the app's contract.
> It has **not yet been run against the real CecureUs database**. Run it on staging with
> `bin/smoke-test.php` first (see [Testing](#testing)), and check the items under
> [Please confirm](#please-confirm-with-the-website-team).

---

## 1. Run it on your computer (10 minutes)

Needs PHP 8.1+ (extensions: `pdo_mysql`, `curl`, `mbstring`, `openssl`) and MySQL/MariaDB.
On Windows, XAMPP or Laragon has both.

```bash
cd backend-php
cp .env.example .env
```

Edit `.env` for local use:

```ini
APP_ENV=development
APP_SECRET=<output of: php -r "echo bin2hex(random_bytes(32));">
DB_HOST=127.0.0.1
DB_NAME=cecureus_local
DB_USER=root
DB_PASS=
SMS_DRIVER=log
MAIL_DRIVER=log
MEDIA_BASE_URL=https://eap.cecureus.com/uploads
```

Create a local database with the existing table structure, the new helper tables and demo data:

```bash
mysql -u root -e "CREATE DATABASE cecureus_local CHARACTER SET utf8mb4"
mysql -u root cecureus_local < sql/dev/000_existing_tables_structure.sql   # local only!
mysql -u root cecureus_local < sql/001_mobile_api_tables.sql
mysql -u root cecureus_local < sql/dev/001_demo_seed.sql                   # local only!
```

Start the server and run the end-to-end check:

```bash
php -S 0.0.0.0:8080 -t public public/index.php
php bin/smoke-test.php http://localhost:8080/api/mobile/v1     # in a second terminal
```

With `SMS_DRIVER=log` / `MAIL_DRIVER=log`, codes are written to `storage/logs/messages.log`
instead of being sent. Open that file to read the code while testing.

**Point the app at it:** in the app's `.env.local` set
`EXPO_PUBLIC_API_BASE_URL=http://<your PC's Wi-Fi IP>:8080/api/mobile/v1` (Android emulator:
`http://10.0.2.2:8080/api/mobile/v1`), keep `EXPO_PUBLIC_APP_ENV=development` and run `npx expo start -c`.
Plain `http://` is accepted by the app only for local/LAN addresses in development builds.

---

## 2. Deploy on eap.cecureus.com

1. **Upload** the `backend-php` folder to the server **outside the web root**, e.g. `/home/cecureus/mobile-api/`.
2. `cd /home/cecureus/mobile-api && composer install --no-dev` (only needed for `MAIL_DRIVER=smtp`).
3. `cp .env.example .env`, fill in every value, set `APP_ENV=production`. `chmod 600 .env`.
4. Make `storage/logs/` writable by PHP.
5. Run `sql/001_mobile_api_tables.sql` on the **real** database (it only creates new `mobile_*` tables).
   **Never run the files in `sql/dev/` on the real database.**
6. Route `https://eap.cecureus.com/api/mobile/v1/*` to `public/index.php`:

**Apache (VirtualHost access):**

```apache
Alias /api/mobile/v1 /home/cecureus/mobile-api/public
<Directory /home/cecureus/mobile-api/public>
    AllowOverride All
    Require all granted
</Directory>
```

**Apache shared hosting / cPanel (no VirtualHost access):** create `public_html/api/mobile/v1/`, copy
`public/index.php` and `public/.htaccess` into it, and change the one line in `index.php` to
`$backendRoot = '/home/cecureus/mobile-api';`. If the existing site already rewrites every `/api/*` URL
(that is how `/api/{table}` works), add this **above** its rule in `public_html/api/.htaccess`, or the request
never reaches the new folder:

```apache
RewriteRule ^mobile/v1/ - [L]
```

**Nginx + PHP-FPM:**

```nginx
location ^~ /api/mobile/v1/ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /home/cecureus/mobile-api/public/index.php;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
}
```

7. Check: `curl https://eap.cecureus.com/api/mobile/v1/health` → `{"status":true,"data":{"ok":true}}`.
   Then `curl https://eap.cecureus.com/api/mobile/v1/me` → `401 UNAUTHENTICATED` (proves routing + JSON errors).
8. Run `bin/smoke-test.php` against **staging** (with log drivers), never against production.

### Database user

Create a dedicated MySQL user for this API (not `root`, not the website's admin user):

| Table | Needs |
|-------|-------|
| `employee_list` | SELECT, INSERT, UPDATE |
| `booking_appointment` | SELECT, INSERT, UPDATE |
| `doctor_list`, `doct_language`, `doctor_timelist`, `service`, `issuse_list`, `language`, `blog_post`, `quiz_category`, `quiz_list`, `quiz_answer`, `percentage` | SELECT |
| `mobile_sessions`, `mobile_otp_requests`, `mobile_registrations`, `mobile_rate_limits`, `mobile_bookings` | SELECT, INSERT, UPDATE, DELETE |

It never uses DELETE on existing tables.

---

## 3. Where everything is

| Endpoint (relative to the base URL) | Login | Code |
|---|---|---|
| `POST auth/register/email/request` · `email/verify` · `phone/request` · `phone/verify` · `complete` | no | `src/Controllers/AuthController.php` |
| `POST auth/login` · `POST auth/logout` | no / yes | `src/Controllers/AuthController.php` |
| `GET me` · `PATCH me` · `DELETE me` | yes | `src/Controllers/ProfileController.php` |
| `GET issues` · `GET languages` | no | `src/Controllers/ReferenceController.php` |
| `GET counsellors` · `counsellors/{id}` · `counsellors/{id}/availability` | yes | `src/Controllers/CounsellorController.php`, `src/Repo/Counsellors.php` |
| `POST bookings` · `GET bookings` · `POST bookings/{id}/cancel` | yes | `src/Controllers/BookingController.php`, `src/Repo/Bookings.php` |
| `GET articles` · `articles/{id}` · `videos` | yes | `src/Controllers/ContentController.php`, `src/Repo/Articles.php`, `config/videos.php` |
| `GET assessments` · `assessments/{id}` · `POST assessments/{id}/submit` | yes | `src/Controllers/AssessmentController.php`, `src/Repo/Assessments.php` |
| `GET health` | no | `src/routes.php` |

```
public/index.php, .htaccess   entry point + URL rewrite
src/routes.php                every URL → controller method
src/App.php                   error handling (every error becomes the JSON envelope), CORS
src/Controllers/              input validation + the rules from the contract
src/Repo/                     ALL SQL (change column names here if the website differs)
src/Security/                 Sessions (bearer tokens), Otp, Passwords, RateLimiter
src/Notify/                   SMS Integra / SMTP / mail() / log drivers
config/organisations.php      corporate email domains + sessions per year
config/videos.php             video list (no table exists yet)
sql/001_mobile_api_tables.sql new helper tables (run on the real DB)
sql/dev/                      LOCAL ONLY: existing table structure + demo data
bin/smoke-test.php            end-to-end test of every endpoint
```

**Adding an endpoint:** add a line to `src/routes.php`, write a static method
`fn(Request $request, array $params): Response` in a controller, start it with
`Sessions::authenticate($request)` if it needs login, and `throw new ApiException(422, 'CODE', 'User message')`
for errors.

---

## 4. Security — what this backend does

| Rule | Where |
|---|---|
| SMS/SMTP/DB credentials only in server `.env`; SMS Integra called over HTTPS from the server | `.env`, `src/Notify/SmsIntegraSender.php` |
| OTP: `random_int` 6 digits, only an HMAC stored, 10 min expiry, 60 s resend, 5 wrong tries, 5 codes/target/hour, 20 codes/IP/hour, never returned by the API | `src/Security/Otp.php`, `AuthController` |
| Passwords: Argon2id/bcrypt; old MD5 hashes accepted once and upgraded on login | `src/Security/Passwords.php` |
| Login: same error for wrong user ID or password; 5 failures → 15 min lock; 100 failures/IP/15 min | `AuthController::login` |
| Tokens: 32 random bytes, only SHA-256 stored, 30 days, revoked on logout/delete/deactivation | `src/Security/Sessions.php` |
| Every user endpoint is scoped to the signed-in user (`customer_id = emp_id`) | `src/Repo/Bookings.php` |
| Only public counsellor fields are selected (no password, PAN, bank, phone, email, Zoom) | `src/Repo/Counsellors.php` |
| Bookings: Idempotency-Key replay, per-counsellor MySQL lock (tables are MyISAM), SMS after save, sent once | `BookingController` |
| SQL injection: every value is a bound `?` parameter | `src/Db.php` |
| Errors: details only in `storage/logs/app.log`; the client gets a generic message | `src/App.php` |
| `log` drivers refused in production | `src/Notify/Channels.php` |

### Must also be done on the server (outside this code)

1. **Switch off the public `/api/{table}` endpoint** (BACKEND_BLOCKERS B-00). It exposes password hashes,
   OTPs, bank and PAN data without login. No amount of app or API security helps while it is open.
2. **Rotate the SMS Integra password** that was shared earlier, and put the new one only in `.env`.
3. HTTPS only (redirect http → https), TLS 1.2+.
4. Back up the database before running `sql/001_mobile_api_tables.sql`.

---

## 5. Please confirm with the website team

These are the places where the table structure alone doesn't say how the website uses a column.
Each one is a single, clearly marked spot in the code:

| Question | Default used | Change in |
|---|---|---|
| `employee_list.status` values | `Active` = can sign in; deletion writes `Cancel` | `.env DELETED_STATUS`, `Repo/Employees.php` |
| Corporate vs individual column | `emp_option` = `Corporate` / `Individual` (as in existing rows) | `Repo/Employees.php` |
| How existing `emp_password` values are hashed | MD5 accepted and upgraded; empty = can't sign in | `.env LEGACY_PASSWORD_FORMATS` |
| Corporate email domains + sessions/year | `config/organisations.php` | `Repo/Organisations.php` |
| `doctor_timelist` day flag values / time format | flag `0/no/off/closed` = day off; times like `10:00 AM` | `Repo/Counsellors.php::workingRanges` |
| Slot length | `service.service_time` (default 60 min) | `Repo/Counsellors.php::serviceSettings` |
| Blog post title column | `post_category` | `Repo/Articles.php TITLE_COLUMN` |
| `percentage` table meaning | `per_title`–`per_titleto` = % band, `session` = label | `Repo/Assessments.php::band` |
| Image folders | `MEDIA_BASE_URL` (+ optional per-type URLs) | `.env` |
| DB connection charset | `utf8mb4` | `.env DB_CHARSET` |

**Existing users without a password:** users already in `employee_list` sign in with their current
password. Anyone without one (or with an unknown hash) can't sign in until a password-reset flow is
added (BACKEND_BLOCKERS B-17). Sign-up of a new account with an email/mobile that already has an
Active row returns `EMAIL_TAKEN` / `MOBILE_TAKEN`.

---

## Testing

`bin/smoke-test.php` creates a throw-away account and checks ~50 behaviours: OTP cooldown and wrong codes,
sign-up order, weak passwords, duplicate email, login by email and by mobile, generic login errors,
profile validation, no private counsellor fields, availability, booking idempotency (same key → same booking),
slot conflict (409), cancel, articles, assessments (no scores sent), logout revocation and account deletion.

```bash
php bin/smoke-test.php https://staging.example.com/api/mobile/v1 /path/to/storage/logs/messages.log
```

Manual calls with curl:

```bash
B=http://localhost:8080/api/mobile/v1
curl -s $B/issues
curl -s -X POST $B/auth/login -H 'Content-Type: application/json' -d '{"userId":"<email|mobile|employee ID>","password":"<password>"}'
curl -s $B/me -H "Authorization: Bearer <accessToken>"
curl -s -X POST $B/bookings -H "Authorization: Bearer <accessToken>" -H 'Idempotency-Key: 6f1c2d3e-0000-4000-8000-000000000001' \
     -H 'Content-Type: application/json' \
     -d '{"counsellorId":"1","slotStart":"2026-11-20T10:00:00+05:30","mode":"video","issueId":"1","language":"English","notes":null}'
```
