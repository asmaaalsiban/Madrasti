# Super Admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A private Super Admin area where the owner adds schools, sets their end date and student limit, blocks a school at once (signed-in people included), and lets a leaving school download all its data during a time-limited exit window while the owner keeps a full archive.

**Architecture:** A school's state is derived from columns on `tenants` by one class (`SchoolStatus`). One middleware checks it on every tenant request. The Super Admin area is a new set of central API endpoints (email code as the second sign-in step) and a new frontend area at `/super-admin`. The exit window builds, in queued jobs, the existing backup (the owner's archive) and a new readable handover package (Excel files plus uploads), which the school's administrators download inside the app.

**Tech Stack:** Laravel 12 (`stancl/tenancy`, Sanctum, queues, mail), PHPUnit; React 19, Vite, TanStack Query, Vitest, `openspout` (existing `XlsxExport`).

**Spec:** `docs/superpowers/specs/2026-11-12-super-admin-design.md` (read it first; `CLAUDE.md` for the project rules and `tools/ui-check` for checking screens).

## Global Constraints

- Backend tests: `php artisan test`. Tests with real separate schools use `DatabaseMigrations` with `--path=database/migrations/central`, `CreateSchool`, and reset the auth guards (`app('auth')->forgetGuards()`) and tenancy between requests, as `tests/Feature/Tenancy/SchoolTokenIsolationTest.php` does.
- Blocked answer: HTTP 403 `{ "code": "school_suspended", "message": <translated> }`. Student limit answer: HTTP 422 `{ "code": "student_limit_reached", ... }`.
- A subscription end date counts through its whole day in `config('app.timezone')`; `subscription_ends_at = null` means no end date.
- Super Admin: password at least **14** characters; the code is **6 digits**, valid **10 minutes**, **5** wrong tries, used once; the central token expires after **8 hours**.
- Exit window default **14** days, editable when opening, extendable; only school **administrators** download; the window starts only when both the archive and the package are ready.
- Archive stored on the backup disk under `archive/<code>/`, package under `handover/<code>/` (outside the school's own folders). **No code anywhere deletes school data.**
- Every user-facing text in Arabic and English (`lang/ar`, `lang/en`; `frontend/src/i18n/ar.json`, `en.json`, both); every new screen works on a phone (390px, no sideways scroll; see `CLAUDE.md`) and in both directions.
- Heavy work (archive, package) in queued jobs. Never query across school databases from tenant code; the owner's list reads a school through `$tenant->run(...)`.

## Review Focus

Each line has its test in the task named.

1. A person already signed in when the school is suspended gets 403 on their next request, with the same token that worked a moment before (Task 2).
2. The end date boundary: a school ending today still works at 23:59 and is blocked after midnight (Task 1, Task 2).
3. Sign-in code abuse: wrong, expired, reused and guessed codes; the same answer for an unknown email, with no mail sent (Task 5).
4. Student limit with an Excel file that would pass it, and two students created at limit minus one (Task 7).
5. A school with no students and no accounting data still produces a valid package; and a failed archive or package step never opens the window (Task 9, Task 10).

---

### Task 1: School state on `tenants` and `SchoolStatus`

**Files:**
- Create: `backend/database/migrations/central/2026_11_12_000001_add_school_state_to_tenants.php`, `backend/app/Central/Support/SchoolState.php`, `backend/app/Central/Support/SchoolStatus.php`
- Modify: `backend/app/Central/Models/Tenant.php` (custom columns, casts)
- Test: `backend/tests/Unit/Central/SchoolStatusTest.php`

**Interfaces:**
- Produces: columns `tenants.max_students` (unsigned int, null), `tenants.exit_window_ends_at` (timestamp, null), `tenants.closed_at` (timestamp, null); tables `central_audit_logs` (`id`, `super_admin_id` null, `action` string, `tenant_id` string null, `meta` json null, `created_at`) and `super_admin_login_codes` (`id`, `super_admin_id`, `code_hash`, `attempts` tinyint default 0, `expires_at`, `used_at` null, `created_at`); `enum SchoolState: string { Active='active'; Expired='expired'; Suspended='suspended'; ExitWindow='exit_window'; Closed='closed' }`; `SchoolStatus::of(Tenant $school, ?CarbonInterface $now = null): SchoolState`; `SchoolStatus::blocked(Tenant $school, ?CarbonInterface $now = null): bool` (true for every state except Active); `SchoolStatus::currentSchoolBlocked(): bool` (false when no tenant is initialised).
- Priority in `of`: exit window in the future, then `ExitWindow`; else `closed_at` set or an exit window date in the past, then `Closed`; else `is_active` false, then `Suspended`; else end date past (end of its day), then `Expired`; else `Active`.

- [ ] **Step 1: Write the failing test** `SchoolStatusTest` (plain `Tenant` objects, `Carbon::setTestNow`): `test_active_without_an_end_date`; `test_a_school_ending_today_is_active_until_the_end_of_the_day_and_expired_after` (23:59:59 active, next day 00:00:00 expired); `test_inactive_is_suspended`; `test_an_exit_window_in_the_future_wins_over_suspended_and_expired`; `test_a_past_exit_window_or_closed_at_is_closed`; `test_blocked_is_true_for_all_but_active`.
- [ ] **Step 2: Run** `php artisan test tests/Unit/Central/SchoolStatusTest.php` - expect FAIL (class missing).
- [ ] **Step 3: Implement** the migration, the enum, `SchoolStatus`, and the `Tenant` additions (`max_students`, `exit_window_ends_at`, `closed_at` in `getCustomColumns`; datetime casts).
- [ ] **Step 4: Run** the test - expect PASS; run `php artisan migrate --path=database/migrations/central` on a scratch SQLite database to check the migration.
- [ ] **Step 5: Commit** `feat: school state columns and SchoolStatus`.

### Task 2: `EnsureSchoolActive` middleware and the public school answer

**Files:**
- Create: `backend/app/Central/Http/Middleware/EnsureSchoolActive.php`, `backend/tests/Feature/Tenancy/SchoolBlockedTest.php`
- Modify: `backend/routes/tenant.php` (add the middleware after `InitializeTenancyByRequestData`), `backend/app/Modules/Academic/Http/Controllers/SchoolBrandingController.php` (answer), `backend/lang/ar.json`, `backend/lang/en.json`

**Interfaces:**
- Consumes: `SchoolStatus::of`, `SchoolState`.
- Produces: `EnsureSchoolActive::handle(Request $request, Closure $next): Response`. Active: pass. ExitWindow: pass only for the allowlist `GET api/school`, `POST api/auth/login`, `GET api/auth/me`, `POST api/auth/logout`, and any path starting `api/exit-export`. Every other state: pass only `GET api/school`. Anything else: 403 `school_suspended`. `GET /api/school` gains `suspended: bool` (state is not Active) and `exit_window_ends_at: ?string` (ISO 8601, only in an exit window).

- [ ] **Step 1: Write the failing tests** with two real schools (`schoola`, `schoolb`, copy the helpers of `SchoolTokenIsolationTest`): `test_a_suspended_school_blocks_a_token_that_worked_a_moment_ago` (sign in, `me` is 200, set `is_active=false` on the tenant, `me` is 403 with `code` `school_suspended`, the other school still 200); `test_reactivating_lets_the_same_token_work_again`; `test_an_end_date_of_today_still_works_and_yesterday_blocks`; `test_the_school_answer_still_works_and_says_it_is_suspended`; `test_in_an_exit_window_me_works_but_the_student_list_is_blocked`; `test_a_closed_school_blocks_everything_but_the_school_answer`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Tenancy/SchoolBlockedTest.php` - expect FAIL.
- [ ] **Step 3: Implement** the middleware (reads `tenant()`), wire it in `routes/tenant.php`, extend the branding answer, add the translated message `auth.school_suspended` in both languages.
- [ ] **Step 4: Run** the new test and `php artisan test` (the whole suite: existing tests use no tenancy middleware or active schools; fix any that depended on the old behaviour) - expect PASS.
- [ ] **Step 5: Commit** `feat: block a suspended, expired or closed school on every request`.

### Task 3: Frontend: sign out of a blocked school

**Files:**
- Create: `frontend/src/features/Auth/SuspendedPage.jsx`, `frontend/tests/suspended.test.jsx`
- Modify: `frontend/src/api/client.js` (response interceptor), `frontend/src/app/router.jsx` (route `/suspended`), `frontend/src/i18n/ar.json`, `en.json`

**Interfaces:**
- Consumes: the 403 `school_suspended` answer and `GET /api/school` `suspended` / `exit_window_ends_at` (Task 2).
- Produces: on a 403 with `code === 'school_suspended'` the client clears the school's token (`setToken(null)`), dispatches `auth:expired` and navigates (full page load, `goTo('/suspended')`); `SuspendedPage` (default export) shows the school name, the translated "subscription ended, contact the platform owner" text and a link to sign in again.

- [ ] **Step 1: Write the failing tests** (`suspended.test.jsx`): `interceptor clears the token and goes to /suspended on a 403 school_suspended` (mock adapter or call the interceptor handler), `a 403 without that code leaves the token alone`, `SuspendedPage shows the school name in Arabic and in English`.
- [ ] **Step 2: Run** `npx vitest run tests/suspended.test.jsx` - expect FAIL.
- [ ] **Step 3: Implement** the interceptor branch, the page and the route (outside the signed-in guards), the strings.
- [ ] **Step 4: Run** the test and `npm test`, `npm run build` - expect PASS.
- [ ] **Step 5: Commit** `feat: sign out and explain when the school is blocked`.

### Task 4: Reminders and notification jobs skip a blocked school

**Files:**
- Modify: `backend/app/Console/Commands/{FinanceRemind,ContractsRemind,DutiesRemind,TasksRemind,WarningsEvaluate}.php`, `backend/app/Modules/Attendance/Jobs/SendAbsenceNotice.php` (and any other class implementing `ShouldQueue` that notifies people)
- Test: `backend/tests/Feature/Console/BlockedSchoolSkipsTest.php`

**Interfaces:**
- Consumes: `SchoolStatus::currentSchoolBlocked(): bool`.

- [ ] **Step 1: Write the failing tests**: `test_finance_remind_sends_nothing_for_a_blocked_school` and the same for each command, `test_the_absence_notice_job_does_nothing_for_a_blocked_school`, `test_an_active_school_still_gets_its_reminders` (guard).
- [ ] **Step 2: Run** - expect FAIL.
- [ ] **Step 3: Implement** an early `return self::SUCCESS` (commands) / early return (jobs) when `SchoolStatus::currentSchoolBlocked()`. Backups are untouched (`BackupRun`).
- [ ] **Step 4: Run** the test and the suites of the touched commands - expect PASS.
- [ ] **Step 5: Commit** `feat: reminders and notices skip a blocked school`.

### Task 5: Super Admin sign-in with an email code

**Files:**
- Create: `backend/app/Central/Services/AdminLoginCode.php`, `backend/app/Mail/SuperAdminLoginCodeMail.php`, `backend/resources/views/mail/super-admin-login-code.blade.php` (or the form the existing `PasswordResetCodeMail` uses), `backend/app/Central/Rules/LongPassword.php`, `backend/tests/Feature/Central/SuperAdminLoginTest.php`
- Modify: `backend/app/Central/Http/Controllers/CentralAuthController.php`, `backend/routes/central/api.php`, `backend/app/Central/Console/CreateSuperAdminCommand.php` (14 characters), `backend/app/Providers/AppServiceProvider.php` (rate limiter `central-code`), `backend/lang/*`

**Interfaces:**
- Consumes: `super_admin_login_codes` (Task 1), `App\Central\Models\SuperAdmin`.
- Produces: `AdminLoginCode::issue(SuperAdmin $admin): void` (stores a hashed 6-digit code, mails it, invalidates older ones); `AdminLoginCode::verify(SuperAdmin $admin, string $code): bool` (counts a try, false after 5 tries, past 10 minutes or once used). Endpoints: `POST /api/central/auth/login {email, password}` answers `{ "challenge": true }` in every case that does not throw a validation error for a wrong password (an unknown email gets the same answer and no mail); `POST /api/central/auth/verify {email, code}` answers `{ token, user }` with a token that expires 8 hours later (`createToken('central', ['*'], now()->addHours(8))`) or 422. `LongPassword` rule: min 14 characters.

- [ ] **Step 1: Write the failing tests** (`Mail::fake()`): `test_a_right_password_sends_a_six_digit_code_and_returns_a_challenge`; `test_the_right_code_returns_a_token_that_expires_in_eight_hours`; `test_a_wrong_password_sends_no_code`; `test_an_unknown_email_gets_the_same_challenge_and_no_mail`; `test_a_wrong_code_counts_and_the_fifth_wrong_try_locks_the_code`; `test_an_expired_code_is_refused` (travel 11 minutes); `test_a_code_cannot_be_used_twice`; `test_a_new_code_invalidates_the_old_one`; `test_the_token_stops_working_after_eight_hours`; `test_creating_a_super_admin_needs_a_password_of_14_characters`; `test_the_code_request_is_throttled`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Central/SuperAdminLoginTest.php` - expect FAIL.
- [ ] **Step 3: Implement** the service, mail, rule, controller methods, routes (`throttle:login` on login, `throttle:central-code` on verify), command rule, limiter, translations.
- [ ] **Step 4: Run** the test and `tests/Feature/Central` - expect PASS (update any old test that logged in with one step).
- [ ] **Step 5: Commit** `feat: Super Admin sign-in with a code sent by email`.

### Task 6: Schools API: list, create, edit, extend, suspend, reactivate, audit

**Files:**
- Create: `backend/app/Central/Services/CentralAudit.php`, `backend/app/Central/Services/SchoolActions.php`, `backend/app/Central/Http/Controllers/SchoolActionController.php`, `backend/tests/Feature/Central/SchoolsApiTest.php`
- Modify: `backend/app/Central/Http/Controllers/TenantController.php`, `backend/app/Central/Http/Resources/TenantResource.php`, `backend/app/Central/Http/Requests/{Store,Update}TenantRequest.php` (add `max_students` nullable integer min 1, `subscription_ends_at`; admin password min 8 as before), `backend/routes/central/api.php`

**Interfaces:**
- Produces: `CentralAudit::record(string $action, ?string $tenantId, array $meta = []): void` (writes `central_audit_logs` with the signed-in Super Admin's id); `SchoolActions::suspend(Tenant)`, `reactivate(Tenant)` (sets `is_active=true`, clears `closed_at` and `exit_window_ends_at`), `extend(Tenant, CarbonInterface $endsAt)`, each recording the audit. Endpoints (all `auth:sanctum` + `super-admin`): `GET /api/central/tenants?search=&state=&page=` (state filter uses `SchoolStatus`), `POST .../tenants`, `PATCH .../tenants/{tenant}`, `POST .../tenants/{tenant}/suspend`, `.../reactivate`, `.../extend {subscription_ends_at}`, `GET .../tenants/{tenant}/audit`. `TenantResource` adds `state`, `max_students`, `students_count` and `staff_count` (cached together for 5 minutes under `school.counts.<id>`, counted with `$tenant->run(...)`), `last_backup_at` (the newest entry of `BackupService::list($tenant->id)`, null when none), `exit_window_ends_at`, `closed_at`.

- [ ] **Step 1: Write the failing tests**: `test_only_a_super_admin_can_list_schools`; `test_the_list_has_state_end_date_and_student_counts`; `test_the_list_filters_by_state_and_searches_by_name_or_code`; `test_a_school_is_created_with_a_limit_and_an_end_date`; `test_suspend_then_reactivate_changes_the_state_and_writes_the_audit`; `test_reactivating_a_closed_school_clears_the_window_and_closed_at`; `test_extend_sets_the_new_end_date`; `test_the_counts_are_cached`; `test_last_backup_at_is_the_newest_backup_or_null`.
- [ ] **Step 2: Run** - expect FAIL.
- [ ] **Step 3: Implement** the services, controller, resource, requests and routes.
- [ ] **Step 4: Run** the test and `tests/Feature/Central` - expect PASS.
- [ ] **Step 5: Commit** `feat: Super Admin schools API with audit log`.

### Task 7: Student limit

**Files:**
- Create: `backend/app/Modules/People/Support/StudentLimit.php`, `backend/app/Modules/People/Support/StudentLimitReached.php`, `backend/tests/Feature/People/StudentLimitTest.php`
- Modify: `backend/app/Modules/People/Actions/SaveStudent.php` (`create`), `backend/app/Modules/People/Http/Controllers/ImportController.php` or the student importer's commit step, `backend/bootstrap/app.php` (render the exception), `backend/lang/*`

**Interfaces:**
- Produces: `StudentLimit::assertRoom(int $adding = 1): void` reads `tenant()?->max_students` and the **live** `Student::count()` (never the cached count) and throws `StudentLimitReached` (with `limit`, `current`, `allowed`) rendered as 422 `student_limit_reached`; `SaveStudent::create` calls it inside its existing transaction before inserting; the import commit calls `assertRoom($newRows)` before writing anything (all or nothing).

- [ ] **Step 1: Write the failing tests**: `test_creating_a_student_at_the_limit_is_refused_with_the_code`; `test_null_means_unlimited`; `test_editing_a_student_at_the_limit_still_works`; `test_an_excel_import_that_would_pass_the_limit_is_refused_as_a_whole_and_says_how_many_are_allowed` (nothing imported); `test_an_import_that_fits_works`; `test_two_creations_when_one_place_is_left_only_one_succeeds`; `test_lowering_the_limit_below_the_count_only_blocks_new_students`.
- [ ] **Step 2: Run** - expect FAIL.
- [ ] **Step 3: Implement** the guard and its two call sites; translations in both languages ("Your school's limit is {limit} students; contact the platform owner").
- [ ] **Step 4: Run** the test and `tests/Feature/People` - expect PASS.
- [ ] **Step 5: Commit** `feat: per-school student limit`.

### Task 8: Super Admin frontend

**Files:**
- Create: `frontend/src/features/SuperAdmin/{saApi.js,SuperAdminLoginPage.jsx,SchoolsPage.jsx,SchoolPage.jsx,AddSchoolPage.jsx,SuperAdminLayout.jsx,useSuperAdmin.js}`, `frontend/tests/superAdmin.test.jsx`
- Modify: `frontend/src/app/router.jsx` (routes `/super-admin`, `/super-admin/schools`, `/super-admin/schools/new`, `/super-admin/schools/:id`), `frontend/src/i18n/ar.json`, `en.json`, `CLAUDE.md` (note that `tools/ui-check` does not cover `/super-admin`)

**Interfaces:**
- Consumes: the central endpoints of Tasks 5 and 6.
- Produces: `saApi` is its own Axios instance (`baseURL: '/api/central'`, token from `localStorage['sa-token']`, 401 clears it and goes to the login); `SuperAdminLoginPage` has two steps (email and password, then the 6-digit code); `SchoolsPage` lists schools (a table on `md` and up, cards on a phone through the shared table cards) with the state, end date, students used / limit and a search and state filter; `SchoolPage` edits the fields and has **Extend**, **Suspend / Reactivate** (with a confirmation) and the audit list; `AddSchoolPage` is the form of the spec ("Add school"). No link to this area from the public pages.

- [ ] **Step 1: Write the failing tests** (`vi.mock` of `saApi`): `login asks for the code after the password and stores the token`; `a wrong code shows the error`; `the list shows state, end date and used / limit`; `suspend asks for confirmation and calls the endpoint`; `extend sends the new date`; `the add form sends limit and end date`.
- [ ] **Step 2: Run** `npx vitest run tests/superAdmin.test.jsx` - expect FAIL.
- [ ] **Step 3: Implement** the pages, the layout (one top bar, like the other roles on a phone), the routes and both languages' strings.
- [ ] **Step 4: Run** `npm test`, `npm run build`, and check the screens on a phone, a tablet and a computer in both languages (`node tools/ui-check/run.cjs up`, then the Super Admin created with `php artisan super-admin:create`) - expect no sideways scroll; say what you could not check.
- [ ] **Step 5: Commit** `feat: Super Admin screens`.

### Task 9: Handover package

**Files:**
- Create: `backend/app/Shared/Handover/HandoverPackage.php`, `backend/app/Shared/Handover/HandoverSection.php` (interface), `backend/app/Shared/Handover/Sections/{Accounting,People,Payroll,Academic,UploadedFiles}.php`, `backend/tests/Feature/Handover/HandoverPackageTest.php`
- Modify: `backend/app/Shared/Export/XlsxExport.php`

**Interfaces:**
- Produces: `XlsxExport::write(string $path, string $sheetName, array $headers, iterable $rows): void` (the body of `download`, which now calls it); `interface HandoverSection { public function name(): string; public function write(string $dir): void; }` (writes its files into `$dir`); `HandoverPackage::build(): string` (run inside the school with `$tenant->run`; returns the path of a zip holding one folder per section and a `README.txt` with the school, the date and what each file is). Accounting first, with: fee structures, each student's fees and installments, payments with receipt numbers, money accounts and their entries, transfers, expenses, the journal, trial balance / income statement / balance sheet / tax summary for each academic year (via `AccountingReports`), budgets. People: students with guardians, staff with contracts. Payroll: runs and payslips. Academic: grades by year and term, attendance, behaviour, timetables. `UploadedFiles`: every file on the disks of `config('backup.file_disks')`.

- [ ] **Step 1: Write the failing tests**: `test_the_package_has_a_folder_per_section_and_a_readme`; `test_the_accounting_files_hold_a_students_installments_and_a_payment` (open the xlsx with openspout and assert the rows); `test_a_school_with_no_data_still_gives_a_valid_package_with_headers_only`; `test_uploaded_files_are_inside`; `test_xlsx_write_makes_a_file_and_download_still_works`.
- [ ] **Step 2: Run** - expect FAIL.
- [ ] **Step 3: Implement** `write`, the interface, the five sections (accounting first, one `XlsxExport::write` per file, rows streamed with `cursor()`), and `build` (zip with `ZipArchive`).
- [ ] **Step 4: Run** the test and `tests/Feature/Reports/ExportTest.php` - expect PASS.
- [ ] **Step 5: Commit** `feat: readable handover package`.

### Task 10: Exit window backend

**Files:**
- Create: `backend/database/migrations/central/2026_11_12_000002_create_school_exit_steps_table.php`, `backend/app/Central/Jobs/PrepareExit.php`, `backend/app/Central/Services/ExitWindow.php`, `backend/app/Central/Http/Controllers/ExitWindowController.php`, `backend/app/Modules/Auth/Http/Controllers/ExitExportController.php`, `backend/tests/Feature/Central/ExitWindowTest.php`
- Modify: `backend/routes/central/api.php`, `backend/routes/tenant/api.php` (the two `exit-export` routes inside `school-user` and `role:admin`), `backend/lang/*`

**Interfaces:**
- Consumes: `HandoverPackage::build`, `BackupService::build/store`, `CentralAudit::record`, `SchoolStatus`.
- Produces: table `school_exit_steps` (`id`, `tenant_id`, `step` in `archive|package`, `status` in `pending|running|done|failed`, `path` null, `error` null, `finished_at` null); `ExitWindow::open(Tenant $school, int $days = 14): void` (creates the two steps and dispatches `PrepareExit`); `PrepareExit` (`ShouldQueue`, tries each step separately, stores the archive with label `archive/<code>` and the package under `handover/<code>`, and **only when both are `done`** sets `exit_window_ends_at = now()->addDays($days)`); `ExitWindow::extend(Tenant $school, int $days): void`; central endpoints `POST /api/central/tenants/{tenant}/exit-window {days}`, `PATCH` (extend), `GET .../exit-steps`, `POST .../exit-steps/{step}/retry`, `GET .../package` and `GET .../archive` (downloads); school endpoints `GET /api/exit-export` (`{ ends_at, seconds_left, ready }`, 404 when there is no open window) and `GET /api/exit-export/package` (streams the package, writes `CentralAudit::record('package_downloaded', $code, ['by' => user id])`).

- [ ] **Step 1: Write the failing tests** (`Storage::fake`, `Queue::fake` then run the job synchronously): `test_opening_the_window_creates_both_steps_and_queues_the_job`; `test_the_window_starts_only_when_both_steps_are_done`; `test_a_failed_package_step_does_not_open_the_window_and_can_be_retried`; `test_extend_adds_days_to_an_open_window`; `test_an_administrator_downloads_the_package_in_the_window_and_it_is_audited`; `test_a_teacher_gets_403_on_the_download`; `test_after_the_window_the_administrator_is_blocked_too`; `test_nothing_is_deleted_the_school_database_and_files_are_untouched`; `test_reactivating_after_the_window_brings_everybody_back`.
- [ ] **Step 2: Run** - expect FAIL.
- [ ] **Step 3: Implement** the migration, service, job, controllers and routes.
- [ ] **Step 4: Run** the test and `php artisan test` - expect PASS.
- [ ] **Step 5: Commit** `feat: exit window with archive and handover package`.

### Task 11: Exit window screens

**Files:**
- Create: `frontend/src/features/Auth/DownloadYourDataPage.jsx`, `frontend/tests/exitWindow.test.jsx`
- Modify: `frontend/src/features/SuperAdmin/SchoolPage.jsx` (the exit section: button, days, step progress with retry, extend, downloads), `frontend/src/app/router.jsx`, `frontend/src/app/guards.jsx` (an administrator of a school in an exit window lands on `/download-your-data`), `frontend/src/i18n/ar.json`, `en.json`

**Interfaces:**
- Consumes: Task 10's endpoints; `GET /api/school` `exit_window_ends_at` (Task 2).
- Produces: `DownloadYourDataPage` shows the countdown (days and hours left), the date the window ends, one download button (authenticated blob download, the pattern of `openAttachment`) and the owner's contact line; nothing else of the app.

- [ ] **Step 1: Write the failing tests**: `the page shows the days left and downloads the package`; `an administrator in an exit window is sent to the download page`; `the school page shows the two steps with their status and a retry on a failed one`; `opening the window sends the days`; `extend sends the new days`.
- [ ] **Step 2: Run** `npx vitest run tests/exitWindow.test.jsx` - expect FAIL.
- [ ] **Step 3: Implement** the page, the guard rule and the exit section.
- [ ] **Step 4: Run** `npm test`, `npm run build`, and check both screens on a phone, a tablet and a computer in both languages with `tools/ui-check`; say what you could not check.
- [ ] **Step 5: Commit** `feat: exit window screens`.

### Task 12: Documentation

**Files:**
- Modify: `README.md` (a "Super Admin" section: sign-in, states, the limit, the exit window, what the package holds, the mail setup, the migration to run), `CLAUDE.md` (Architecture: the central area, `SchoolStatus` and the middleware; commands `super-admin:create`)

- [ ] **Step 1: Write** the README section (what it does, its rules, its settings, that `php artisan migrate --path=database/migrations/central` is needed) and the `CLAUDE.md` lines.
- [ ] **Step 2: Check** every path and command named exists (`ls`, `php artisan list | grep super-admin`).
- [ ] **Step 3: Commit** `docs: Super Admin`.
