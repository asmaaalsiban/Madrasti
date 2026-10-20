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
- Production: serve the frontend and `/api` from the same domain (reverse proxy). No sub-domains or wildcard DNS are needed.
- School codes are 3-30 lowercase letters, digits or hyphens (the same rule for the website, `school:create` and the Super Admin API).

Settings (backend `.env`):

| Variable | Default | Purpose |
|---|---|---|
| `REGISTRATION_ENABLED` | `false` (`true` in `.env.example`) | turns public registration on/off; off unless set |
| `REGISTRATION_DAILY_LIMIT` | `50` | max registrations per day across all IPs |
| `TRUSTED_PROXIES` | none (`*` when `APP_ENV=local`) | IPs/CIDRs of your load balancer; without it `X-Forwarded-For` is ignored so IP limits cannot be spoofed |

Before opening registration to the internet add email verification and a CAPTCHA.

## Staff, students and parents

The admin creates the accounts (admin menu: **Staff**, **Students**).

- **Staff**: one page with a tab per category (teaching, administrative, student affairs, health, technical, services). Each person has a
  type (teacher, head of department, principal, nurse, driver, ...). Teaching types have one subject; floor supervisors have a floor.
  Principal, vice principal and general manager get the `admin` role, everyone else the `teacher` role (finer permissions come with each module).
- **Students**: the admin does not type the student's email. It is generated as `<english first name>.<student number>@<school code>.edu`
  (e.g. `ahmad.d1001@school2.edu`; `d1001@school2.edu` without an English name). Set `STUDENT_EMAIL_DOMAIN` to use another domain.
  After saving, the admin sees the email and password once. The admin also enters the parent's email, name and phone
  (a parent with several children gets one account). A parent sees each child's sign-in email on the parent home page.

Every new account gets the **default password** (`DEFAULT_ACCOUNT_PASSWORD`, `123456789` unless changed in the backend `.env`) and is sent to
**Change password** at the first sign-in; the default password can never be chosen again. Users can change their password but not their email.
The admin can reset a staff member, student or parent to the default password from the same screens.

After updating an existing install run `php artisan tenants:migrate` once (adds `staff_profiles.staff_type/subject_id/floor`; existing teachers become staff of type teacher).
`school:seed-demo` also adds demo staff (`teacher1@<school>.test`, `nurse1@...`) and four students with parents (`parent1@<school>.test` ..., default password).

## Attendance

The homeroom teacher of a classroom (or an admin) records the day: **Take attendance** lists the students, everybody starts *present*,
and the teacher only taps **Late** (yellow) or **Absent** (red) for the exceptions, then **Save**. Saving again the same day corrects it.
Admins can record any classroom of the current academic year.

- When a student is saved as absent, the parents' phones get a message ("your child ... was absent today"). It goes through the
  queue and is recorded per parent in `absence_notifications` (sent / failed / skipped when the parent has no phone); a parent never gets the
  same absence twice, and failed ones are tried again on the next save.
- The provider is chosen with `MESSAGING_DRIVER`. Only `log` (writes the message to the Laravel log) exists for now. To add WhatsApp, Twilio, SMS...
  create a class implementing `App\Shared\Messaging\MessageSender`, add it to `config/messaging.php` and set the driver.
  Remember to run a queue worker (`php artisan queue:work`) when `QUEUE_CONNECTION` is not `sync`.
- **Absence report** (admin and teacher): absent/late days per student for the academic year or a date range; click a number to see the dates.
  Parents see their child's totals and dates on their home page.

After updating run `php artisan tenants:migrate` (adds `absence_notifications`).

## Status

Phase 1 (Foundation): folder structure and migrations are in place.
Laravel and Vite apps are not installed yet (`composer create-project` / `npm create vite`).
