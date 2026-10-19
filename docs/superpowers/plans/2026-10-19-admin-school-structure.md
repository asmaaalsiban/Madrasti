# Admin School Structure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin can manage academic years, grade levels, classrooms and subjects through a tenant REST API and an admin dashboard (Arabic RTL / English LTR).

**Architecture:** One controller/model/policy/request/resource set per resource in `app/Modules/Academic`, all routes under `auth:sanctum` + `role:admin`; an `Auditable` trait writes `audit_logs`. Frontend adds `AdminLayout`, shared table/modal components and one generic TanStack Query hook factory used by four pages.

**Tech Stack:** Laravel 11, Pest/PHPUnit (`Tests\TenantTestCase`), React 19, react-router-dom 7, TanStack Query, Tailwind 4, react-i18next, Vitest.

**Spec:** `docs/superpowers/specs/2026-10-19-admin-school-structure-design.md`

## Global Constraints

- Code, comments, commits in English; no hardcoded UI strings (`react-i18next`, keys `admin.*` / `academic.*` in `src/i18n/{ar,en}.json`).
- CSS logical properties only (`ms-4`, `pe-2`), never `left`/`right`.
- API Resources for responses, Form Requests for validation, Policies for authorization; messages translatable (`lang/{ar,en}`).
- Never query across tenant databases; tests extend `Tests\TenantTestCase` and call `withoutMiddleware([InitializeTenancyByDomain::class, PreventAccessFromCentralDomains::class])` as in `tests/Feature/Auth/AuthTest.php`.
- Audit entry for every create/update/delete on these resources.
- Commits end with the attribution lines required by the session; work on branch `feature/admin-school-structure`; open a PR at the end, never push to `main`.

## Review Focus

- Delete of an in-use year / grade level / classroom: 422 with translated message, row kept (tested in Tasks 3-5).
- Setting `is_current=true` on a year while another is current: only one stays current (Task 4).
- `ends_on` equal to or before `starts_on`: 422 (Task 4).
- Duplicate subject `code` on update to its own value must pass; to another's must 422 (Task 2).
- Non-admin (teacher/parent) and guest get 403/401 on every route (Task 2, repeated per resource by data provider).
- Empty list and search with no matches render an empty state, not an error (Task 8).

---

### Task 1: Auditable trait and AuditLog model

**Files:**
- Create: `backend/app/Shared/Traits/Auditable.php`, `backend/app/Modules/Audit/Models/AuditLog.php`
- Test: `backend/tests/Feature/Audit/AuditableTest.php`

**Interfaces:**
- Produces: trait `App\Shared\Traits\Auditable` (boot hooks on created/updated/deleted); model `App\Modules\Audit\Models\AuditLog` (`$timestamps = false`, casts `old_values`/`new_values` to array, `morphTo auditable()`).

- [ ] **Step 1: Write failing test** `test_create_update_delete_write_audit_logs`: use the trait on a Subject (created in Task 2 — so write this test against an anonymous model on `subjects` table via `Subject`; if Task 2 not done, create Task 1 and 2 test together). Assert after `Subject::create`, `update`, `delete` that `audit_logs` has actions `created`,`updated`,`deleted` in order, `new_values` contains changed fields, `old_values` contains previous values on update, `user_id` equals the acting `auth()->id()` (null when unauthenticated).
- [ ] **Step 2: Run** `php artisan test --filter=AuditableTest` → FAIL (class not found).
- [ ] **Step 3: Implement** trait `Auditable::bootAuditable(): void` registering the three model events and creating an `AuditLog` with `ip_address`/`user_agent` from `request()`; `AuditLog` model as described.
- [ ] **Step 4: Run** the test → PASS.
- [ ] **Step 5: Commit** `feat(backend): add Auditable trait and AuditLog model`.

### Task 2: Subjects API (establishes the CRUD pattern)

**Files:**
- Create: `app/Modules/Academic/Models/Subject.php`, `Policies/SubjectPolicy.php`, `Http/Requests/SubjectRequest.php`, `Http/Resources/SubjectResource.php`, `Http/Controllers/SubjectController.php`, `database/factories/SubjectFactory.php`, `lang/{ar,en}/academic.php`
- Modify: `routes/tenant/api.php` (add `Route::middleware(['auth:sanctum','role:admin'])->apiResource('subjects', SubjectController::class)`), `lang/en/validation.php` only if a key is missing
- Test: `tests/Feature/Academic/SubjectTest.php`

**Interfaces:**
- Produces: `Subject` (uses `Auditable`, fillable `code,name_ar,name_en`); JSON shape `{data:{id,code,name_ar,name_en}}`, index paginated `{data:[...],meta,links}` with `?search=` matching code/name_ar/name_en and `?per_page=` (default 15). `SubjectRequest::rules()` uses `Rule::unique('subjects','code')->ignore($this->route('subject'))`. Policy: `viewAny/view/create/update/delete` return `$user->role === Role::Admin` (match how `UserResource`/`User` expose role; check `User` model). Translation keys: `academic.in_use` (message for blocked deletes, params `:resource`).

