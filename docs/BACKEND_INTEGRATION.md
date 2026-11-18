# How the app talks to the backend

For: the backend developer building `https://eap.cecureus.com/api/mobile/v1`, and app developers.

```
 Android app (Expo)                     CecureUs server (PHP)                      Outside services
 ──────────────────                     ─────────────────────                      ────────────────
 src/api/endpoints.ts  ──HTTPS JSON──▶  /api/mobile/v1/*  (backend-php/)  ──SQL──▶ MySQL (existing tables)
   Bearer token in                        • creates + checks OTP codes      ──HTTPS▶ SMS Integra (OTP, booking alert)
   Android Keystore                       • hashes passwords, issues tokens ──SMTP─▶ Email (sign-up code)
                                          • all secrets in backend-php/.env
```

**The app holds no secrets.** It does not know the SMS Integra login, the SMTP password or the database
password, and it never generates or checks an OTP. Everything the app ships is public; everything secret is
in `backend-php/.env` on the server. `npm run check:secrets` (also in CI) fails the build if that changes.

## 1. One setting: the base URL

| Where | Value |
|---|---|
| Production | `EXPO_PUBLIC_API_BASE_URL=https://eap.cecureus.com/api/mobile/v1` (EAS environment `production`) |
| Staging / preview APK | the staging server's URL (EAS environment `preview`) |
| Local development | `.env.local` → `http://<PC Wi-Fi IP>:8080/api/mobile/v1` (PHP dev server, see `backend-php/README.md`) |

`src/config/env.ts` refuses a non-HTTPS URL except for local/LAN addresses in development builds.

## 2. What every request looks like

All calls go through one function, `src/api/client.ts`. For `api.counsellors.get('7')`:

```http
GET https://eap.cecureus.com/api/mobile/v1/counsellors/7
Accept: application/json
Authorization: Bearer 3kq…F1w                ← omitted for auth/*, issues, languages
```

Only four HTTP methods are ever used — **GET** (read), **POST** (create / actions), **PUT** (update profile)
and **DELETE** (delete account) — because the CecureUs server allows only these. The client's `HttpMethod`
type (`src/api/client.ts`) doesn't allow anything else, and the server answers other methods with 405.

For writes the body is JSON (`Content-Type: application/json`). The client:

- times out after 15 s (30 s for bookings) and shows "check your connection";
- expects `{"status": true, "data": …}` and validates `data` against `src/api/schemas.ts` (unknown fields are
  dropped, so even an over-sharing server can't leak e.g. password hashes into the app);
- on an error reads `{"status": false, "message", "code"}`. The `message` is shown to the user for 400/409/422;
  for other statuses the app shows its own friendly text. `code` drives specific behaviour (e.g. `OTP_EXPIRED`);
- on **401** from any logged-in call, deletes the stored session and returns to the sign-in screen;
- on **429** reads the `Retry-After` header (seconds) to show a countdown.

## 3. Endpoint → app function

| HTTP (relative to base URL) | App function (`src/api/endpoints.ts`) | Screen |
|---|---|---|
| `POST auth/register/email/request` `{email, userType}` | `auth.register.requestEmailOtp` | Get Started → email |
| `POST auth/register/email/verify` `{requestId, otp}` | `auth.register.verifyEmailOtp` | email code |
| `POST auth/register/phone/request` `{registrationToken, mobile}` | `auth.register.requestPhoneOtp` | mobile number |
| `POST auth/register/phone/verify` `{registrationToken, requestId, otp}` | `auth.register.verifyPhoneOtp` | SMS code |
| `POST auth/register/complete` `{registrationToken, firstName, lastName, password, employeeId}` | `auth.register.complete` | name + password |
| `POST auth/login` `{userId, password}` | `auth.login` | I already have an account |
| `POST auth/logout` | `auth.logout` | Profile → Sign out |
| `GET me` · `PUT me` · `DELETE me` | `profile.get` · `profile.update` · `profile.requestDeletion` | Profile, Edit Profile, Delete Account |
| `GET issues` · `GET languages` | `reference.issues` · `reference.languages` | booking form |
| `GET counsellors?search=&expertise=&page=` | `counsellors.list` | Counsellor tab |
| `GET counsellors/{id}` · `…/availability?from=&to=` | `counsellors.get` · `counsellors.availability` | profile + calendar |
| `POST bookings` (header `Idempotency-Key`) | `bookings.create` | Book → Confirm |
| `GET bookings?page=` · `POST bookings/{id}/cancel` | `bookings.list` · `bookings.cancel` | My Sessions |
| `GET articles` · `GET articles/{id}` · `GET videos` | `content.articles` · `content.article` · `content.videos` | Explore |
| `GET assessments` · `GET assessments/{id}` · `POST assessments/{id}/submit` | `assessments.*` | Self assessments |

Response shapes: `docs/API_CONTRACT.md`. Working server code for each: `backend-php/src/Controllers/`.

## 4. Sign-up and sign-in, step by step

```text
App                                                      Server
 │ POST auth/register/email/request {email, userType}  →  checks email free + company domain,
 │                                                        makes a 6-digit code, stores its HMAC,
 │ ← {requestId, expiresInSeconds: 600, resendAfterSeconds: 60}   emails the code
 │ POST auth/register/email/verify {requestId, otp}     →  checks code (10 min, 5 tries)
 │ ← {registrationToken}
 │ POST auth/register/phone/request {registrationToken, mobile} → sends SMS code via SMS Integra
 │ ← {requestId, …}
 │ POST auth/register/phone/verify {registrationToken, requestId, otp}
 │ ← null
 │ POST auth/register/complete {registrationToken, firstName, lastName, password, employeeId}
 │                                                        → inserts employee_list row (Argon2id password)
 │ ← 201 {accessToken, expiresAt, user}
 │ (token saved in Android Keystore via expo-secure-store)
 │
 │ later: POST auth/login {userId: email | mobile | employee ID, password} ← {accessToken, expiresAt, user}
```

Example (what the app sends, reproduced with curl):

```bash
B=https://eap.cecureus.com/api/mobile/v1
curl -s -X POST $B/auth/register/email/request -H 'Content-Type: application/json' \
     -d '{"email":"<work email>","userType":"corporate"}'
# {"status":true,"data":{"requestId":"9f0c…","expiresInSeconds":600,"resendAfterSeconds":60}}

curl -s -X POST $B/auth/login -H 'Content-Type: application/json' \
     -d '{"userId":"<email, mobile or employee ID>","password":"<password chosen at sign-up>"}'
# {"status":true,"data":{"accessToken":"…","expiresAt":"…","user":{"id":"…",…}}}
# wrong ID or password → 422 {"status":false,"message":"Incorrect user ID or password.","code":"INVALID_CREDENTIALS"}
```

## 5. Bookings never double up

The app creates one random `Idempotency-Key` per booking attempt and re-sends the **same** key if the
network drops. The server returns the original booking for a repeated key, so a retry can never create a
second booking or a second SMS. The app shows "Confirmed" only when the server says `status: "confirmed"`
(the team adds the Zoom link); otherwise "Request received".

## 6. Trying it end to end on one PC

```bash
# terminal 1 — backend (see backend-php/README.md for the one-time DB setup)
cd backend-php && php -S 0.0.0.0:8080 -t public public/index.php
# terminal 2 — app
echo "EXPO_PUBLIC_API_BASE_URL=http://10.0.2.2:8080/api/mobile/v1" >> .env.local
npx expo start -c        # press "a" for the Android emulator
```

Sign-up codes appear in `backend-php/storage/logs/messages.log` (development drivers).
