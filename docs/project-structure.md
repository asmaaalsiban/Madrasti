# Madrasati - Project Structure

## Backend (`backend/`, Laravel 11+)

```
app/
  Central/            # Central app only: Tenant/Domain models, Super Admin, onboarding command
  Modules/<Module>/   # Tenant app, one folder per module
    Models/ Http/{Controllers,Requests,Resources}/ Policies/ Actions/ Jobs/
  Shared/             # Traits (Auditable), Enums (Role), helpers
routes/
  central/            # Super Admin API (central domain)
  tenant/             # School API (resolved by domain via stancl/tenancy)
database/
  migrations/central/ # tenants, domains, super admins
  migrations/tenant/  # everything inside a school database
  seeders/tenant/     # default roles, subjects, settings
lang/{ar,en}/         # translatable API + validation messages
tests/{Feature,Unit}/Tenant
```

Modules: Academic, Attendance, Grading, Scheduling, TeacherTools, Motivation,
Communication, Surveys, Finance, HR, Procurement, Audit, Reports, Auth.

Rules (from CLAUDE.md): API Resources for responses, Form Requests for validation,
Policies per role (a parent only sees own children), heavy work through Queues,
audit log on create/update/delete of grades, finance, attendance.

## Frontend (`frontend/`, React + Vite)

```
src/
  api/          # single Axios client + TanStack Query setup
  app/          # router, providers, role-based routes
  guards/       # RequireAuth, RequireRole
  layouts/      # AdminLayout, TeacherLayout, StudentLayout, ParentLayout
  features/<Module>/{api,components,hooks,pages}
  pages/{auth,admin,teacher,student,parent}   # role dashboards
  components/{ui,shared}                       # Shadcn UI + shared parts
  i18n/locales/{ar,en}                         # keys grouped by module
  features/Central/  # main-domain site: WelcomePage, RegisterSchoolPage, FindSchoolPage, host helpers
  tenant/       # loads school name/logo/colors from API by domain
```

Rules: functional components only, no hardcoded strings, logical CSS properties
only (RTL), parent/student screens mobile-first.
