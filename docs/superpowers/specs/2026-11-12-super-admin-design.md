# Super Admin: managing schools, subscriptions and exits

Date: 2026-11-12. Status: draft for approval (updated with the owner's answers).

## Intent and why

The owner sells the platform to many schools and today has no screen to run that business: only a login endpoint, a `super-admin:create` command and a bare API (`/api/central/tenants`: list, create, update). The owner wants **one private area, only for them**, to add a school and its first admin, say how big the school may be and until when it has paid, switch a school off when it has not renewed, and, when a school leaves, give it all its data while **keeping every byte of it themselves** in case it comes back.

## Goals

1. A Super Admin web area (own login, central credentials, two-step verification) with: schools list, add school, edit, extend the subscription, suspend / reactivate.
2. **Subscription enforced for real**: a suspended school, or one whose end date has passed, is blocked on its next request, including people already signed in. They see a clear "subscription ended" message and cannot use the app until the school is reactivated or extended.
3. A **student limit** per school.
4. **Exit window**: for a school that is leaving, one button lets its administrators back in for a limited time, only to download all their data (a readable handover package). A countdown is shown to them and to the owner. When it ends, the school is closed for everybody. The owner can extend the window.
5. The owner keeps a complete **archive** of every school (restorable) and nothing is ever deleted.
6. Renewal is **manual**: the owner receives the money outside the system and sets the new end date.

## Non-goals and anti-goals

- No online billing, invoices, card subscriptions or automatic renewal. No plans catalogue: a school has an end date and a student limit, nothing more.
- **No deletion of school data, ever, by this system**: no delete button, no scheduled delete. Suspending, expiring and closing only block access. (If the owner ever has to erase a school, for example because the law or a contract demands it, that is a separate decision made later.)
- The Super Admin screens never expose one school's data to another; tenant code still never queries across school databases (the owner's list reads each school through `tenant->run`).
- A blocked school has no self-service export, except inside an exit window the owner opened.

## Approaches considered

**Enforcing the block.** (a) Check the school's status on every tenant request (chosen): immediate, no scheduler, expiry works by itself, no extra query because the tenant row is already loaded. (b) Delete the school's tokens on suspension: immediate for the suspension, but does not cover expiry without a nightly job, and everybody must sign in again after renewal. (c) Both: extra complexity for nothing.

**Handing the school its data.** (a) Give the existing backup zip: fastest, but it is machine-readable JSON, useless to a school. (b) A readable handover package only: the owner then has nothing to restore from. (c) Both (chosen): the existing backup as the owner's **archive**, plus a new **handover package** for the school.

**How the school gets the package.** (a) The owner sends a link and a password (chosen first, rejected): secrets to pass around and lose. (b) The school's administrator signs in during the exit window and downloads it inside the app (chosen): the sign-in is the protection, nothing is passed around, and the countdown is visible where they work.

## The design

### 1. Enforcement (tenant API)

- New middleware `EnsureSchoolActive`, placed right after tenancy is initialised, on every tenant route. A school's state is derived from three columns: `is_active`, `subscription_ends_at`, `exit_window_ends_at`. Blocked means: `is_active` is false, or the end date is in the past (the end date counts through its whole day, in the app's time zone; `null` means no end date). Answer: HTTP 403 `{ code: "school_suspended", message }` (translated).
- **Exit window exception**: while `exit_window_ends_at` is in the future, the middleware lets through only: signing in, `GET /api/school`, and the exit-export endpoints below, and only for users of the **admin** role (an accountant or teacher gets the blocked answer; the administrator hands them the files). Everything else stays 403. After the window ends, the school is blocked for everybody.
- Exempt as before: `GET /api/school` (it still shows the name and a notice; its answer gets `suspended: true` and, in a window, `exit_window_ends_at`), and the payment webhooks (they already answer 404 for an inactive school).
- Frontend: the Axios client treats a 403 `school_suspended` as "sign this school out": clears the school's token and goes to a page that says the subscription has ended and to contact the platform owner. In an exit window an administrator who signs in lands on the **Download your data** page instead: the countdown, a button to download the package, the date the window ends, and the contact of the owner. After a renewal people simply sign in again.
- Scheduled per-school commands that notify people (finance, contracts, duties, tasks, warnings reminders) do nothing for a blocked school; notification and push jobs check the status when they run. **Backups keep running** for blocked schools.

### 2. Super Admin area

- Frontend routes under `/super-admin` (not linked from the public pages): sign-in, schools list, school page. A token of its own, kept apart from the school tokens, expiring after 8 hours. Backend: the existing `routes/central/api.php` (`auth:sanctum` + `super-admin`), extended.
- **Sign-in, two steps**: email and a **long password** (at least 14 characters, checked when it is set), then a **6-digit code sent to the Super Admin's email**. The code is stored hashed, lasts 10 minutes, allows 5 wrong tries and is used once; the existing login throttle stays and the code request is throttled too (the same pattern as the school password-reset code). Mail goes through the app's normal mail settings (in production an SMTP account, for example Gmail with an app password). Email as the second factor is weaker than an authenticator app (whoever reads the inbox has both steps), so an authenticator app is a possible later upgrade.
- **Schools list**: name, code, status (active / expired / suspended / in exit window / closed), end date, students used / limit, created. Search and filter by status.
- **Add school**: name, code (same rule as everywhere), first admin (name, email, password), subscription end date, student limit. Uses `CreateSchool` unchanged (database, migrations, seeds, admin, cleanup on failure).
- **School page**: the same fields editable; **Extend** (set a new end date), **Suspend / Reactivate** (one button, confirmation), the numbers (students, staff), last backup time, and the exit actions (section 4).
- A small central audit log: who (which super admin), what (create, suspend, reactivate, extend, open or extend an exit window, download of a package), which school, when. Shown on the school page.
- The owner can create further Super Admins only through the `super-admin:create` command (no screen for it yet; the command asks for a password of at least 14 characters).

