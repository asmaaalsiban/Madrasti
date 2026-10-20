# Madrasati (مدرستي)

Multi-tenant school platform (ClassDojo-style): academics, finance, and parent/teacher/student engagement.
Each school gets its own subdomain and its own database.

- Backend: Laravel 11+ REST API, Sanctum, `stancl/tenancy` (database per tenant)
- Frontend: React 18 (Vite), TanStack Query, Tailwind CSS + Shadcn UI
- Languages: Arabic (default) and English, full RTL
- Roles: `admin`, `teacher`, `student`, `parent` (multi-child); Super Admin in the central app

## Repository layout

```
backend/     Laravel API (central + tenant)
frontend/    React SPA
docs/        Project structure and specs
CLAUDE.md    Team conventions
```

See [docs/project-structure.md](docs/project-structure.md) and [docs/database.md](docs/database.md).

## Database

- `backend/database/migrations/central/` - tenants, domains, super admins
- `backend/database/migrations/tenant/` - everything inside a school database (11 files)

```
php artisan migrate --path=database/migrations/central   # central
php artisan tenants:migrate         # all schools
```

## Local demo (MySQL)

```
cd backend
copy .env.example .env          # then set DB_PORT / DB_PASSWORD, and run: php artisan key:generate
php artisan migrate --path=database/migrations/central
php artisan school:create school2 "School 2" school2.localhost --admin-email=admin@school2.test --admin-password=Admin12345
php artisan school:seed-demo school2     # demo admin, teachers, year, grades, subjects, classrooms (APP_ENV=local only)
php artisan serve

cd ../frontend
npm install
npm run dev -- --host
```

Open `http://school2.localhost:<vite port>` (not `localhost`: the school is resolved from the host name)
and sign in with `admin@school2.test` / `Admin12345`.

## Central site (main domain)

On the main domain (`localhost`, `127.0.0.1`) the app shows a welcome page:

- **Create account** (`/register`): registers a new school. Calls `POST /api/central/register` (public, 5 per hour per IP),
  which creates the school, its database and its first admin, then redirects to `<code>.<base domain>/login`.
- **Sign in** (`/find-school`): the user types the school code and is sent to that school's login page.

On a school domain (`school2.localhost`) the app shows that school's landing page and login, as before.

Settings:

| Where | Variable | Default | Purpose |
|---|---|---|---|
| backend `.env` | `TENANCY_BASE_DOMAIN` | `localhost` | schools live at `<code>.<base domain>` |
| backend `.env` | `REGISTRATION_ENABLED` | `true` | turns public registration on/off |
| frontend `.env` | `VITE_CENTRAL_HOSTS` | `localhost,127.0.0.1` | hosts that show the central site |
| frontend `.env` | `VITE_BASE_DOMAIN` | `localhost` | base domain used to build school links |

Before opening registration to the internet add email verification and a CAPTCHA.

## Status

Phase 1 (Foundation): folder structure and migrations are in place.
Laravel and Vite apps are not installed yet (`composer create-project` / `npm create vite`).
