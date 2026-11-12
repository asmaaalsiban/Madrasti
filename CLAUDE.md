# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# Madrasati (مدرستي)

A multi-tenant, ClassDojo-style school platform: academic management, finance, and parent/teacher/student engagement. Sold to many schools; every school has its own database. Team of 3 developers.

`README.md` is the feature log: one section per feature (what it does, its rules, its settings, whether it needs a migration). Read the section of a feature before changing it, and add to it when a feature changes. `docs/project-structure.md` and `docs/database.md` are early outlines and partly out of date; trust the code.

## Commands

Backend (`backend/`, Laravel 12, PHP 8.2+, PHPUnit):

```
php artisan test                                      # whole suite (SQLite in memory, about a minute)
php artisan test tests/Feature/Grading                # one folder
php artisan test tests/Feature/Grading/AssessmentTest.php
php artisan test --filter=test_name_of_one_test
vendor/bin/pint                                       # format (default Laravel preset)
php artisan serve                                     # API on :8000
php artisan migrate --path=database/migrations/central   # central database
php artisan tenants:migrate                           # every school database
php artisan tenants:run <command>                     # run a command inside every school
```

Frontend (`frontend/`, React 19, Vite, Vitest):

```
npm run dev                                 # Vite; proxies /api to 127.0.0.1:8000
npm test                                    # vitest run (tests are in frontend/tests/)
npx vitest run tests/grading.test.jsx       # one file
npx vitest run tests/grading.test.jsx -t "part of a test name"
npm run lint                                # oxlint
npm run build
```

A local school (see "Local demo" in `README.md`):

```
php artisan school:create school2 "School 2" --admin-email=admin@school2.test --admin-password=Admin12345
php artisan school:seed-demo school2        # staff, students, classrooms (APP_ENV=local only)
php artisan tenants:list
```

Other tools: `a11y/axe-check.js` (accessibility check, instructions at its top), `loadtest/` (see its README), `php artisan backup:run|status|list|restore`.

## Checking screens (do this before calling a UI change done)

`tools/ui-check/` opens every screen of the app against a throw-away local demo school (SQLite in the temp folder, so no MySQL is needed; it creates the school, starts the API and Vite, signs in as an admin, a teacher, a student and a parent, and stops everything at the end). Works on Windows, macOS and Linux; needs `php` and `node`.

```
cd tools/ui-check && npm install && npx playwright install chromium    # once
node run.cjs sweep                                                       # every route, phone width, Arabic and English: sideways scroll, crashes, 5xx answers (exit 1 on any)
node run.cjs sweep --viewports=phone,tablet,laptop --lang=ar            # more widths
node run.cjs shots --pages=/admin/students,/teacher/attendance --viewports=phone,tablet   # full-page screenshots into tools/ui-check/out/
node run.cjs up                                                          # only start the demo (http://127.0.0.1:5199, school code school2) and wait
```

Demo logins (school code `school2`): `admin@school2.test`, `head1@school2.test` (teacher), `ahmad.d1001@school2.edu` (student), `parent1@school2.test` (parent), password `Test12345`. The sweep finds layout breakage, not ugliness: look at the screenshots for that. Its data is the demo data, not a real school.

When running the demo by hand instead: `php artisan serve` ignores variables you set (it re-reads `.env`), so start the API with `php -S 127.0.0.1:8000 index.php` from `backend/public`; set `BCRYPT_ROUNDS` (an empty value makes hashing fail) and `APP_MAINTENANCE_DRIVER=file`.

### Before saying a UI change is done

- See it on a phone (390px), a tablet (820px) and a computer (1280px), in Arabic and English. Use `shots` for the pages you changed and `sweep` for the rest.
- On a phone nothing scrolls sideways: tables become cards (see "Design system (Relief) and phone layout" below), timetables use day pills, long lists keep their Save button in reach.
- Run `npm test` and `npm run build` (and `php artisan test` for backend changes).
- Say what you could not check. Never claim a screen works that you did not look at.

## Architecture

### Tenancy: one URL, the school travels in a header

- `stancl/tenancy`, one database per school. The school is **not** resolved by domain: the frontend keeps the chosen school code in `localStorage` (`src/tenant/school.js`) and sends it in the `X-School` header on every request; the code is also the tenant id. `App\Providers\TenancyServiceProvider` sets that header on `InitializeTenancyByRequestData`, and `routes/tenant.php` wraps every school route with it. A missing or unknown code is a 404.
- Sanctum tokens live in each school's own database, so a token of one school is unknown to another (`tests/Feature/Tenancy/SchoolTokenIsolationTest.php`). The frontend stores one token per school (`token:<code>`).
- Central app (`app/Central`, `routes/central/api.php`, `database/migrations/central`): tenants, super admins, public school registration, the `school:*` commands. `App\Central\Actions\CreateSchool` is the one path that onboards a school (database, migrations, seeds, first admin, cleanup on failure).
- Tenant app: everything else. Migrations in `database/migrations/tenant`. Never query across school databases from tenant code. To list tables use `App\Shared\Support\CurrentDatabase`, not `Schema::getTables()` (on MySQL/PostgreSQL that lists every database on the server).
- Scheduled per-school work runs through `tenants:run` in `routes/console.php`.

### Backend layout

