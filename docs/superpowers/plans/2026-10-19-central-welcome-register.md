# Central Welcome, School Registration and School Lookup Login Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The central domain shows a welcome page with Create account (register a new school) and Sign in (by school code); school domains keep today's behavior.

**Architecture:** A public throttled `POST /api/central/register` reuses `CreateSchool`. The frontend decides central vs school from `window.location.hostname` (`host.js`), and the router serves central pages on central hosts only.

**Tech Stack:** Laravel 12 (PHPUnit, `Tests\TenantTestCase`), React 19, react-router-dom 7, react-i18next, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-19-central-welcome-register-design.md`

## Global Constraints

- Code/comments/commits in English; no hardcoded UI strings (`central.*` keys in `src/i18n/{ar,en}.json`); logical CSS properties only; mobile-first.
- API Resources/Form Requests; messages translatable (`lang/{ar,en}`).
- School code regex: `^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$`; reserved: `www, admin, api, app, central, mail, root, support, static, assets, school, schools`.
- Throttle `5` per `60` minutes per IP; `REGISTRATION_ENABLED` env toggles the endpoint; domain is `{code}.{config('tenancy.base_domain')}` (env `TENANCY_BASE_DOMAIN`, default `localhost`).
- Work on `feature/central-welcome-register`; open a PR at the end; commits end with the session attribution lines.

## Review Focus

- Code with uppercase/underscore/leading hyphen/too short/too long: 422 (Task 1 test + Task 2 `isValidSchoolCode`).
- A code equal to an existing tenant id OR existing domain: 422, no second tenant created (Task 1).
- Password confirmation mismatch and short password: 422 (Task 1).
- Registration when `CreateSchool` throws: translated 500, no leaked exception text (Task 1).
- Central host with port in dev: redirect keeps the port; school host without port: no stray colon (Task 2).
- Whitespace/uppercase typed in "Sign in" code field is normalized before redirect (Task 3).

---

### Task 1: Register endpoint

**Files:**
- Create: `backend/app/Central/Http/Requests/RegisterSchoolRequest.php`, `backend/app/Central/Http/Controllers/RegisterSchoolController.php`, `backend/lang/en/registration.php`, `backend/lang/ar/registration.php`
- Modify: `backend/routes/central/api.php`, `backend/config/tenancy.php` (add `'base_domain' => env('TENANCY_BASE_DOMAIN', 'localhost')`, `'registration_enabled' => env('REGISTRATION_ENABLED', true)`), `backend/.env.example`, `backend/app/Providers/AppServiceProvider.php` (add `RateLimiter::for('register', ...)` 5 per 60 minutes by IP)
- Test: `backend/tests/Feature/Central/RegisterSchoolTest.php`

**Interfaces:**
- Consumes: `App\Central\Actions\CreateSchool::handle(array{id,name,domain,admin_name,admin_email,admin_password,...}): Tenant`.
- Produces: `POST /api/central/register` body `{school_name, code, admin_name, admin_email, password, password_confirmation}` → 201 `{data:{code, domain, login_path:'/login'}}`; 422 field errors; 403 when disabled; 429 when throttled; 500 `{message}` translated on failure. Tests bind a fake `CreateSchool` in the container.

- [ ] **Step 1: Write failing tests** (class `RegisterSchoolTest extends TenantTestCase`; central tables created in `setUp` with `Schema::create('tenants', id string primary, data json)` and `domains(id, domain unique, tenant_id)`; fake `CreateSchool` bound via `$this->app->instance(CreateSchool::class, $fake)` recording its input and creating rows in those tables): `test_registers_a_school` (201, fake got `id=alnoor`, `domain=alnoor.localhost`, admin email/password, JSON `data.code=alnoor`, `login_path=/login`); `test_rejects_bad_codes` data provider (`Alnoor`, `al_noor`, `-ab`, `ab`, 31 chars, `www`, `admin`) → 422 on `code`; `test_rejects_taken_code_and_taken_domain` (existing tenant id; existing domain row) → 422 on `code`, fake not called; `test_password_rules` (short, unconfirmed) → 422 on `password`; `test_disabled_returns_403` (`config(['tenancy.registration_enabled'=>false])`); `test_failure_returns_translated_500` (fake throws `RuntimeException('SQLSTATE secret')`; response `message` equals `__('registration.failed')` and does not contain `SQLSTATE`); `test_validation_messages_are_arabic` (`Accept-Language: ar`); `test_throttles_after_five_requests` (6th → 429).
- [ ] **Step 2: Run** `php artisan test --filter=RegisterSchoolTest` → FAIL (404).
- [ ] **Step 3: Implement** the request (rules per Global Constraints, `Rule::notIn` reserved list, unique on `tenants,id` and `domains,domain` via a closure rule building `{code}.{base_domain}`), controller (`__invoke(RegisterSchoolRequest, CreateSchool)`: 403 if disabled; call action; catch `Throwable` → `report($e)` and 500 with `registration.failed`), route `Route::post('register', RegisterSchoolController::class)->middleware('throttle:register')` outside the auth groups, limiter, config, env example (`TENANCY_BASE_DOMAIN=localhost`, `REGISTRATION_ENABLED=true`), lang files (`failed`, `disabled`).
- [ ] **Step 4: Run** `php artisan test` → all PASS; `vendor/bin/pint --test` on touched files.
- [ ] **Step 5: Commit** `feat(backend): public school registration endpoint`.

### Task 2: Host helpers and API client

**Files:**
- Create: `frontend/src/features/Central/host.js`, `frontend/src/features/Central/api.js`
- Test: `frontend/tests/centralHost.test.js`

**Interfaces:**
- Produces: `isCentralHost(hostname: string): boolean` (true when in `import.meta.env.VITE_CENTRAL_HOSTS` split by comma, default `localhost,127.0.0.1`); `isValidSchoolCode(code: string): boolean` (regex from Global Constraints); `normalizeSchoolCode(input: string): string` (trim + lowercase); `schoolUrl(code: string, path = '/', location = window.location): string` → `${protocol}//${code}.${VITE_BASE_DOMAIN||'localhost'}${port ? ':'+port : ''}${path}`; `registerSchool(payload): Promise<{code, domain, login_path}>` posting to `/central/register` through `src/api/client.js` (the central API lives on the same host, prefix `/api/central`; the client baseURL already ends with `/api`).

