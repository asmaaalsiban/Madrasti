# Super Admin: managing schools, subscriptions and exits

Date: 2026-11-12. Status: draft for approval.

## Intent and why

The owner sells the platform to many schools and today has no screen to run that business: only a login endpoint, a `super-admin:create` command and a bare API (`/api/central/tenants`: list, create, update). The owner wants **one private area, only for them**, to add a school and its first admin, say how big the school may be and until when it has paid, switch a school off when it has not renewed, and, when a school leaves, hand it all its data while keeping a copy in case it comes back.

## Goals

1. A Super Admin web area (own login, central credentials) with: schools list, add school, edit, extend the subscription, suspend / reactivate.
2. **Subscription enforced for real**: a suspended school, or one whose end date has passed, is blocked on its next request, including people already signed in. They see a clear "subscription ended" message and cannot use the app until the school is reactivated or extended. Nothing is deleted.
3. A **student limit** per school.
4. **Closing a school**: an archive the owner keeps (restorable) and a readable **handover package** for the school (people, accounting above all, grades, attendance, uploaded files), downloaded through a temporary protected link.
5. Renewal is **manual**: the owner receives the money outside the system and sets the new end date.

## Non-goals and anti-goals

- No online billing, invoices, card subscriptions or automatic renewal. No plans catalogue: a school has an end date and a student limit, nothing more.
- Never delete school data on suspension or expiry. Never delete data except by an explicit, separate, confirmed action.
- The Super Admin screens never expose one school's data to another; tenant code still never queries across school databases (the owner's list reads each school through `tenant->run`).
- Not a way around the school's own privacy rules: the handover link is private, temporary and protected.

## Approaches considered

**Enforcing the block.** (a) Check the school's status on every tenant request (chosen): immediate, no scheduler, expiry works by itself, no extra query because the tenant row is already loaded. (b) Delete the school's tokens on suspension: immediate for the suspension, but does not cover expiry without a nightly job, and everybody must sign in again after renewal. (c) Both: extra complexity for nothing.

**Handing the school its data.** (a) Give the existing backup zip: fastest, but it is machine-readable JSON, useless to a school. (b) A readable handover package only: the owner then has nothing to restore from. (c) Both (chosen): the existing backup as the owner's **archive**, plus a new **handover package** for the school.

## The design

### 1. Enforcement (tenant API)

- New middleware `EnsureSchoolActive`, placed right after tenancy is initialised, on every tenant route. It blocks when `is_active` is false or `subscription_ends_at` is in the past (the end date counts through its whole day; `null` means no end date). Answer: HTTP 403 `{ code: "school_suspended", message }` (translated).
- Exempt: `GET /api/school` (it must still show the name and a "subscription ended" notice; its answer gets a `suspended: true` flag), and the payment webhooks (they already answer 404 for an inactive school). The login endpoint is blocked like the rest (it already refuses inactive schools).
- Frontend: the Axios client treats a 403 `school_suspended` as "sign this school out": clears the school's token and goes to a page that says the subscription has ended and to contact the platform owner. After reactivation people simply sign in again.
- Scheduled per-school commands that notify people (finance, contracts, duties, tasks, warnings reminders) do nothing for a blocked school. **Backups keep running** for blocked schools.

### 2. Super Admin area

- Frontend routes under `/super-admin` (not linked from the public pages): login, schools list, school page. A token of its own, kept apart from the school tokens. Backend: the existing `routes/central/api.php` (`auth:sanctum` + `super-admin`), extended.
- **Schools list**: name, code, status (active / expired / suspended), end date, students used / limit, created. Search and filter by status.
- **Add school**: name, code (same rule as everywhere), first admin (name, email, password), subscription end date, student limit. Uses `CreateSchool` unchanged (database, migrations, seeds, admin, cleanup on failure).
- **School page**: the same fields editable; **Extend** (set a new end date), **Suspend / Reactivate** (one button, confirmation), the numbers (students, staff), last backup time, and the close-school actions (section 4).
- A small central audit log: who (which super admin), what (create, suspend, reactivate, extend, close, export, delete), which school, when. Shown on the school page.
- The owner can create further Super Admins only through the `super-admin:create` command (no screen for it yet).

### 3. Student limit

- `max_students` on the school (`null` = unlimited). The school's own student count is all rows of `students` in its database.
- Enforced where students are created (the student form and the Excel import): at the limit the request is refused with a translated 422 (`student_limit_reached`) saying the limit and how to contact the owner. Existing students are never removed; lowering the limit below the current count only blocks new ones.
- The owner's list shows used / limit. The count of each school is cached for a few minutes so the list does not open twenty school databases on every visit.