- `app/Modules/<Module>/` holds a module's models, controllers, requests, resources, policies, services and jobs. `app/Shared/` holds what several modules use (Auditable trait, Role enum, backup, import/export, payments, push, attachments).
- All school routes are in one file, `routes/tenant/api.php`. Every signed-in route goes through the `school-user` middleware group (`bootstrap/app.php`: Sanctum, active account, password already changed) plus `role:<roles>`. New routes must be added inside it.
- Roles are `App\Shared\Enums\Role`: `admin`, `teacher`, `student`, `parent` (the case is `Role::Guardian`, its value is `parent`). Staff kinds (accountant, head of department, nurse...) are a `staff_type` on a teacher-role user, not extra roles.
- Access to one student's data is decided by gates defined in `AppServiceProvider` (`view-student-grades`, `view-student-fees`, `view-student-attendance`...): admin, the student, their own parents, and for some a related teacher. Every `/students/{student}/...` endpoint must call one, with a test that another family's parent and a classmate get 403.
- Models of sensitive data use the `Auditable` trait, which writes the audit log on create/update/delete (grades, fees and payments, attendance, payroll, contracts, students, accounts).
- API Resources for responses, Form Requests for validation; API and validation messages are translatable (`lang/ar`, `lang/en`; the request's `Accept-Language` picks the language).

### Backend tests

- `Tests\TenantTestCase` migrates only the tenant schema into one in-memory SQLite database, with no tenancy bootstrapping. Module tests extend it (most through `Tests\Feature\Academic\AcademicTestCase`, which also switches the tenancy middleware off and has `actAsAdmin()` and friends).
- A test that needs real, separate schools uses `CreateSchool` with `DatabaseMigrations` and the central migrations (see `SchoolTokenIsolationTest`). A test keeps one application for all its requests, so reset the auth guards and end tenancy between requests there, or a second request reuses the first one's user.

### Frontend layout

- `src/api/client.js` is the single Axios client (adds the token, `Accept-Language`, `X-School`). Server state is TanStack Query.
- `src/app/router.jsx` has every route: the admin routes under `AdminLayout`, and the teacher, student and parent routes (`HOME_ROUTES`) under `DashboardLayout`. Guards are in `src/app/guards.jsx`. Pages live in `src/features/<Module>/`.
- Translations are two files, `src/i18n/ar.json` and `src/i18n/en.json`, keys grouped by module. Arabic has plural forms (`_zero`, `_two`, `_few`, `_many`) that English does not.
- One build serves every school: the name, logo and colour come from `GET /api/school` (`src/tenant/`), and `--color-brand` is set at runtime. `src/tenant/color.js` darkens a school's colour until it reaches 4.5:1 contrast.

### Design system ("Relief") and phone layout

- Tokens are in `src/index.css` (`@theme`). That file also **restyles utilities globally**: `.rounded*`, `.shadow*` and `button.rounded` / `input.rounded` get the theme's radii, flat shadow and pill shape with no breakpoint. So `md:rounded-none` or `md:shadow-none` does not undo them; when a look must apply only on phones, write it with `max-md:` classes instead.
- There is no Shadcn UI in the code (`src/components/ui` is empty); shared parts are in `src/components/shared` (`DataTable`, `Modal`, `UserMenu`, `BottomTabBar`...). Icons are `lucide-react`, with `aria-hidden` next to a text label.
- Phones (below `md`, 768px): no page scrolls sideways.
  - `ResponsiveTables` (mounted in both layouts) turns every table of 3+ columns into cards from its own header cells (`labelTables.js`, CSS under `table[data-stack]`). A real grid opts out with `data-grid`; `data-scroll` keeps a sideways scroll.
  - Timetables use `PhoneTimetable` (day pills, one card per period); `TimetableGrid` renders it below `md` and the grid above.
  - Pages with a row per person and a Save button (attendance, score sheet) keep one set of elements, laid out as cards below `md`, with the summary and Save fixed above the bottom tab bar.
  - `BottomTabBar` shows on phones and tablets; `Modal` is a bottom sheet on phones.
- Because some pages render both a phone view and a table (CSS shows one), frontend tests look rows up inside `screen.getByRole('table')` or the phone view's `data-testid`, not on the whole screen.
- `CountUp` numbers animate for 0.9 s: wait for them with a longer `waitFor` timeout.

## Conventions

- Code, comments, commit messages and identifiers in English. The app is Arabic (default) and English.
- Every feature ships with tests (PHPUnit feature tests, Vitest).
- No hardcoded strings in components; add each key to both `ar.json` and `en.json`.
- RTL: CSS logical properties only (`ms-4`, `pe-2`, `start-0`, `text-start`), never `left`/`right`. The exception is content that is always left-to-right (numbers, money, dates, passwords): those get `dir="ltr"`, as `Money` and `PasswordInput` do. `dir` and `lang` on `<html>` follow the language. Check every screen in both directions.
- Functional components and hooks only. Parent and student screens are mobile-first.
- A parent can only reach their own children; a teacher only what they teach or manage.
- Heavy or async work (notifications, reports, scheduling) goes through queues.
- Changes go through a branch and a pull request, not a direct push to `main`.

## Lessons learned (do not repeat)

When a mistake could happen again, add one line here: the rule and why. Remove a line once code or a test makes the mistake impossible.

- `md:rounded-none`, `md:shadow-none` and the like do nothing in this project: `index.css` sets `.rounded*` / `.shadow*` with no breakpoint. Use `max-md:` classes for a phone-only look.
- `php artisan serve` ignores the variables you set (it re-reads `.env`). Start the demo API with `php -S 127.0.0.1:8000 index.php` from `backend/public`, or use `tools/ui-check`.
- A Playwright route `**/api/**` also catches Vite's own `src/api/client.js` and the page renders blank. Use the full origin: `http://127.0.0.1:5199/api/**`.
- Do not review screens with hand-made fake API data: pages crash on the wrong shape and the result means nothing. Use the demo school (`tools/ui-check`).
- `PasswordInput` keeps its eye button on the physical right (`right-0`, `pr-10`) on purpose: the field is `dir="ltr"`. Do not "fix" it to `end-0`.
- A merged pull request is finished. New work goes on a new branch from `main`, never as more commits on the old branch (that is how a change ended up not in `main`).
