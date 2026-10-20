# Central welcome page, school registration and school lookup login

## Intent
The main (central) domain shows a welcome page with **Create account** and **Sign in**. "Create account" registers a **new school** (school + first admin). "Sign in" asks for the school code and sends the user to that school's own login page. School domains keep the current school landing page.

Decision (from the user): the account being created is a new school, not a parent/student account.

Out of scope: email verification, plans/payments, Super Admin UI, parent/student self sign-up.

## Frontend (`frontend/src`)
- `features/Central/` (new folder): `WelcomePage`, `RegisterSchoolPage`, `FindSchoolPage`, `api.js`, `host.js`.
- `host.js`: `isCentralHost(hostname)` is true when the host is in `VITE_CENTRAL_HOSTS` (default `localhost,127.0.0.1`); `schoolUrl(code, path)` builds `{protocol}//{code}.{VITE_BASE_DOMAIN}{:port}{path}` (default base domain `localhost`, keeps the current port in dev).
- Router: on a central host, `/` renders `WelcomePage`, plus `/register` and `/find-school`; on a school host the routes stay as today (`LandingPage`, `/login`, role areas).
- `WelcomePage`: hero, **Create account** (`/register`) and **Sign in** (`/find-school`), language switcher.
- `RegisterSchoolPage`: fields school name, school code, admin name, email, password, password confirmation. Shows per-field 422 errors; on success redirects to `schoolUrl(code, '/login')`.
- `FindSchoolPage`: one field (school code); redirects to `schoolUrl(code, '/login')`. Invalid code format shows an error without redirecting.
- All strings under `central.*` in `ar.json` / `en.json`; logical CSS properties only; mobile-first.

## Backend (`app/Central`)
- `POST /api/central/register` in `routes/central/api.php`: public, `throttle:5,60` per IP.
- `RegisterSchoolRequest`: `school_name` required max 255; `code` required, `^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$`, unique in `tenants.id` and in `domains.domain`, not in a reserved list (`www, admin, api, app, central, mail, root, support, static, assets, school, schools`); `admin_name` required; `admin_email` required email; `password` min 8, confirmed.
- `RegisterSchoolController` calls the existing `CreateSchool` action with domain `{code}.{config('tenancy.base_domain')}` (new env `TENANCY_BASE_DOMAIN`, default `localhost`); returns 201 `{data: {code, domain, login_path: '/login'}}`. Failures from `CreateSchool` return 500 with a translated generic message (the action already cleans up the tenant).
- `REGISTRATION_ENABLED` (env, default `true` locally): when false the endpoint returns 403 with a translated message.
- Messages translatable (`lang/{ar,en}`), `.env.example` documents the new variables.

## Organization
- README and `docs/project-structure.md` describe the central flow and the new env variables.
- One branch (`feature/central-welcome-register`), one PR.

## Tests
- Backend (Pest/PHPUnit, `CreateSchool` faked since tests run on a single DB): successful registration returns 201 and calls the action with the right domain; duplicate code 422; reserved code 422; bad code format 422; short or unconfirmed password 422; throttle after 5 requests returns 429; `REGISTRATION_ENABLED=false` returns 403; messages in Arabic.
- Frontend (Vitest): `isCentralHost`/`schoolUrl` (with and without port); central host renders `WelcomePage`, school host renders `LandingPage`; register form posts payload and redirects; 422 field errors; find-school validates and redirects; Arabic and English labels.

## Success criteria
- Opening `http://localhost:<port>` shows the welcome page; **Create account** creates a school and lands on `<code>.localhost:<port>/login`; **Sign in** with a school code lands on that school's login page.
- `php artisan test` and `npm test` pass; the PR is pushed to GitHub.

## Security notes
A public endpoint creates a database per call: it is throttled, can be switched off, and reserved codes are blocked. Before opening it to the internet, add email verification and a CAPTCHA (not in this slice).