- [ ] **Step 1: Write failing tests** in `SubjectTest`: `test_admin_can_crud_subject` (POST 201, GET index contains it, GET show, PUT 200, DELETE 204), `test_search_filters_by_name_and_code`, `test_code_must_be_unique_but_may_keep_own` (PUT same code → 200; other's code → 422 on `code`), `test_validation_errors_are_translated` (header `Accept-Language: ar` → Arabic message), and a data-provider test `test_non_admin_is_forbidden` for roles teacher, student, parent (403) and guest (401) on index and store.
- [ ] **Step 2: Run** `php artisan test --filter=SubjectTest` → FAIL (404 routes).
- [ ] **Step 3: Implement** the files above following the interfaces; register policy via `Gate::policy` in `AppServiceProvider` (or auto-discovery if it applies to the module path — verify, else register).
- [ ] **Step 4: Run** → PASS, plus `php artisan test` full suite green.
- [ ] **Step 5: Commit** `feat(backend): subjects CRUD API`.

### Task 3: Grade levels API

**Files:**
- Create: `GradeLevel` model, `GradeLevelPolicy`, `GradeLevelRequest`, `GradeLevelResource`, `GradeLevelController`, `GradeLevelFactory`; modify `routes/tenant/api.php`, `lang/{ar,en}/academic.php`
- Test: `tests/Feature/Academic/GradeLevelTest.php`

**Interfaces:**
- Consumes: Task 2 pattern and the `non-admin forbidden` data-provider approach.
- Produces: `GradeLevel` (fillable `name_ar,name_en,sort_order`, `hasMany classrooms()`); resource fields `id,name_ar,name_en,sort_order`; index ordered by `sort_order`, supports `?search=`; route `grade-levels`.

- [ ] **Step 1: Write failing tests:** `test_admin_can_crud_grade_level`, `test_index_is_ordered_by_sort_order`, `test_cannot_delete_grade_level_with_classrooms` (422, message from `academic.in_use`, row still exists), `test_non_admin_is_forbidden`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (delete blocked with `abort(422)`/ValidationException when `classrooms()->exists()`). **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(backend): grade levels CRUD API`.

### Task 4: Academic years API

**Files:**
- Create: `AcademicYear` model, `AcademicYearPolicy`, `AcademicYearRequest`, `AcademicYearResource`, `AcademicYearController`, factory; modify routes and lang
- Test: `tests/Feature/Academic/AcademicYearTest.php`

**Interfaces:**
- Produces: `AcademicYear` (fillable `name,starts_on,ends_on,is_current`; casts dates `Y-m-d`, `is_current` bool; `hasMany classrooms()`); route `academic-years`; index ordered by `starts_on` desc.

- [ ] **Step 1: Write failing tests:** `test_admin_can_crud_year`, `test_ends_on_must_be_after_starts_on` (equal and earlier → 422 on `ends_on`), `test_setting_current_unsets_previous_current` (create A current, create B current → A `is_current=false`, exactly one current), `test_cannot_delete_year_with_classrooms`, `test_non_admin_is_forbidden`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement**; unset other years inside a DB transaction in the controller store/update when `is_current` is true. **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(backend): academic years CRUD API`.

### Task 5: Classrooms API

**Files:**
- Create: `Classroom` model, `ClassroomPolicy`, `ClassroomRequest`, `ClassroomResource`, `ClassroomController`, factory; modify routes and lang
- Test: `tests/Feature/Academic/ClassroomTest.php`

**Interfaces:**
- Consumes: `GradeLevel`, `AcademicYear` models.
- Produces: `Classroom` (fillable `name,grade_level_id,academic_year_id,homeroom_teacher_id,capacity`; `belongsTo gradeLevel(), academicYear(), homeroomTeacher()` → `App\Models\User`); resource includes nested `grade_level{id,name_ar,name_en}`, `academic_year{id,name}`, `homeroom_teacher{id,name}|null`; index filters `?grade_level_id=&academic_year_id=&search=`; route `classrooms`. Validation: FKs `exists`; `homeroom_teacher_id` must be an existing user with role `teacher`; `capacity` nullable integer ≥ 1.

- [ ] **Step 1: Write failing tests:** `test_admin_can_crud_classroom`, `test_homeroom_teacher_must_be_a_teacher` (parent user id → 422), `test_capacity_must_be_positive`, `test_index_filters_by_grade_level_and_year`, `test_cannot_delete_classroom_with_enrollments`, `test_non_admin_is_forbidden`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (eager load relations in index to avoid N+1; enrollments check via the existing `enrollments` table). **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(backend): classrooms CRUD API`.

### Task 6: Admin overview endpoint

**Files:**
- Create: `app/Modules/Academic/Http/Controllers/AdminOverviewController.php`; modify `routes/tenant/api.php` (`GET admin/overview`, admin only)
- Test: `tests/Feature/Academic/AdminOverviewTest.php`

**Interfaces:**
- Produces: `GET /api/admin/overview` → `{data:{academic_years:int,grade_levels:int,classrooms:int,subjects:int}}`.

- [ ] **Step 1: Failing tests:** `test_overview_returns_counts` (create 2 years, 3 levels, 1 classroom, 4 subjects → exact counts; empty DB → all 0), `test_overview_forbidden_for_non_admin`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** invokable controller. **Step 4: Run** → PASS; run full `php artisan test`.
- [ ] **Step 5: Commit** `feat(backend): admin overview counts endpoint`.

### Task 7: AdminLayout, shared UI components and admin routes

**Files:**
- Create: `frontend/src/layouts/AdminLayout.jsx`, `src/components/shared/{DataTable,Modal,FormField,ConfirmDialog}.jsx`
- Modify: `src/app/router.jsx` (admin children use `AdminLayout`; keep `RoleHome` at `/admin` until Task 8), `src/i18n/{ar,en}.json` (`admin.nav.*`, `admin.actions.*` — add, edit, delete, save, cancel, search, empty, confirmDelete)
- Test: `frontend/tests/adminLayout.test.jsx`, `frontend/tests/sharedComponents.test.jsx`

**Interfaces:**
- Produces: `DataTable({columns:[{key,label,render?}], rows, page, lastPage, onPage, search, onSearch, onEdit, onDelete, loading})` showing `t('admin.actions.empty')` when `rows` is empty; `Modal({open,title,onClose,children})` (Escape closes, `role="dialog"`); `FormField({label,error,children})`; `ConfirmDialog({open,message,onConfirm,onCancel})`; `AdminLayout` renders nav links for `/admin`, `/admin/academic-years`, `/admin/grade-levels`, `/admin/classrooms`, `/admin/subjects` plus `<Outlet/>`, with a toggle button (`aria-expanded`) for the mobile menu.

- [ ] **Step 1: Write failing tests:** layout shows all five nav links in Arabic; menu toggle flips `aria-expanded`; `DataTable` renders rows, shows empty state for `rows=[]`, calls `onSearch`/`onEdit`/`onDelete`; `Modal` closes on Escape; `ConfirmDialog` calls `onConfirm`.
- [ ] **Step 2: Run** `npm test` → FAIL. **Step 3: Implement** with logical properties only. **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(frontend): admin layout and shared table/modal components`.

### Task 8: Resource hooks, four pages and overview

**Files:**
- Create: `frontend/src/features/Academic/api/resource.js`, `features/Academic/hooks/useResource.js`, `features/Academic/pages/{OverviewPage,AcademicYearsPage,GradeLevelsPage,ClassroomsPage,SubjectsPage}.jsx`
- Modify: `src/app/router.jsx` (routes under `/admin`), `src/i18n/{ar,en}.json` (`academic.*` titles, fields, `admin.overview.*`)
- Test: `frontend/tests/academicPages.test.jsx`

**Interfaces:**
- Produces: `makeResourceApi(path)` → `{list(params), create(body), update(id, body), remove(id)}` using `src/api/client.js`; `useResource(path)` → `{ list: (params) => useQuery, create/update/remove: useMutation that invalidate `[path]` }`; pages wire `DataTable` + `Modal` form + `ConfirmDialog`; server 422 `errors` map to `FormField error`. Classrooms page loads grade levels / years / teachers? (teacher list is out of scope: homeroom teacher field is omitted in the UI; API supports it). `OverviewPage` fetches `/admin/overview` and shows four count cards.

- [ ] **Step 1: Write failing tests** (mock `src/api/client.js`): overview renders the four counts; subjects page lists rows, create flow posts payload and refetches, 422 error shows under the field, delete flow confirms then calls `DELETE`, empty list shows empty state; same page renders English labels after `i18n.changeLanguage('en')`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `npm test`, `npx oxlint`, `npx vite build` → all pass.
- [ ] **Step 5: Commit** `feat(frontend): admin overview and school structure pages`.

### Task 9: End-to-end check and PR

- [ ] **Step 1:** `cd backend && php artisan test` and `cd frontend && npm test && npx vite build` → all green.
- [ ] **Step 2:** Run the dev server, screenshot `/admin` pages in Arabic desktop and English mobile with mocked API (as done for the landing page) and review layout; fix issues found.
- [ ] **Step 3:** `git push -u origin feature/admin-school-structure` and open a PR (check for a PR template first) summarizing backend + frontend.
