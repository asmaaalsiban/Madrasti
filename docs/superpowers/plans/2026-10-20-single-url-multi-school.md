# One public URL for every school: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** One fixed URL; the school is chosen by code inside the app and sent as `X-School`.
**Spec:** `docs/superpowers/specs/2026-10-20-single-url-multi-school-design.md`

## Global Constraints
- Header name `X-School`; localStorage keys `school` and `token:<code>`; code regex `^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$`.
- No hardcoded UI strings (`central.*` keys in `ar.json`/`en.json`); logical CSS only; API through `/api`.
- Branch `feature/single-url-multi-school`; PR at the end; commits end with the session attribution lines.

## Review Focus
- Token of school A used with `X-School: B`: rejected (token is looked up in B's database): covered by design, verified in review.
- Missing/unknown school header: 404 JSON on every tenant route (Task 1).
- Switching school never shows data of the previous school (page reload, Task 3).
- Invalid/uppercase/space-padded code typed in find-school (Task 3).

### Task 1: Backend
Files: `routes/tenant.php`, `app/Providers/TenancyServiceProvider.php`, `app/Central/Actions/CreateSchool.php`, `app/Central/Http/Requests/RegisterSchoolRequest.php`, `app/Central/Http/Controllers/RegisterSchoolController.php`, `app/Central/Console/CreateSchoolCommand.php`, `app/Central/Console/ListSchoolsCommand.php`, `config/tenancy.php`, `.env.example`; tests: `tests/Feature/Tenancy/SchoolHeaderTest.php`, update `AuthTest`, `AcademicTestCase`, `RegisterSchoolTest`, `CreateSchoolCleanupTest`.
- [ ] RED: tests for 404 without/with unknown `X-School` on `GET /api/school`; register returns `{data:{code}}` and calls the action without a domain; `CreateSchool` with no domain creates none.
- [ ] GREEN: implement per spec; update existing tests' `withoutMiddleware` to `InitializeTenancyByRequestData`.
- [ ] `php artisan test`, pint on touched files; commit.

### Task 2: Frontend core
Files: `src/tenant/school.js`, `src/api/client.js`, `src/features/Auth/AuthProvider.jsx` (token via client helpers), remove `src/features/Central/host.js` (move `isValidSchoolCode`/`normalizeSchoolCode` to `src/tenant/school.js`), `src/app/router.jsx`; tests: `tests/school.test.js`, `tests/apiClient.test.js`, update `centralPages.test.jsx`.
- [ ] RED then GREEN: `getSchool/setSchool/clearSchool`; client adds `X-School` and scopes the token per school; `routesFor(school)` returns central routes when `school` is null.
- [ ] Commit.

### Task 3: Pages and Switch school
Files: `src/features/Central/{FindSchoolPage,RegisterSchoolPage,WelcomePage}.jsx`, `src/components/shared/SwitchSchoolButton.jsx`, `LandingPage`, `LoginPage`, `AdminLayout`, `DashboardLayout`, i18n; tests in `centralPages.test.jsx`, `sharedComponents.test.jsx`.
- [ ] RED then GREEN for find-school (valid/unknown/invalid), register (select school, sign in, reload), SwitchSchoolButton (clears and reloads), labels in both languages.
- [ ] `npm test`, `npx oxlint`, `npx vite build`; commit.

### Task 4: Docs, review, PR
- [ ] README: single URL flow, remove `TENANCY_BASE_DOMAIN`/`VITE_*` rows; `docs/project-structure.md`.
- [ ] Whole-branch fresh review; fix Critical/Important test-first; push; open PR.
