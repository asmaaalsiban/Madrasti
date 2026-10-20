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
php artisan school:create school2 "School 2" --admin-email=admin@school2.test --admin-password=Admin12345
php artisan school:seed-demo school2     # demo admin, teachers, year, grades, subjects, classrooms (APP_ENV=local only)
php artisan serve

cd ../frontend
npm install
npm run dev
```

Open `http://localhost:<vite port>`, choose **Sign in**, type the school code `school2`, then sign in with
`admin@school2.test` / `Admin12345`. The URL never changes: use **Switch school** to enter another school's code.

## One public URL, many schools

The site has a single URL. Every school has its own database; the school is chosen **inside** the app by its code and sent
to the API in the `X-School` header (the code is also the tenant id). A token is looked up in that school's own database,
so a token from one school never works in another.

- No school selected: a welcome page with **Create account** (`/register`) and **Sign in** (`/find-school`).
  - **Create account** registers a new school (`POST /api/central/register`: public, 5 per hour per IP), creates its
    database and first admin, signs the admin in and opens the admin area.
  - **Sign in** asks for the school code, checks it exists, then opens that school's login page.
- School selected: the school's landing page, login and role areas. **Switch school** forgets the selection (each school keeps
  its own sign-in in the browser) and returns to the welcome page.
- Production: serve the frontend and `/api` from the same domain (reverse proxy) and add that domain to `CENTRAL_DOMAINS`
  so `/api/central/*` (registration) is reachable. No wildcard DNS is needed.

Settings (backend `.env`):

| Variable | Default | Purpose |
|---|---|---|
| `REGISTRATION_ENABLED` | `false` (`true` in `.env.example`) | turns public registration on/off; off unless set |
| `REGISTRATION_DAILY_LIMIT` | `50` | max registrations per day across all IPs |
| `TRUSTED_PROXIES` | none (`*` when `APP_ENV=local`) | IPs/CIDRs of your load balancer; without it `X-Forwarded-For` is ignored so IP limits cannot be spoofed |
| `CENTRAL_DOMAINS` | `127.0.0.1,localhost` | hosts that serve `/api/central/*` |

Before opening registration to the internet add email verification and a CAPTCHA.

## Status

Phase 1 (Foundation): folder structure and migrations are in place.
Laravel and Vite apps are not installed yet (`composer create-project` / `npm create vite`).