### 3. Student limit

- `max_students` on the school (`null` = unlimited). The school's own student count is all rows of `students` in its database.
- Enforced where students are created (the student form and the Excel import) with the live count, never the cached one: at the limit the request is refused with a translated 422 (`student_limit_reached`) saying the limit and how to contact the owner. An Excel import that would pass the limit is refused as a whole, saying how many students are still allowed. Existing students are never removed; lowering the limit below the current count only blocks new ones.
- The owner's list shows used / limit. The count of each school is cached for a few minutes so the list does not open twenty school databases on every visit.

### 4. Exit window and the package

Super Admin action **"Allow data download"** on a school, with the number of days (default **14**, editable; the owner can **extend** it later). Pressing it:

1. Builds, as queued jobs with progress shown on the school page, an **archive** (the existing `BackupService::build` of the school, with its uploaded files, kept on the backup disk under `archive/<code>/`, encrypted with `BACKUP_PASSWORD` when set) and a **handover package**.
2. Sets `exit_window_ends_at` (now + the days). From now on, section 1 applies: the school's administrators can sign in and only download.
3. When the window ends the school is closed for everybody (`closed_at` is set by the first request after the end date or by a daily job, whichever comes first; either way the middleware already blocks by the date).

The **handover package** is a zip of readable files (Excel via the existing `XlsxExport`, PDF only where a report already exists: the report card, the existing accounting reports) and the uploaded files, stored under `handover/<code>/`, with a `README` saying what each file is, the date and the school:

- **Accounting (first priority)**: fee structures, each student's fees and installments, payments and receipts, money accounts and transfers, expenses, the journal, the accounting reports (trial balance, income, balance sheet, tax) for each year, budgets.
- People: students and their guardians, staff and their contracts.
- Payroll: runs and payslips.
- Academic: grades and report cards by year, attendance, behaviour, timetables.
- Uploaded files (student files, homework photos, signatures).

The administrator downloads it from the **Download your data** page (a normal authenticated request); each download is written to the central audit log. The owner can also download the package and the archive from the school page. If the school comes back, the owner reactivates it and extends the date: nothing was deleted, so everything is as it was.

## Constraints

- Laravel 12, `stancl/tenancy`, one database per school; central migrations in `database/migrations/central` (`max_students`, `exit_window_ends_at`, `closed_at` are columns on `tenants`, listed in `Tenant::getCustomColumns`; plus a table for the central audit log and one for the Super Admin's sign-in codes).
- All user-facing text in Arabic and English (`lang/`, `ar.json`/`en.json`); the Super Admin area and the download page are mobile-friendly like the rest (see `CLAUDE.md`: phone layout, `tools/ui-check`).
- Every new endpoint has tests; the enforcement, the exit window (an admin passes, a teacher does not) and the sign-in code have tests with two real schools where it matters (like `SchoolTokenIsolationTest`).
- Large exports run as queued jobs, never inside the request.

## Order of work

1. Enforcement middleware, `suspended` flag on `GET /api/school`, the frontend sign-out page, the skipped reminders. Super Admin sign-in **with the email code**, the schools list / add / edit / extend / suspend, and the audit log.
2. Student limit and the used / limit numbers.
3. Exit window: the archive, the handover package (accounting first, then the other parts), the **Download your data** page and the countdown.
4. Later, if wanted: warning emails to a school's admin before the end date (30 / 7 / 1 days); an authenticator app as the second factor.

## Calls made while checking the design (the writer's, not agreed)

- **Where things live**: the archive and the package are on the backup disk under `archive/<code>/` and `handover/<code>/`, outside the school's own folders. The archive includes the uploaded files: `BackupService::build` adds every disk in `config('backup.file_disks')`.
- **Time zone**: the app's (`config('app.timezone')`).
- **Closing is a state**: `tenants.closed_at`. The status of a school is derived: active, expired, suspended, in exit window, closed. Each archive/package step is its own row with a status and can be retried alone; a failed step never leaves the school half-prepared, and the window does not start until both are ready. Reactivating a closed school is allowed (it clears `closed_at`).
- **Who may download in a window**: school administrators only.
- **Other queued work for a blocked school**: notification and push jobs check the school's status when they run and drop out; payment webhooks stay as they are (404 when inactive).
- **Handover files**: Excel is enough everywhere; PDF only where a report already exists.
- **Window length**: 14 days by default (an accountant needs time to check the accounting files); the owner can change it when opening and extend it later.

## Left to the builder

The exact layout of the screens; the shape of each Excel file; how progress of the preparation jobs is stored; caching times; the translation wording; whether the suspended page is a route or a full-page state.
