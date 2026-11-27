# Contributing to CecureUs

Thanks for helping out. This repo holds two things that must stay in sync:

- **The app** — Expo SDK 57 / React Native / TypeScript, lives in `src/` (see [README.md](README.md)).
- **The PHP reference backend** — plain PHP 8.1 in `backend-php/`, a working implementation of
  [docs/API_CONTRACT.md](docs/API_CONTRACT.md) (see [backend-php/README.md](backend-php/README.md)).

Changes on one side usually affect the other. If you touch the API, update
`docs/API_CONTRACT.md` **and** `backend-php/` in the same PR, then run both test suites (below).

## Getting set up

**App (Node 20+):**

```bash
npm install
cp .env.example .env.local    # then fill in the API base URL
```

**Backend (PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`; MySQL/MariaDB):**

```bash
cd backend-php
composer install              # needed once, for the style check
cp .env.example .env          # SMS_DRIVER=log, MAIL_DRIVER=log write codes to storage/logs
mysql -u root -e "CREATE DATABASE cecureus_local CHARACTER SET utf8mb4"
mysql -u root cecureus_local < sql/dev/000_existing_tables_structure.sql   # local only!
mysql -u root cecureus_local < sql/001_mobile_api_tables.sql
mysql -u root cecureus_local < sql/dev/001_demo_seed.sql                   # local only!
```

## Before you open a PR

**App checks (all of them run with one command):**

```bash
npm run check                 # check:secrets + typecheck + lint + test:coverage
```

**Backend checks:**

```bash
php tests/run.php             # unit + integration tests (DB tests skip without a database)
composer cs:check             # PHP style check (composer cs:fix to apply)
php -l <file>                 # syntax check happens in CI for every PHP file
```

If you changed the backend, also run the end-to-end suites against your local database:

```bash
php -S 127.0.0.1:8080 -t public public/index.php     # keep PHP_CLI_SERVER_WORKERS=8 for the race test
php bin/smoke-test.php http://127.0.0.1:8080/api/mobile/v1
php bin/race-test.php   http://127.0.0.1:8080/api/mobile/v1
```

CI runs all of this on every PR (two jobs: `check` for the app, `backend-php` for the API) and a PR
is only mergeable when both are green.

## Rules of the codebase

**App (`src/`):**

- Routes live in `src/app/` (Expo Router) — every file there is a screen. Components, hooks and
  helpers stay outside it.
- Never put secrets in the app: no SMS gateway URLs, no credentials, no secret-looking
  `EXPO_PUBLIC_*` values. `npm run check:secrets` fails the build when you do; OTPs and credentials
  belong to the server only.
- Use `npx expo install <package>` instead of `npm add` so versions match the SDK.
- Follow the existing theme tokens (`src/theme/`) and the Figma palette — Nunito, teal `#17B8B0`,
  amber `#F59E0B`, navy `#1A2B4A`.
- Never create or edit `ios/` / `android/`; they are generated (CNG). Configure `app.json` instead.

**Backend (`backend-php/`):**

- One file per area; **all SQL lives in `src/Repo/`** and every value is a bound `?` parameter.
  Controllers do validation and the contract rules, never SQL.
- Errors are `throw new ApiException(<status>, '<CODE>', '<user message>')`; every failure must come
  out as the JSON envelope, never a PHP error page.
- Endpoints that need login start with `Sessions::authenticate($request)`, and every user-scoped
  query is filtered by the signed-in user's id.
- Anything user-visible must be covered by `tests/run.php` (unit test without DB, integration test
  with DB where it makes sense).
- PHP style is enforced by `.php-cs-fixer.php` — run `composer cs:fix` before committing new files.
- `composer.json` pins `"config": {"platform": {"php": "8.1.0"}}` so the lock file stays installable
  on PHP 8.1 (CI) even if you run a newer PHP locally. Do not remove it, and commit `composer.lock`
  together with `composer.json` when you change dependencies.

## Tests: add them, don't skip them

- New app behaviour: Jest tests next to the code (`src/**/__tests__/`), using the mock API in
  `src/test/`. `npm run test:coverage` reports what is covered.
- New backend endpoint or rule: a test file in `backend-php/tests/` (`*Test.php`, plain PHP — no
  framework; look at `tests/OtpTest.php` for the pattern). Use `needDb()` only for the parts that
  really need MySQL.
- Bug fixes: add the regression test first, confirm it fails, then fix. Name the test after the
  behaviour ("lowercase bearer scheme authenticates"), not after the bug report number.
- `.maestro/` holds the Android E2E flows; if you change a screen that one drives, update the flow.

## Commits and PRs

- One logical change per PR. Mixed "fix + feature + reformat" PRs are hard to review and get sent back.
- Commit messages say what changed and why, in one line ("Rate-limit the taken email and mobile
  answers"), more detail in the body if needed.
- Do not commit `.env`, logs (`storage/logs/`), `vendor/`, or `node_modules/`.
- Describe the PR: what, why, how you tested it, and screenshots for UI changes.
- The PR must be green on both CI jobs before review. If `backend-php` fails, read the log — the
  failing step (syntax, style, tests, smoke, race) is named in the job output.

## Reporting bugs and security issues

- Bugs: open an issue with what you did, what you expected, and what happened (logs/screenshots).
- Security (exposed secrets, auth bypass, injection, the public `/api/{table}` problem): read
  [docs/SECURITY.md](docs/SECURITY.md) first. Please **do not post exploitable details publicly** —
  contact the maintainers directly and wait before disclosing.
- Feature ideas: check [PROJECT_STATUS.md](PROJECT_STATUS.md) and [BACKEND_BLOCKERS.md](BACKEND_BLOCKERS.md)
  first — many "missing" things are already tracked there with a reason.