- [ ] **Step 1: Write failing tests:** `isCentralHost('localhost')`/`('127.0.0.1')` true, `('school2.localhost')` false; `schoolUrl('alnoor','/login',{protocol:'http:',port:'5173'})==='http://alnoor.localhost:5173/login'` and without port `'http://alnoor.localhost/login'`; `isValidSchoolCode` true for `alnoor`, `al-noor2`; false for `Alnoor`, `al_noor`, `-ab`, `ab`, 31 chars; `normalizeSchoolCode('  AlNoor ')==='alnoor'`; `registerSchool` posts to `/central/register` with the payload (mock `api`).
- [ ] **Step 2: Run** `npx vitest run tests/centralHost.test.js` → FAIL.
- [ ] **Step 3: Implement** the two modules per the signatures.
- [ ] **Step 4: Run** → PASS. **Step 5: Commit** `feat(frontend): central host helpers and register API client`.

### Task 3: Central pages and routing

**Files:**
- Create: `frontend/src/features/Central/WelcomePage.jsx`, `RegisterSchoolPage.jsx`, `FindSchoolPage.jsx`
- Modify: `frontend/src/app/router.jsx` (when `isCentralHost(window.location.hostname)` the route table is `[ '/', '/register', '/find-school' ]` with the three pages plus a `*` redirect to `/`; otherwise the existing table), `frontend/src/i18n/{ar,en}.json` (`central.*`)
- Test: `frontend/tests/centralPages.test.jsx`

**Interfaces:**
- Consumes: Task 2 exports; `LanguageSwitcher`.
- Produces: `WelcomePage` (links `/register`, `/find-school`); `RegisterSchoolPage` (labels `central.schoolName`, `central.schoolCode`, `central.adminName`, `central.email`, `central.password`, `central.passwordConfirmation`; on success sets `window.location.assign(schoolUrl(code,'/login'))`; maps 422 `errors` to fields; non-422 shows `central.genericError`); `FindSchoolPage` (field `central.schoolCode`; invalid code shows `central.invalidCode` and does not redirect; valid redirects with `schoolUrl(normalizeSchoolCode(v),'/login')`). `createRouter` exported as today.

- [ ] **Step 1: Write failing tests** (mock `./api` `registerSchool` and `window.location.assign`): welcome shows both links in Arabic and English; register posts the exact payload and redirects to `http://alnoor.localhost:5173/login` (jsdom location mocked); 422 shows the field error under `code`; 500 shows the generic error and no redirect; find-school redirects for `' AlNoor '` to `…alnoor.localhost…/login`, shows `central.invalidCode` for `al_noor` without redirect; router test: with `window.location.hostname='localhost'` `routes` renders Welcome at `/`, with `'school2.localhost'` renders the school landing.
- [ ] **Step 2: Run** `npx vitest run tests/centralPages.test.jsx` → FAIL.
- [ ] **Step 3: Implement** pages (Tailwind logical classes, mobile-first, same visual style as `LandingPage`), translations in both languages, router switch (evaluate host once at module load via a `central` const).
- [ ] **Step 4: Run** `npm test`, `npx oxlint`, `npx vite build` → pass.
- [ ] **Step 5: Commit** `feat(frontend): central welcome, register and find-school pages`.

### Task 4: Docs, final review and PR

- [ ] **Step 1:** Update `README.md` (central flow, `TENANCY_BASE_DOMAIN`, `REGISTRATION_ENABLED`, `VITE_CENTRAL_HOSTS`, `VITE_BASE_DOMAIN`) and `docs/project-structure.md` (`features/Central`).
- [ ] **Step 2:** Run `php artisan test`, `npm test`, `npx vite build`; screenshot welcome/register/find-school (AR desktop, EN mobile) against a mocked API.
- [ ] **Step 3:** Fresh-context whole-branch review; fix Critical/Important findings test-first.
- [ ] **Step 4:** `git push -u origin feature/central-welcome-register`, open PR (no template exists), report to the user.
