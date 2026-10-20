# People Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Admin creates teacher/student/parent accounts with a default password that must be changed at first sign-in.
**Spec:** `docs/superpowers/specs/2026-10-20-people-management-design.md`

## Global Constraints
- Default password `123456789` via `config('accounts.default_password')`; never hardcode it elsewhere. Passwords never reach `audit_logs`.
- Admin-only routes under `auth:sanctum` + `role:admin` + `password.changed`; Policies; Form Requests; API Resources; translatable messages (`lang/{ar,en}`).
- Frontend: no hardcoded strings, logical CSS only. Branch `feature/people-management` from `main`; PR on `main`.

## Review Focus
- Guardian email that is an existing teacher/admin/student account: 422, nothing created (Task 3).
- Same email for student and parent: 422 (Task 3). Parent with two children: one account (Task 3).
- A user with `must_change_password` calling any admin/API route: 403 `password_change_required` (Task 1).
- Reset on an admin account: 403 (Task 2). New password equal to the default or current: 422 (Task 1).
- Deleting a student frees nothing silently: email reusable? (soft-deleted user keeps the email unique: document, 422 on reuse) (Task 3).

### Task 1: Foundation and password change
Files: migration `database/migrations/tenant/2026_10_20_000012_add_must_change_password_to_users.php`, `config/accounts.php`, `.env.example`, `app/Shared/Traits/Auditable.php`, `app/Models/User.php`, `app/Modules/Academic/Models/Student.php`, `app/Modules/Auth/Http/Resources/UserResource.php`, `app/Modules/Auth/Http/Controllers/AuthController.php` + `ChangePasswordRequest`, `app/Http/Middleware/EnsurePasswordChanged.php` (alias `password.changed`), `bootstrap/app.php`, `AppServiceProvider` (limiter `change-password`), `routes/tenant/api.php`; tests `tests/Feature/Auth/ChangePasswordTest.php`, `tests/Feature/Audit/AuditableTest.php` (update).
- [ ] RED then GREEN per the spec bullets above; commit.

### Task 2: Teachers and reset password
Files: `app/Modules/People/{Http/Controllers/TeacherController.php,UserPasswordController.php,Http/Requests/TeacherRequest.php,Http/Resources/TeacherResource.php}`, policy, routes; tests `tests/Feature/People/TeacherTest.php`, `ResetPasswordTest.php`.
- [ ] RED then GREEN; commit.

### Task 3: Students, parents and enrollment
Files: `app/Modules/People/Actions/RegisterStudent.php`, `UpdateStudent.php`, `Http/Controllers/StudentController.php`, requests/resources, routes; tests `tests/Feature/People/StudentTest.php`; extend `SeedDemoSchool` + `SeedDemoTest`.
- [ ] RED then GREEN; commit.

### Task 4: Frontend pages
Files: `src/features/Academic/components/ResourcePage.jsx` (select `options`, `getValue`, `rowActions`), `src/components/shared/DataTable.jsx` (extra actions), `src/features/People/pages/{TeachersPage,StudentsPage}.jsx`, `ClassroomsPage.jsx` (homeroom teacher), `src/layouts/AdminLayout.jsx` nav, i18n; tests `tests/peoplePages.test.jsx`.
- [ ] RED then GREEN; `npm test`, oxlint, build; commit.

### Task 5: Change password UI and forced change
Files: `src/features/Auth/pages/ChangePasswordPage.jsx`, `src/features/Auth/AuthProvider.jsx` (`updateUser`), `src/app/guards.jsx`, `src/app/router.jsx`, layouts links, i18n; tests `tests/changePassword.test.jsx`.
- [ ] RED then GREEN; commit.

### Task 6: Docs, review, PR
- [ ] README (default password, `tenants:migrate`, people management); full test runs; screenshots; fresh whole-branch review; fix Critical/Important test-first; push; PR on `main`.
