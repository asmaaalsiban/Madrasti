# CLAUDE-DENSE.md — everything learned building Madrasati (compressed handoff for new sessions)

Snapshot of a long session (2026-11). Facts verified in code/tests unless marked UNMERGED/UNVERIFIED. Read `CLAUDE.md` + `README.md` (feature log) too; code wins over docs. Repo `asmaaalsiban/Madrasti`; user machine Windows `D:\مشروعي\Madrasti`, MySQL on port 3307.

## Working with the user
- Reply Arabic, simple, short; code/terms English. User gets lost in long explanations: numbered steps, exact terminal commands (PowerShell). Ponytail "lite" mode was active: do what's asked, name the smaller option in one line, end with what was skipped/not checked.
- Never ask for/paste secrets (Gmail app password, keys). No PR unless asked (user often says "افتح الـ PR"). Never push to `main`; branch+PR; user merges. A stop hook demands uncommitted/untracked work be committed+pushed.
- Never push to a branch whose PR was merged (lost work once): fetch, branch from `origin/main`, new PR.
- Say plainly what was NOT checked; sandbox measurements ≠ user's machine. Verify UI in real Chromium (Playwright pre-installed, `/opt/pw-browsers/chromium`), mock API with `page.route(/localhost:8000\/api\//)` (full origin; `**/api/**` also catches Vite's `src/api/*`). fullPage screenshots mis-place `position:fixed`.
- Pint reformats unrelated lines: run it on new files only. Measure before claiming perf.

