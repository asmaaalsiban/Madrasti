# Madrasati (مدرستي)

A multi-tenant, ClassDojo-style school platform: academic management + finance + parent/teacher/student engagement.
Sold to multiple schools. Each school gets its own subdomain and its own database.

Full product details: @docs/product-spec.md (read it before building or changing any module).
Lessons learned and handover notes from earlier sessions (user preferences, gotchas, bugs, PR state): @docs/CLAUDE-DENSE.md. Code wins over docs.

## Team
- 3 developers (backend, frontend, full-stack)

## Stack
- Backend: Laravel 11+ (REST API), Sanctum, MySQL/PostgreSQL, Laravel Queues
- Frontend: React 18+ (Vite), React Router, Axios + TanStack Query, Tailwind CSS + Shadcn UI
- Realtime: Laravel Reverb / Pusher (chat, notifications)
- Multi-tenancy: `stancl/tenancy` (database per tenant, tenant resolved by domain)
- Languages: Arabic (default) and English, full RTL support

## Roles
- `admin` (school management), `teacher`, `student`, `parent` (multi-child)
- Super Admin: central app only, manages schools (tenants)

## Modules
- School structure: academic years, grades, classes, subjects
- Attendance (students + staff) and Grades
- Smart scheduling: class/exam timetables and staff duty schedules (conflict-free, regenerate on demand)
- Teacher tools: lesson plan book, daily summary, behavior notes, homework
- Motivation (ClassDojo-style): points, badges, "star of the week"
- Communication: chat with read receipts, announcements, feeds/stories, notifications
- Surveys and parent approvals (e.g. trips, polls)
- Finance: tuition, installments, payments, alerts
- HR: staff attendance, hours, evaluation
- Procurement board, audit log
- Reports and CSV/Excel import/export, automated backups

## Architecture
- Central app: `tenants` + `domains` tables, Super Admin dashboard
- Super Admin area (`/super-admin`, API under `/api/central`): sign-in by password + emailed code; schools can be suspended, extended, limited by student count, and closed through an exit window. No code deletes school data (only the nightly backup rotation removes old backups)
- A school's state (active, expired, suspended, exit window, closed) is never stored: `App\Central\Support\SchoolStatus` works it out from the tenant row. The `EnsureSchoolActive` middleware blocks every non-active school on every request; use `SchoolStatus` for any new check
- Tenant app: all school features, runs inside the tenant database
- Central migrations and tenant migrations are kept separate
- Onboarding a school: one command creates DB, runs migrations, creates first admin, seeds defaults
- Frontend is one build for all schools; name, logo, colors come from the API by domain
- Mobile: responsive web / PWA first (no native app for now)
- Super Admin area (`/super-admin`, central API, own token): not covered by the automated screen sweep (the `tools/ui-check` tool, in open pull request #213 until it is merged); check its screens by hand

## Build order
1. Foundation: tenancy, auth + roles, school structure
2. Admin and academics: attendance, grades, timetables, smart scheduling
3. Engagement: chat, announcements, stories, badges, parent portal
4. Hardening: Excel import/export, backups, load testing, pilot

## Conventions
### General
- Code, comments, commit messages, and identifiers in English
- Never query across tenant databases from tenant code
- Every feature ships with tests (Pest/PHPUnit backend, Vitest frontend)
- Audit log entry for every create/update/delete on sensitive data (grades, finance, attendance)
- After every addition or change: commit, push to the working branch, and open a pull request, then give the user its link so they can merge it
- Whenever you give the user a branch or pull request link, also write the terminal commands they need to run (fetch/checkout or pull, then `npm install` and `npm run dev` for the frontend, `composer install` and migrations for the backend when needed), each command in its own code block
- Never force-push over a branch before checking its pull request is merged (`git merge-base --is-ancestor <branch> origin/main`). If it is not merged, keep its commits and add new ones on top. A force push once removed an unmerged commit (the handover notes) from the branch
- Before saying a screen change is done, open it in a real browser (Playwright, mocked API) and look at it, then say plainly what was not checked. A settings button once shipped that the user could not find

### i18n / RTL
- `react-i18next`; no hardcoded strings in components
- Translation keys grouped by module (e.g. `attendance.title`)
- CSS logical properties only (`ms-4`, `pe-2`, `margin-inline-start`), never `left`/`right`
- Set `dir` and `lang` on `<html>` from the selected language
- Check every screen in both RTL and LTR

### Backend
- API Resources for responses, Form Requests for validation
- Policies for authorization by role; a parent can only access their own children
- Heavy or async work (notifications, reports, scheduling) goes through Queues
- API and validation messages are translatable

### Frontend
- Functional components and hooks only
- One API client module; server state via TanStack Query
- Role-based routes and guards
- Parent and student screens are mobile-first

## Commands
<!-- Fill in once the repos are set up -->
- Backend: `php artisan serve`, `php artisan test`
- Frontend: `npm run dev`, `npm test`
- Tenant migrations: `php artisan tenants:migrate`
- Create the first Super Admin: `php artisan super-admin:create <email> [--name=] [--password=]`
