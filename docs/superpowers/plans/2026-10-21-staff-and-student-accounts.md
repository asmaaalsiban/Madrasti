# Staff and student accounts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Staff with types, generated student emails, guardian phone, parents see their children's email.
**Spec:** `docs/superpowers/specs/2026-10-21-staff-and-student-accounts-design.md`

## Global Constraints
- Admin only routes behind the `school-user` group; Policies, Form Requests, Resources; translatable messages.
- Default password only from `config('accounts.default_password')`; the new `account` block only in the create response.
- Frontend: no hardcoded strings, logical CSS only. Branch `feature/staff-and-student-accounts` from `main`; PR on `main`.

## Review Focus
- A type that grants admin access creates an `admin` account; downgrading revokes tokens (Task 2).
- Generated email collisions (teacher with the same local part, soft-deleted users, two students same name+number case) (Task 3).
- Guardian phone owned by another account: 422, nothing created (Task 3).
- A parent sees only their own children's emails (Task 3).
- Old teachers without a profile still work (list, homeroom rule) (Task 2).

### Task 1: StaffType, migration, profile model
Files: `app/Shared/Enums/StaffType.php`, migration `..._add_staff_type_to_staff_profiles.php` (with backfill), `app/Modules/People/Models/StaffProfile.php`, `User::staffProfile()`; tests `tests/Unit/StaffTypeTest.php`, migration test.
### Task 2: Staff API (replaces teachers)
Files: `StaffController`, `StaffRequest`, `StaffResource`, `StaffTypeController`, `SaveStaff` action, `UserPolicy`, `ClassroomRequest`, routes; tests `StaffTest` (rename of `TeacherTest`), `ReviewFixesTest`/`ResetPasswordTest`/`ClassroomTest` updates.
### Task 3: Students (generated email, guardian phone) and parent children
Files: `StudentEmail` service, `SaveStudent`, `StudentRequest`, `StudentResource`, `UserResource`, `AuthController`; tests `StudentTest`, `StudentEmailTest`, `AuthTest` (children email), seeder + `SeedDemoTest`.
### Task 4: Frontend
Files: `ResourcePage`, `StaffPage`, `StudentsPage`, `ClassroomsPage`, `ParentHome`, router, `AdminLayout`, i18n; tests `staffPage.test.jsx`, `peoplePages.test.jsx`, `parentHome.test.jsx`.
### Task 5: Docs, review, PR
README, full runs, independent review, fix Critical/Important test-first, PR on `main`.
