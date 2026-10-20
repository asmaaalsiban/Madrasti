# Staff types and generated student accounts

## Intent
Teachers become **Staff** (all school employees with a type), and student accounts are generated instead of typed. Parents see their children's login email when they sign in.

Decisions (from the user): one **Staff** page with tabs per category; a teacher has **one** subject; the student email is `<english first name>.<student code>@<school code>.edu` (e.g. `ahmad.d1001@school2.edu`, `d1001@school2.edu` when there is no English name); guardian phone is added; parents see their children's email.

## Staff
- `StaffType` enum (value, category): **teaching** `teacher`, `head_of_department`, `subject_coordinator`, `trainee`, `substitute_teacher`; **administrative** `principal`, `vice_principal`, `general_manager`, `registrar`, `accountant`, `hr`, `data_entry`; **student_affairs** `counselor`, `floor_supervisor`, `bus_supervisor`, `activities_coordinator`, `sports_coach`; **health** `nurse`, `pharmacist`; **technical** `librarian`, `lab_technician`, `it_support`; **services** `caretaker`, `driver`, `security_guard`, `canteen_worker`.
- Per type: `needsSubject()` (the five teaching types), `needsFloor()` (`floor_supervisor`), `grantsAdminAccess()` (`principal`, `vice_principal`, `general_manager` → account role `admin`; every other type → role `teacher`, which has no screens yet), `canBeHomeroom()` (`teacher`, `head_of_department`). Fine-grained permissions per type come later with each module's screens.
- Table `staff_profiles` (already exists: `user_id`, `job_title`, `hired_on`, `weekly_hours`) gets `staff_type` (default `teacher`), `subject_id` (nullable FK, null on delete), `floor` (nullable string). Existing teachers get a profile with type `teacher`.
- API `apiResource('staff')` replaces `teachers` (admin only): name, email (unique), phone (unique), is_active, `staff_type`, `subject_id` (kept only for types that need it), `floor` (only for types that need it). Lists users that have a staff profile (the school's first admin is not staff). Filters `?category=`, `?staff_type=`, `?homeroom=1`, `?search=`. New accounts get the default password and must change it. Changing the type updates the account role; when the role changes the user's tokens are revoked. Delete = soft delete + tokens revoked + homeroom cleared (as for teachers). Admin-level staff can be reset to the default password by an admin (the first admin cannot).
- `GET /api/staff-types`: `[{value, category, needs_subject, needs_floor, grants_admin_access, can_be_homeroom}]`.
- `ClassroomRequest`: the homeroom teacher must be active, not deleted, and of a type with `canBeHomeroom()` (an old teacher without a profile counts as `teacher`).

## Students
- The student email is **generated** when the student is created: `<slug(first word of name_en)>.<slug(student_number)>@<domain>`; without an English name `<slug(student_number)>@<domain>`; slug = lowercase `a-z0-9` only. Domain = `STUDENT_EMAIL_DOMAIN` or `<school code>.edu`. A collision with any existing user (including deleted) appends `2`, `3`, ... to the local part. The email never changes afterwards.
- Request: `student_email` is removed; `guardian_phone` (nullable, stored on the parent account; if the phone belongs to a different account → 422 on `guardian_phone`); `guardian_email` and `guardian_name` as before.
- The create response also carries `account: {email, password}` (the default password) so the admin can hand it over; list/show never do.
- `UserResource.children` (parents) adds `email` (the student's login email) and `classroom` (name).

## Frontend
- Admin menu **Staff** (`/admin/staff`) replaces Teachers: tabs per category (All + 6), table with type and subject, form with the type select (types of the open tab, all types when editing) and type-dependent fields (subject, floor). The types come from `/staff-types`.
- Students form: no email field, adds parent phone; after saving a new student a dialog shows the generated email and the default password; the table shows the email. Classroom form: homeroom teacher list from `staff?homeroom=1`.
- Parent home (`/parent`): a card per child with name, student number, classroom and login email.
- ResourcePage: `visibleWhen(form)` per field, `filters` prop, `onSaved` hook, `optionsParams` for selects.
- Strings under `staff.*` / `people.*` in ar/en.

## Out of scope
Per-type permissions and the screens of accountants/registrars/nurses, several subjects per teacher, editing a student's email, parent actions.

## Operations
`php artisan tenants:migrate` once per install.
