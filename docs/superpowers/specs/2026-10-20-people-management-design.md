# Teachers, students and their parents: accounts with a default password

## Intent
The school admin creates the accounts of teachers, students and parents. Every account gets the same default password (`123456789`, configurable) and **must change it at the first sign-in**. Users can change their password (never their email); the admin can reset it back to the default.

Decisions (from the user): the admin registers a student with the student's email and the parent's email; students and parents sign in with their own email; users may change the password but not the email; forced change at first sign-in = yes; teachers behave like students.

## Backend (`app/Modules/People`, tenant API, admin only unless noted)
- Migration `users.must_change_password` boolean default false. Config `config/accounts.php`: `default_password` from env `DEFAULT_ACCOUNT_PASSWORD` (default `123456789`).
- `Auditable` stops writing hidden attributes (`password`, `remember_token`) to `audit_logs`; `User` and `Student` become auditable.
- Teachers: `apiResource('teachers')` on `users` with role `teacher`. Fields: name, email (unique in users), phone (nullable, unique), is_active. Create sets the default password and `must_change_password=true`. Delete = soft delete, revoke tokens, clear `classrooms.homeroom_teacher_id`.
- Students: `apiResource('students')`. Fields: student_number (unique), name_ar, name_en?, birth_date?, gender?, `student_email` (unique in users), `classroom_id`? (enrolls for that classroom's academic year), `guardian_email`?, `guardian_name`?. One transaction creates the student account (role student, default password), the `students` row, the enrollment and the guardian link. A guardian email that already belongs to a parent account is reused (one parent, many children); one that belongs to another role, or equals the student email, is a 422 on `guardian_email`. New parent accounts are named `guardian_name` or "Parent of <student name>". Update can change the student fields, the student email, the classroom (enrollment for its year) and add a guardian. Delete = soft delete the student and its user (tokens revoked); parents stay.
- `POST /api/users/{user}/reset-password` (admin): resets a teacher/student/parent to the default password, `must_change_password=true`, revokes tokens; admins cannot be reset this way (403).
- `POST /api/auth/change-password` (any signed-in user, throttled 5/min per user): `current_password`, `password` (min 8, confirmed, different from the current one and not the default); sets `must_change_password=false`, revokes the user's other tokens, returns the user.
- Middleware `password.changed` (all role areas, applied to the admin group now): while `must_change_password` is true every request except `auth/me`, `auth/logout`, `auth/change-password` gets 403 `{code: 'password_change_required'}`.
- `UserResource` adds `must_change_password`. `school:seed-demo` adds demo students (with parents) to classrooms.

## Frontend
- Admin pages **Teachers** and **Students** (shared `ResourcePage`): extra row action "Reset password" (with confirmation), student form with the student email, the parent email/name and the classroom; the classroom form gets the homeroom teacher select.
- `ChangePasswordPage` (`/change-password`, every role): current, new, confirm; links in the admin and dashboard headers. `RequireAuth` sends users with `must_change_password` to this page until it is done.
- All strings under `people.*` and `auth.*` in `ar.json`/`en.json`; logical CSS only.

## Out of scope
Excel import, "forgot password" by email, photos, the students'/teachers' own home screens, editing guardians other than adding one.

## Tests
Backend: CRUD + validation + authorization (non-admin 403) for teachers and students; guardian reuse/conflicts; enrollment for the classroom's year; default password and forced change; change-password rules (wrong current, same, default, throttle, other tokens revoked); middleware blocks until changed; reset; audit logs never contain `password`; seed idempotent. Frontend: pages, extra action, change-password form and forced redirect, Arabic/English labels.

## Operations
Existing schools need `php artisan tenants:migrate` for the new column.