### 4. Closing a school

Super Admin action "Close school" on a (suspended) school, in steps, each a queued job with progress shown on the school page:

1. Suspend the school.
2. **Archive**: the existing `BackupService::build` of the school, stored on the backup disk under an `archive` label, encrypted with `BACKUP_PASSWORD` when set. Kept until the owner deletes it; restoring a school from it is the existing `backup:restore`.
3. **Handover package**: a zip with readable files (Excel via the existing `XlsxExport`, PDF where a report already exists) and the uploaded files:
   - **Accounting (first priority)**: fee structures, each student's fees and installments, payments and receipts, money accounts and transfers, expenses, the journal, accounting reports (trial balance, income, balance sheet, tax) for each year, budgets.
   - People: students and their guardians, staff and their contracts.
   - Payroll: runs and payslips.
   - Academic: grades and report cards by year, attendance, behaviour, timetables.
   - Uploaded files (student files, homework photos, signatures) and a `README` saying what each file is, the date, the school.
   Protected with a password generated for the package.
4. **Download link**: a temporary signed link (14 days by default, set by the owner), served over the central API, plus the password, both shown to the owner once so they can pass them to the school. Every download is written to the audit log.
5. **Delete** (optional, separate, explicit): the owner may set "delete the school's data on date X" or do it by hand, after typing the school code. It drops the school's database and files; the archive stays unless deleted too.

If the school comes back: reactivate and extend (nothing was deleted), or, if the data was deleted, restore the archive into a school with the same code.

## Constraints

- Laravel 12, `stancl/tenancy`, one database per school; central migrations in `database/migrations/central` (the school's `max_students` is a column on `tenants`, listed in `Tenant::getCustomColumns`).
- All user-facing text in Arabic and English (`lang/`, `ar.json`/`en.json`); the Super Admin area is mobile-friendly like the rest (see `CLAUDE.md`: phone layout, `tools/ui-check`).
- Every new endpoint has tests; the enforcement has a test with two real schools (like `SchoolTokenIsolationTest`).
- Large exports run as queued jobs, never inside the request.

## Order of work

1. Enforcement middleware, `suspended` flag on `GET /api/school`, the frontend sign-out page, the skipped reminders. Super Admin login and the schools list / add / edit / extend / suspend, with the audit log.
2. Student limit and the used / limit numbers.
3. Close school: archive, handover package (accounting first, then the other parts), download link, optional deletion.
4. Later, if wanted: warning emails to a school's admin before the end date (30 / 7 / 1 days).

## Calls made while checking the design (marked as the writer's, not agreed)

- **Lost link or password**: the password is never stored (the package is an AES zip; only its path and expiry are). A lost one is replaced with a **Rebuild package** action that makes a new package, link and password.
- **Where things live**: the archive and the package are on the backup disk under `archive/<code>/` and `handover/<code>/`, outside the school's own folders, so "delete the school's data" (its database and its own files) never touches them. The archive does include the uploaded files: `BackupService::build` adds every disk in `config('backup.file_disks')`.
- **Time zone**: the app's (`config('app.timezone')`); the end date counts through its whole day in that zone; `null` means no end date, never expired.
- **A blocked school and its own data**: no self-service export while blocked. Data leaves only through the owner's handover (see the question below).
- **Student limit**: enforcement reads the live count, never the cached one (the cache is only for the owner's list). All rows of `students` count (the schema has no withdrawn status). An Excel import that would pass the limit is **refused as a whole** with how many students are still allowed.
- **Closing is a state**: `tenants.closed_at`. Status of a school is derived: active, expired, suspended, closed. Each close step (archive, package, link) is its own row with a status, can be retried alone, and a failed step never leaves the school half-closed in the list. Reactivating a closed school is allowed (it clears `closed_at`); if its data was deleted it must first be restored from the archive.
- **Super Admin session**: the central token expires (Sanctum's default is never): 8 hours. The existing login throttle stays.
- **Other queued work for a blocked school**: notification and push jobs check the school's status when they run and drop out; payment webhooks stay as they are (404 when inactive).
- **Handover files**: Excel is enough everywhere. PDF only where a report already exists (the report card, the existing accounting reports).

## Left to the builder

The exact layout of the screens; the shape of each Excel file; how progress of a close job is stored; caching times; the translation wording; whether the suspended page is a route or a full-page state.
