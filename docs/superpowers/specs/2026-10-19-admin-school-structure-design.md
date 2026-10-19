# Admin dashboard: school structure (slice 1)

## Intent
Give the school `admin` a dashboard to manage the school structure: academic years, grade levels, classrooms (sections) and subjects. Backend API and frontend UI ship together in one branch and one PR (`feature/admin-school-structure`).

Out of scope: users (teachers/students/parents), terms, teacher-subject assignment, attendance, grades, import/export.

## Backend (`app/Modules/Academic`)
Four REST resources under `routes/tenant/api.php`, inside `auth:sanctum` + `role:admin`:

| Route prefix | Table | Fields |
|---|---|---|
| `academic-years` | academic_years | name, starts_on, ends_on, is_current |
| `grade-levels` | grade_levels | name_ar, name_en, sort_order |
| `classrooms` | classrooms | name, grade_level_id, academic_year_id, homeroom_teacher_id (nullable), capacity (nullable) |
| `subjects` | subjects | code (unique), name_ar, name_en |

- Models, Policies (admin only), Form Requests (translatable messages in `lang/{ar,en}`), API Resources, one Controller each (index with `?search=` and pagination, store, show, update, destroy).
- `GET /admin/overview` returns counts of the four resources for the dashboard cards.
- Rules: `ends_on` after `starts_on`; setting `is_current` unsets it on other years; a grade level, year or classroom that is in use cannot be deleted (422 with translated message).
- Audit log: create/update/delete write to `audit_logs` via an `Auditable` trait in `app/Shared/Traits`.
- Tests (Pest): CRUD per resource, validation, non-admin gets 403, tenant isolation, audit entries.

## Frontend (`src/`)
- `layouts/AdminLayout.jsx`: sidebar on desktop, collapsible menu on mobile; replaces the generic shell for `/admin/*` routes. Logical CSS properties only.
- Routes under `/admin`: `/admin` (overview), `/admin/academic-years`, `/admin/grade-levels`, `/admin/classrooms`, `/admin/subjects`, guarded by `RequireRole role="admin"`.
- `features/Academic/{api,hooks,pages,components}`: TanStack Query hooks per resource using the existing `api/client.js`.
- Shared UI in `components/shared`: `DataTable` (search, pagination), `Modal`, `FormField`, `ConfirmDialog`.
- All strings under `admin.*` / `academic.*` in `ar.json` and `en.json`; API validation errors shown per field.
- Tests (Vitest): overview counts render, list/create/delete flows with mocked API, Arabic and English.

## Success criteria
- An admin can create, edit, search and delete each of the four resources in Arabic (RTL) and English (LTR), on desktop and mobile.
- Non-admins cannot reach the routes (UI guard) or the API (403).
- `php artisan test` and `npm test` pass; every change on these resources is in the audit log.
