# CecureUs — Mobile App

Mental health & wellbeing app for corporate employees (B2B) and individuals (B2C).
Expo SDK 57 · React Native 0.86 · TypeScript · Expo Router. Android first; iOS later.
UI follows the Figma Make prototype "Build Prototype" (Nunito, teal `#17B8B0` / amber `#F59E0B` / navy `#1A2B4A`, Lucide icons).

> Status: Phase 1 app implemented and tested against a **mock** backend. A PHP reference backend for the
> whole API is in [`backend-php/`](backend-php/README.md) (tested in CI against MySQL with demo data, not yet
> deployed on the CecureUs server). Native builds are pending — see [PROJECT_STATUS.md](PROJECT_STATUS.md),
> [BACKEND_BLOCKERS.md](BACKEND_BLOCKERS.md) and [docs/PLAY_STORE.md](docs/PLAY_STORE.md).

## How the pieces fit

```
Android app (this repo, src/)  ──HTTPS JSON + Bearer token──▶  https://eap.cecureus.com/api/mobile/v1
                                                                (backend-php/, run by the backend team)
                                                                  ├─ MySQL: existing CecureUs tables
                                                                  ├─ SMS Integra (sign-up code, booking alert)
                                                                  └─ SMTP (sign-up email code)
```

The app contains **no secrets**: OTP codes are created, sent and checked by the server, and the SMS/email/DB
credentials live only in `backend-php/.env`. Details: [docs/BACKEND_INTEGRATION.md](docs/BACKEND_INTEGRATION.md).

## Quick start

```bash
npm install
cp .env.example .env.local     # fill in the API base URL
npm start                       # Expo dev server (press "a" for Android)
```

Requires Node 20+ (tested with Node 24). Native modules used are all in Expo Go, so Expo Go works for
development; release builds use EAS.

## Scripts

| Command | What |
|---------|------|
| `npm run typecheck` | TypeScript strict check |
| `npm run lint` | ESLint (Expo config + React Compiler rules, `no-console`) |
| `npm test` | Unit + integration tests (Jest, jest-expo, React Native Testing Library) |
| `npm run test:coverage` | Tests with coverage report |
| `npm run check:secrets` | Fails if an SMS gateway URL, credential or secret-looking `EXPO_PUBLIC_*` value is in the app |
| `npm run check` | All of the above — run before every commit |
| `npm run doctor` | Expo Doctor dependency/config diagnostics |
| `npm run mock-api` | Local mock backend on http://localhost:4000/v1 (any email, OTP `123456`) |

### Demo mode on your phone (no backend needed)

Set `EXPO_PUBLIC_DEMO_MODE=true` in `.env.local`, run `npx expo start -c`, and scan the QR code with Expo Go
(SDK 57). The app uses a built-in fake backend on the phone (stored on the device, survives reloads):

- **Get Started** → corporate or individual → email (code `123456`) → mobile (code `123456`) → name → password.
  Corporate sign-up needs a work email at a registered demo organisation: `@fixturecorp.com` or `@democorp.com`.
- **I already have an account** → user ID (email, mobile or employee ID) + the password you chose at sign-up.
  There are no pre-made accounts: create one first with Get Started.

A small "DEMO · sample data" badge is shown. Demo mode is refused outside development builds.

### Preview the UI without a backend or Android device

```bash
npm run mock-api                                               # terminal 1
EXPO_PUBLIC_API_BASE_URL=http://localhost:4000/v1 npm run web  # terminal 2
```

The web build is for design review only (sessions are kept in `sessionStorage`); it is never shipped.

## Project layout

```
src/
  app/                 Expo Router routes
    (auth)/            Welcome, email entry, OTP verification
    (app)/(tabs)/      Home, Explore, Ally (Phase 2 placeholder), Counsellor, Profile
    (app)/…            Counsellor profile, booking, confirmation, sessions, articles, videos,
                       assessments, breathing, journal, goals, progress, profile edit, privacy,
                       delete account, emergency support
  api/                 HTTP client, error mapping, zod schemas, typed endpoints
  auth/                Session storage (SecureStore) and AuthProvider
  features/booking/    Idempotent booking submitter, history helpers
  local/               Encrypted on-device stores (journal, moods, goals, activity)
  lib/                 Pure helpers (dates, validation, contact links, HTML, breathing, streaks)
  components/          Design system (ui/) and shared components
  theme/               Colours, spacing, typography tokens
  test/                Mock API server + test harness (tests only)
docs/                  API contract, backend integration, security review, test plan, Play Store prep
backend-php/           PHP reference implementation of the mobile API (deployed by the backend team)
scripts/               mock API server, secrets check
.maestro/              Android E2E flows (not yet executed)
```

## Key decisions
- **No direct database access from the app.** All data goes through an authenticated HTTPS API
  (proposed in [docs/API_CONTRACT.md](docs/API_CONTRACT.md)). The existing `/api/{table}` endpoint is unsafe (see
  [docs/SECURITY.md](docs/SECURITY.md)).
- **Bookings are idempotent**: each booking request carries an `Idempotency-Key` reused on retry, so network
  failures can't create duplicate bookings or duplicate SMS. The app only shows "Confirmed" when the server says so.
- **Private by design**: journal, moods and goals never leave the device; employers never see individual data.
- **Phase 1 scope**: no payments, no AI chat (Ally tab offers human support routes instead).

## Releasing
Step-by-step guide: [docs/PLAY_STORE.md](docs/PLAY_STORE.md). Builds use EAS (`eas.json`: `preview` → APK,
`production` → AAB for Google Play).
