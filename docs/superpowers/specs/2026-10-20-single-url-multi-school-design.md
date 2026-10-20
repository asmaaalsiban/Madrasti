# One public URL for every school

## Intent
The site has one fixed public URL. Every school keeps its own database, but the school is chosen **inside** the app (by school code) instead of by sub-domain, so adding a school never changes the URL. Replaces the per-school sub-domain flow of the central welcome feature.

## Decisions
- The school is identified by the request header `X-School: <code>` (stancl `InitializeTenancyByRequestData`, header `X-School`, no query parameter). Domains are no longer needed for schools.
- Security: a Sanctum token is looked up in the school's own database, so a token from school A is rejected by school B even if the header is changed. Unknown or missing code: 404 `tenancy.school_not_found`.
- The central API (`/api/central/*`, bound to `CENTRAL_DOMAINS`) is unchanged.

## Backend
- `routes/tenant.php`: middleware `['api', InitializeTenancyByRequestData::class]`; remove `PreventAccessFromCentralDomains` and domain initialization. `TenancyServiceProvider` sets `InitializeTenancyByRequestData::$header = 'X-School'` and `$queryParameter = null`.
- `CreateSchool`: `domain` becomes optional (created only when given).
- `POST /api/central/register`: no domain, no `tenancy.base_domain`; validation of the code is unchanged (regex, reserved, unique tenant id). Response: `201 {data: {code}}`. The client then signs in with `POST /api/auth/login` (header `X-School`).
- `school:create`: `domain` argument optional. `school:list` shows `-` when a school has no domain. Remove `TENANCY_BASE_DOMAIN`.

## Frontend
- `src/tenant/school.js`: `getSchool()`, `setSchool(code)`, `clearSchool()` (localStorage `school`).
- `src/api/client.js`: base URL `/api` (same origin through the Vite proxy / production proxy); sends `X-School` when a school is selected; the auth token is stored per school (`token:<code>`).
- Router: no school selected → central routes (`/` welcome, `/register`, `/find-school`); school selected → the school routes (landing, login, role areas). Changing the school reloads the page (`window.location.assign('/')`) so no data leaks between schools.
- `FindSchoolPage`: validates the code format, selects it, checks `GET /school`; unknown school shows `central.schoolNotFound` and clears the selection; success goes to `/login`.
- `RegisterSchoolPage`: on success selects the school, signs the admin in with the form's email/password, and opens the admin area.
- **Switch school** button in the school landing, login page, admin layout and dashboard layout: `clearSchool()` then reload `/` (tokens of other schools are kept).
- Remove `VITE_CENTRAL_HOSTS`, `VITE_BASE_DOMAIN`, host detection and the production host warning.

## Out of scope
List of the user's schools, email verification, CAPTCHA, provisioning through a queue.

## Tests
- Backend: tenant routes answer 404 `tenancy.school_not_found` without the header and with an unknown code; `CreateSchool` without a domain creates no domain row; registration no longer needs/returns a domain; commands without a domain.
- Frontend: school store; client sends `X-School` and uses a per-school token; routes depend on the selected school; find-school success/unknown/invalid code; register selects the school and signs in; Switch school clears the selection and reloads; Arabic and English labels.
