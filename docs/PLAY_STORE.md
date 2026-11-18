# Google Play Release Preparation

Account: organisation developer account owned by CecureUs (`connect@cecureus.com`). Organisation accounts need
D-U-N-S verification. **Nothing has been submitted. No money has been spent.**

## Step by step: from this repo to Google Play

The app is built in the cloud with **EAS Build** (no Android Studio needed). Commands run from the repo root.

**Before the first build (once)**
1. Backend live: `https://eap.cecureus.com/api/mobile/v1/health` returns `{"status":true,…}` and the
   smoke test passes on staging (`backend-php/README.md`). The public `/api/{table}` endpoint is switched off.
2. Privacy policy, terms and an account-deletion web page are live (B-15, B-13).
3. Official 1024×1024 icon in `assets/images/` (current icon is an interim extract).
4. `npm ci && npm run check` passes (secrets scan, typecheck, lint, tests).

**Link the project to Expo (once)**
```bash
npx eas-cli@latest login                 # the CecureUs Expo account
npx eas-cli@latest init                  # writes the EAS projectId into app.json — commit it
```

**Set the public app settings per environment (once).** These are not secrets; they are compiled into the app.
`EXPO_PUBLIC_APP_ENV` and `EXPO_PUBLIC_DEMO_MODE=false` are already fixed per profile in `eas.json`.
```bash
for ENV in preview production; do
  npx eas-cli@latest env:create --environment $ENV --visibility plaintext --name EXPO_PUBLIC_API_BASE_URL       --value https://eap.cecureus.com/api/mobile/v1
  npx eas-cli@latest env:create --environment $ENV --visibility plaintext --name EXPO_PUBLIC_MEDIA_BASE_URL     --value https://eap.cecureus.com/uploads
  npx eas-cli@latest env:create --environment $ENV --visibility plaintext --name EXPO_PUBLIC_PRIVACY_POLICY_URL --value https://cecureus.com/privacy-policy
  npx eas-cli@latest env:create --environment $ENV --visibility plaintext --name EXPO_PUBLIC_TERMS_URL          --value https://cecureus.com/terms
done
```
(Point `preview` at the staging API URL instead if there is one.) **Never** add SMS, email or database
credentials to EAS — they go only in `backend-php/.env` on the server.

**1. Test build (APK you can install directly)**
```bash
npx eas-cli@latest build -p android --profile preview
```
Install the APK from the link on a real phone; run the manual test plan (`docs/TEST_PLAN.md`) and the Maestro flows.

**2. Store build (AAB)**
```bash
npx eas-cli@latest build -p android --profile production
```
EAS creates and keeps the upload key the first time (answer "yes"); the version code auto-increments.

**3. First upload — manual, once.** Play Console → *Create app* (name CecureUs, App, Free) → fill *App content*
(privacy policy URL, app access with the reviewer account below, ads: none, content rating, target audience 18+,
data safety from the table below, health apps declaration, account-deletion URL) → *Testing → Internal testing →
Create release* → upload the `.aab` downloaded from the EAS build page. Turn on **Play App Signing**.

**4. Later uploads — automatic**
Create a Google Play service-account JSON key (Play Console → Setup → API access), keep it out of git
(`play-service-account*.json` is ignored), then:
```bash
npx eas-cli@latest submit -p android --profile production    # uploads the latest build as a draft to Internal testing
```

**5. Promote** Internal testing → Closed testing (personal accounts: ≥12 testers for 14 days) → Production.

**Every release:** bump `version` in `app.json` for user-visible releases, run `npm run check`, build, test the
preview APK against the live backend, then submit.

## Timeline risk (launch target 31 Oct 2026)
- **Personal** developer accounts created after Nov 2023 must run a **closed test with ≥12 testers for 14 continuous
  days** before production access. An **organisation** account is exempt — confirm which account type CecureUs has.
- First review of a new app can take several days (longer for health apps). Health apps must complete the
  **Health apps declaration**.
- Realistically, with backend blockers open on 20 Oct, a 31 Oct public launch is at high risk; an **internal testing**
  release by 31 Oct is achievable once the API exists.

## Technical checklist
| Item | Value / status |
|------|----------------|
| Application ID | `com.cecureus.app` — **permanent once published; confirm before first upload** |
| App name | CecureUs |
| Version | 1.0.0; versionCode managed remotely by EAS (`autoIncrement`) |
| Target API | Expo SDK 57 / React Native 0.86 target Android 16 (API 36) by default — verify in the built AAB (`aapt dump badging`) |
| Signing | EAS-managed upload key + Play App Signing (recommended) |
| Format | AAB via `eas build -p android --profile production` |
| Permissions | INTERNET only; camera/mic/location/storage/overlay blocked |
| Icon | Interim icon extracted from the design PDF — **replace with official high-res logo (1024×1024 PNG / SVG)** |

## Store listing (draft)
**Short description (≤80):** Confidential counselling, self-assessments and wellbeing tools at work and beyond.

**Full description:**
CecureUs helps you look after your mental health — privately and confidentially.

• Talk to certified counsellors: browse verified psychologists by expertise and language, see availability and book audio, video or chat sessions.
• Self assessments: quick check-ins for stress, burnout, anxiety and work-life balance.
• Breathing exercises: guided 2-minute calm breathing, box breathing, sleep relaxation, panic reset and grounding.
• Private journal, goals and mood tracking stored securely on your device.
• Articles and videos curated by experts.
• Help when you need it: 24/7 confidential helpline 1800 121 9497 and WhatsApp support.

For employees of partner organisations, sessions are covered by your company's wellness programme. Your employer
never sees your conversations, journal, assessment answers or which counsellor you booked.

CecureUs is not an emergency service. If you are in immediate danger, call 112.

**Category:** Health & Fitness (or Medical — confirm). **Screenshots:** capture from the preview build (Home,
Counsellors, Booking, Explore, Breathing, Profile) at 1080×1920+; feature graphic 1024×500.

## Data safety form (draft — confirm with backend behaviour)
| Data type | Collected | Shared | Purpose | Notes |
|-----------|-----------|--------|---------|-------|
| Name | Yes | No | Account management | |
| Email address | Yes | No | Account management, authentication | Verified by emailed code at sign-up |
| Phone number | Yes | No | Account management, authentication | Verified by SMS code at sign-up; sign-in ID |
| Health info (booking topic, notes, assessment results) | Yes | No | App functionality | Sent to CecureUs servers; counsellor sees booking notes |
| Journal, mood, goals | **Not collected** | — | — | Stored only on device |
| App interactions / diagnostics | No (Phase 1) | — | — | Update if analytics/crash reporting is added |

Data encrypted in transit: Yes (HTTPS). Users can request deletion: Yes — in-app (Profile → Delete Account) **and**
a public web URL (required; to be provided, B-13/B-15).

## Content rating
IARC questionnaire: no violence/sexual content/gambling; user-to-counsellor communication happens outside the app
(Zoom/phone) in Phase 1. Expect "Everyone"/"3+" with "Users interact" if chat is added.

## Reviewer access (App content → App access)
Sign-in is user ID + password, so reviewers need **no OTP**: create one real account on the production backend
(sign up once through the app), give its email and password in *App access*, and make sure at least one
counsellor has open slots. Without it, review will be rejected because login is required.

## Release steps (require explicit approval before each)
See **Step by step** at the top of this file. Nothing is built or uploaded without the CecureUs team's go-ahead.