## Stack / run
Laravel 12 PHP≥8.2 (stancl/tenancy, Sanctum, PHPUnit), React 19 + Vite + TanStack Query + Tailwind 4 + i18next (ar default/en, RTL), Vitest, oxlint.
`cd backend`: `php artisan serve` · `php artisan test [path|--filter=x]` · `php artisan migrate --path=database/migrations/central` · `php artisan tenants:migrate` · `php artisan super-admin:create <email>` (pw ≥14) · `php artisan queue:work --timeout=3600`. `cd frontend`: `npm run dev` (proxy /api→127.0.0.1:8000) · `npm test` · `npx vitest run tests/x.test.jsx` · `npm run lint` · `npm run build`.
Local school: `php artisan school:create school2 "School 2" --admin-email=… --admin-password=…`, `school:seed-demo school2`. Demo logins (PR #213): admin@school2.test, password Test12345.

## Architecture
- One URL, school travels in `X-School` header (localStorage `school`; token per school `token:<code>`); not by domain. DB per school (`madrasati_<code>`), central DB `madrasati_central`. Sanctum tokens live in each school DB. Never query across school DBs from tenant code.
- All school routes in `routes/tenant/api.php`; signed-in ones inside group `school-user` (Sanctum, active, password changed) + `role:`. Roles: admin, teacher, student, parent (`Role::Guardian`). Per-student endpoints need a gate + test that other family/classmate gets 403.
- `Auditable` trait writes audit log. Resources/FormRequests; messages in `lang/{ar,en}`; frontend keys in BOTH `src/i18n/ar.json`,`en.json` (ar has extra plural forms).
- **Super Admin** (merged #215): `/super-admin`, API `/api/central`, own client `saApi`, token `sa-token`. Login = password(≥14) + emailed 6-digit code (`AdminLoginCode`, 10 min, 5 tries, single use), token 8 h. `CentralAudit` logs actions. Schools list/create/edit/extend/suspend/reactivate; `SchoolStatus` derives state (active/expired/suspended/exit window/closed) from tenant row; `EnsureSchoolActive` 403 `school_suspended` on every request, frontend interceptor signs out → `/suspended`. `closed_at` is never set by any code (only cleared).
- **Exit window**: `ExitWindow::open` → queued `PrepareExit` (archive via BackupService + handover package `app/Shared/Handover` xlsx+uploads) under `archive/<code>/`,`handover/<code>/`; window opens only when both done; only school admins sign in/download (`/download-your-data`, bare page, interceptor exception). Needs a queue worker; `retry_after` raised 90→3900 in `config/queue.php` for ALL queues (decision; alternative = dedicated connection). **No code deletes school data.**
- **Student limit** (merged #216): `tenants.max_students`; `StudentLimit` live `Student::count()` under per-school lock; create + import (all-or-nothing) → 422 `student_limit_reached`; friendly yellow notice in form; admins get bell notice at 90% and 100%. Deleted student frees a place.
- Auditable actor fix (merged #217): Super Admin id must never be written into a school table (FK `audit_logs.user_id`); `Auditable` writes `user_id` only for school `User`. SQLite tests don't enforce FKs → MySQL-only failures.
- Lenis smooth scroll only on public landing (`useSmoothScroll`, #220).

## Backend tests
`TenantTestCase` = in-memory SQLite, tenant migrations only; `AcademicTestCase` also `withoutMiddleware(InitializeTenancyByRequestData)` + `actAsAdmin()`. Real separate schools: `CreateSchool` + `DatabaseMigrations` + `--path=database/migrations/central` (see SchoolTokenIsolationTest); `app('auth')->forgetGuards()` + end tenancy between requests. Don't mix `RefreshDatabase` TestCase with tenant tests (clobbers shared SQLite). No Student/Term factories: use `Model::create`.

## Frontend rules
Logical CSS only (`ms-`,`pe-`,`text-start`), `dir="ltr"` for numbers/emails/passwords. `src/index.css` restyles `.rounded*`/`.shadow*` globally → `md:rounded-none` does nothing; use `max-md:`. Phones <768px: no sideways scroll; `ResponsiveTables` turns 3+-col tables into cards (`data-grid`/`data-scroll` opt out); `Modal` = bottom sheet on phone; `BottomTabBar`. Tests look inside `getByRole('table')`/testid; `CountUp` 0.9 s → longer `waitFor`. No Shadcn in code. `PasswordInput` `right-0` is intentional.

## Dev-machine (Windows) gotchas
- Slow API (280 ms for `/api/school`) → enabling **opcache** (`opcache.enable=1`, `enable_cli=1`, `zend_extension=opcache` in php.ini) gave 89 ms. Also try Defender exclusion, `$env:PHP_CLI_SERVER_WORKERS=4`. `php artisan serve` is single-threaded.
- Local mail: `MAIL_MAILER=log` writes codes to `storage/logs` (quoted-printable); real mail = Gmail SMTP smtp.gmail.com:587, app password (needs 2-step), `MAIL_SCHEME=` empty. SMTP hung on a phone hotspot (worked on Wi-Fi). Duplicate `MAIL_MAILER` lines in `.env` → first wins; `php artisan config:clear` after edits.
- Vite dev re-optimizes deps on first load after `npm install` (reload). `VITE_API_URL` in `.env.local` pointing to Render caused CORS/404 once; keep it unset locally.
- Wrong `super-admin:create` example email (`your@email.com`) was typed literally: login with the exact email created.
- Hosting on Render is paused by the user.

## Bugs found & fixed (patterns to remember)
- Soft-deleted student leaves dangling `student_renewals` row → `$r->student` null → 500, page stuck "loading" (UNMERGED fix #223: `whereHas('student')`).
- Admin dashboard loaded all fees/payments/installments/scores into PHP; now SQL sum/avg (36k scores 522→12 ms) (#223).
- Entrance animations (blur, stacked Reveal+PageEnter) and an endless blurred aurora made dashboard slow (21→61 fps); service worker `controllerchange` reloaded the page on first visit (requests sent twice) (#224).
- Modal drawn inside page got clipped to a parent box → now `createPortal` to body; student form 3 columns, fits 1280×720 (#218).
- Owner's counts cached 5 min → invalidate on Student/User events (`SchoolCounts`); counts now `Student::count()` (#219).

## Security (review of 27 items; UNMERGED #222, see docs/security.md there)
Added: `SecurityHeaders` (API), `RequireHttps` (prod default, loop-proof via X-Forwarded-Proto), `config/cors.php` (`CORS_ALLOWED_ORIGINS`, none in prod), `school-api` throttle 300/min per person, `LoginMonitor` (failed logins logged, alert at 10/15 min), `ProductionGuard` (debug forced off in prod), composer/npm advisories fixed (katex 0.19.1), school tokens expire 30 d (`SANCTUM_EXPIRATION` minutes), unused `laravel/sail`,`@types/react*` removed. Already fine: bcrypt hashed casts, reset code 15 min/5 tries/single use, uploads validated+private disk, payment webhook HMAC `hash_equals`, all raw SQL bound, KaTeX `trust:false` (only `dangerouslySetInnerHTML`), no secrets in git history (pattern search, run gitleaks before launch). Open decisions: token in localStorage vs httpOnly cookie (kept), SPA CSP at web server (nginx snippet in docs/security.md), weak example `DEFAULT_ACCOUNT_PASSWORD`, specialist review of payments/children's data. Go-live: `APP_ENV=production APP_DEBUG=false`, `TRUSTED_PROXIES`, `CORS_ALLOWED_ORIGINS`, real mail, queue worker, `FORCE_HTTPS`.

## PR state (changes fast; verify with GitHub)
Merged: #215 Super Admin, #216 limit UX, #217 audit actor, #220 Lenis, #226 (other session). Open: #212 CLAUDE.md refresh (+Super Admin section) → #214 lessons → #213 ui-check tool; #218 student form; #219 counts sync; #222 security+30d tokens; #223 renewals+dashboard SQL; #224 dashboard motion + no first-visit reload; #227 CI (GitHub Actions: backend phpunit, frontend lint/test/build); #228 Claude hooks (.env guard, no push to main, ar/en key match; `.claude/settings.json`+`hooks/` now tracked). Old: #4 #35 #69 #183 #196 (status unknown). Not done: final cross-task review of Super Admin (agent started, result pending), SPA CSP, token pruning, gitleaks, `/install-github-app`.

## Process notes
Subagent-driven build used: task brief → implementer → scoped reviewer → fix loop ≤5 rounds → final review; ledger in git-ignored `.superpowers/sdd/…`. Reviewers repeatedly caught: non-atomic job guards (unique lock leaks, stale `running` steps), import atomicity under limit, empty-password rule bypass (non-implicit validation rule skips `''`). Fable hit a 5-hour rate limit once; use sonnet/opus for reviewers.
