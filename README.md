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
  (a parent with several children gets one account: type the same email again, or search "Find an existing parent" by name, phone or email and pick them). A parent sees each child's sign-in email on the parent home page.

Every new account gets the **default password** (`DEFAULT_ACCOUNT_PASSWORD`, `123456789` unless changed in the backend `.env`) and is sent to
**Change password** at the first sign-in; the default password can never be chosen again. Users can change their password but not their email.
The admin can reset a staff member, student or parent to the default password from the same screens.

After updating an existing install run `php artisan tenants:migrate` once (adds `staff_profiles.staff_type/subject_id/floor`; existing teachers become staff of type teacher).
`school:seed-demo` adds demo staff: per subject one head of department (`head1@<school>.test` ... `head8@...`) and three teachers (`teacher1@...` ... `teacher24@...`), plus `nurse1@...`. Every classroom gets its own homeroom teacher and every subject is spread over its three teachers in all classrooms. It also adds ten students in every classroom (120, generated names, siblings share a parent `parentN@<school>.test`; default password). It also adds graded assessments: every subject in every classroom has a quiz, an assignment and a final exam in the first term (100%) and a quiz in the second, all published, with a score for every student. It is safe to run again: it only adds what is missing.

## Buildings, floors and rooms

Admin menu **Buildings**: a school can have several buildings (e.g. boys, girls, secondary), each marked for boys, girls or mixed. A building has floors (level 0 = ground floor); a floor has its own gender (or the building's), the **range of grades** it serves (e.g. grade 6 to 10) and **how many classrooms, science labs and computer labs** it has. The rooms are created from these numbers with generated names (classrooms `001`, `102`...; labs `SL-0-1`, `CL-1-2`) which can be renamed (`PUT /api/rooms/{id}`). Raising a number adds rooms; lowering it removes only unused rooms (a room where a classroom stays, or that a timetable uses, is never removed: the change is refused as a whole). The next step generates the classrooms (sections) of each grade and places them in these rooms.

## Generating the classrooms

`php artisan school:seed-grades <school>` adds the standard levels in order (buds, KG1, KG2, grades 1 to 12 where 12 is the Tawjihi; an existing level keeps its name and only gets its code and place). Then admin menu **Classrooms > Generate classrooms**: for every grade type how many *mixed*, *boys* and *girls* sections it has, press **Preview**, then **Create classrooms**. Sections are named `<code>-<letter>` (`KG1-A`, `4-B`) and each is placed in a free classroom of a floor that serves its grade and fits its gender (a floor of the exact gender is preferred over a mixed one). Sections that find no room are still created and listed so they can be placed by hand (the classroom form has **Students** and **Room** fields; a room hosts one classroom per academic year). Only what is missing is created, so running it again adds nothing; a section without a gender counts as mixed.

## Curriculum

Admin menu **Curriculum**: a grid with the subjects as rows and the grades as columns; every box is the number of periods per week (empty = the grade does not have the subject). The bottom row totals each grade against the periods of the week (default 35 = 7 periods x 5 days, editable) and says *Complete*, *Missing n* or *Over by n*. **Copy a grade** gives other grades the same subjects, and **Apply the standard plan (grades 1-10)** adds the standard subjects and fills grades 1 to 10: Arabic, English and maths every day, one science subject until grade 8 then physics, chemistry, biology and earth science (2 periods each), social studies until grade 6 then history, geography and national education from grade 7, plus Islamic education, computer, PE, financial literacy, vocational, art, music and activities, 35 periods in every grade. It is a template (the Ministry's official table was not found), so edit it to the school's own plan; kindergarten and the secondary grades are left alone. Once a grade has a curriculum, teacher assignment only accepts its subjects.

## Teachers for the curriculum

`php artisan school:seed-teachers <school> [--load=24]` (local only) creates the demo teachers the curriculum needs: for every subject as many teachers as its periods require, counted per building (each building has its own teachers), at a weekly load of 24 periods; teachers that exist already count, so it can be repeated. Then **Teacher assignment > Distribute automatically** (preview, then apply) gives every subject of every classroom (current year, from the curriculum) a teacher where there is none: the teacher teaches that subject, is active and has room in the weekly load; **a teacher stays in one building** (the building of their first classroom), so nobody walks between the girls' and the boys' building in the five minutes between two periods (the timetable will also refuse two consecutive periods in different buildings); teachers are filled one after another so a teacher takes as many sections as fit and as few teachers as possible are used; existing assignments are kept and counted. Anything that cannot be assigned is listed with how many more teachers of that subject are needed.

## School day and timetable

Admin menu **School day**: the school days (Sunday to Thursday by default), periods a day (7), when the first starts (07:45), the length of a period (45 min), the break between periods (5 min) and an optional longer break after one period (e.g. 20 min after the 3rd); a preview shows the bells of a day. Changing it updates the periods; a period that the timetable uses is never removed.

Admin menu **Timetable**: **Generate the timetable** builds the week of every section of the current year from the teacher assignments (the curriculum's periods): preview (nothing saved; shows how many lessons can be placed and what could not, and the subjects that have no teacher), then apply (replaces the unlocked lessons; the run is recorded in `schedule_generations`). Hard rules: a section has one lesson per period; a teacher is in one place at a time and never in a period they are unavailable (`teacher_unavailabilities`); **a teacher never has two consecutive periods in different buildings**; a subject has at most ceil(periods / days) lessons a day (5 periods in 5 days means one every day: Arabic, English and maths every day). Soft rules: subjects spread over the week, core subjects in the morning, a teacher's lessons spread over the days. It is a constructive search with a repair step and up to 80 attempts; the same seed gives the same timetable; locked lessons are kept. The grid can be read by classroom or by teacher. **Periods a day per grade:** on the School day page every grade can have its own number of periods a day (empty = the whole school day); grades 1-3 and the kindergarten usually have 6, so 30 lessons a week. The curriculum compares each grade with its own week, the standard plan gives grades 1-3 a 6-period day and 30 periods, `school:seed-grades` sets 6 for the buds, KG1, KG2 and grades 1-3 (a value the school chose is kept), and a section never gets a lesson after its last period. After generating, sections whose curriculum is shorter than their week are listed ("1-A: 28 lessons for 30 periods (2 empty)"), and empty cells are marked on the grid.

**Who sees the timetable:** the teacher (**My timetable**: the section they teach in every period, today's column highlighted), the student (the week of their section with the teacher of every lesson) and the parent (a **Timetable** link on each child). 

**Editing by hand:** in the admin **Timetable** (by classroom) click a cell to put a subject there (`PUT /api/timetable/entries`; the teacher comes from the teacher assignment of the section, the room from the section), lock or unlock it (`PUT /api/timetable/entries/{id}/lock`) or empty it (`DELETE /api/timetable/entries/{id}`). A lesson placed by hand is locked, so generating again keeps it. The same hard rules as the generator apply and a refusal says why: the teacher is already teaching another section (named) in that period, has a lesson in another building right before or after, is unavailable, the section has no such period (grades 1-3 have 6) or the subject has no teacher in the section. **Teacher availability** (admin menu): tick the periods a teacher cannot teach in (`GET/PUT /api/teachers/{id}/unavailability`); the generator and hand edits keep away from them, and lessons already placed in those periods are listed after saving.

## Whom a teacher teaches

On the Staff form a teacher has **Teaches from grade / to grade** (empty = every grade, e.g. basic 1-3, preparatory, secondary) and **Teaches students** (boys, girls, empty = both). The automatic distribution and manual assignment only use teachers who may teach the section: its grade is inside their grades, and its students are theirs (a girls-only teacher gets girls' sections only; a mixed section needs a teacher for both). The timetable is built from the assignments, so nobody is ever put outside their scope.

## Sections a teacher teaches

On the Staff form, **Sections the teacher teaches** lets the admin tick the exact sections of a teacher (e.g. a head of department with one or two). Empty = any section that fits their grades and students. When sections are chosen the teacher teaches only those: manual assignment to another section is refused, the automatic distribution never uses them elsewhere, and it gives a teacher their chosen section before anybody else.

## Terms and teacher assignment

Admin menu **Terms**: the terms of an academic year (inside the year, no overlap). Admin menu **Teacher assignment**: who teaches which subject in which classroom (one teacher per subject per classroom; the teacher must be an active teaching staff member of that subject). Grades are entered per term, by the assigned teacher, so both come first.

## Grades (teacher side)

The teacher of a subject in a classroom (see *Teacher assignment*) opens **Grades**, picks classroom + subject and the term, and creates assessments (quiz, exam, assignment, project, participation) with a maximum score and a weight (the weights of one subject in one term cannot exceed 100%). **Enter scores** shows every student of the classroom; an empty box means "not graded"; scores cannot exceed the maximum. An assessment is a **draft** until the teacher presses **Publish**; only published ones will be visible to students and parents (next step). A published assessment cannot be deleted. Next to every score the sheet shows **Pass / Fail** (at least 50% of the maximum passes; `GRADING_PASS_PERCENT`). A student who missed the assessment is marked **Absent (makeup exam pending)**: no score, not counted as a zero, and the list reminds the teacher how many are pending; when the makeup exam is taken the teacher changes the status to **Makeup exam** and enters the score. The sheet also hints when the attendance says the student was absent that day. **Student results** shows each student's weighted percentage in the subject and term with Pass / Fail. Admins can do the same for any classroom. Every change is in the audit log.

## Grades (student and parent side)

Only **published** assessments are visible. A **student** sees their grades on their home page; a **parent** opens **Grades** on the card of each child. Grades are shown by term and subject: every subject has a result: **Pass**, **Fail**, **waiting for the makeup exam** (the student was absent), **waiting for the retake exam** or **no grades yet**; **Show details** lists the assessments with the score out of the maximum. The percentage is weighted (see above) and the pass mark is `GRADING_PASS_PERCENT` (50). The teacher can choose **Retake (failed the exam)** for a student who failed: the failing score is kept as the *first attempt*, the exam waits for the new score, and the new score counts. The teacher's private remarks are never shown to students and parents.

## Heads of department

A staff member of type *head of department* has a **My department** page (teacher home): the teachers of the same subject, the classrooms each one teaches, their homeroom classroom and whether its attendance was recorded today / on how many of the last 30 days.

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

## Behaviour evaluation

Teacher menu (and admin menu) **Behaviour**: pick a section and a date, then **Evaluate** each student for that week. A teacher evaluates the students of the sections they teach or are homeroom of; an admin anyone. An evaluation is a score from 1 to 5 for each **criterion** (or empty = not applicable), a note, and whether the parent sees the note; it is saved with the date (default today, never in the future) and belongs to the week (Sunday to Saturday) of that date. Evaluating the same student again in the same week replaces the teacher's evaluation (`PUT /api/behavior/evaluations`, `GET /api/behavior/week`).

**Everything is built on the weeks** (`BehaviorSummary`): a **week** is the mean of all teachers' evaluations of that week per criterion; a **month** is the mean of its weeks (the row shows how many weeks it has); a **term** is the mean of its months (terms come from the school's terms). Each table also has an overall mean. Results: `GET /api/students/{id}/behavior`, shown to the student (**Behaviour** on their home), to each parent per child, and to the teachers who evaluate the student and admins (gate `view-student-behavior`); parents and students see only remarks and notes marked visible.

**Criteria** (admin, **Behaviour criteria**): a new school starts with 7 (discipline, respect, participation, cooperation, responsibility, cleanliness and care of property, honesty). The admin adds their own (Arabic and English name), hides or shows one, or removes it; a criterion already used is only hidden so old results stay (`/api/behavior/criteria`). The migration adds the tables and the default criteria, so run `php artisan tenants:migrate`.

**Quick notes** for single events (positive or negative, e.g. "helped a friend", "hit a classmate") are added from the same dialog with the date and an option to hide them from the parent; the author or an admin can delete them (`POST /api/behavior/notes`, `DELETE /api/behavior/notes/{id}`).
## Homework

Teacher menu **Homework** (teachers and heads of department; **not** the admin, who does not send homework): send homework to a section for a subject the teacher teaches there (title, details, due date, optional photo); edit or delete it. A teacher sees and changes only their own homework. The section must have the subject assigned (`POST /api/homework`, `PUT/DELETE /api/homework/{id}`, `GET /api/homework`). The student sees the homework of their section (`GET /api/homework/me`, **Homework** link on the student home) and each parent sees it per child (`GET /api/students/{id}/homework`, **Homework** link on the child's card); a gate (`view-student-homework`) limits it to the student, their parents and admins. A homework can have **photos** (of the page or the task; JPG, PNG or WebP up to 5 MB each, up to 10): `POST /api/homework/{id}/images` always **adds** to the photos already there (never replaces them) and `DELETE /api/homework/files/{id}` removes one. They are stored privately (per school, table `homework_files`) and opened with `GET /api/homework/files/{id}` by the teacher, admins, and the students of the section and their parents. Uploading answers comes later.

**Student answers:** on the student's Homework page each homework has **Send my answer**: a text and/or **photos of the pages** (a clear camera button; on a phone it opens the camera or gallery; up to 10 pages). New photos are **added** to the pages already sent, so page 1 can be sent now and page 2 later; `remove_file_ids[]` removes chosen pages. Sending again updates the text, a student can delete the whole answer, and an answer after the due date is marked late (`POST|DELETE /api/homework/{id}/submission`, only for a student of the section and a published homework). **Answers** on the teacher's homework (`GET /api/homework/{id}/submissions`) lists every student of the section: who sent (with the text and every page, `GET /api/homework/files/{id}`) and who did not. The photo is private: the teacher of the homework, the student and their parents. A parent sees whether the child has sent the answer. Deleting a homework deletes its answers and photos. The migration moves any photo saved before into `homework_files`, so run `php artisan tenants:migrate`.

## Daily summary

Teacher menu **Daily summary**: after a lesson the teacher picks the section and subject (from their teaching assignments) and the day (default today, never in the future) and writes what was covered. Writing again for the same section, subject and day replaces it (one summary per section, subject and day); the teacher sees their previous summaries and can delete them (`PUT /api/daily-summaries`, `GET /api/daily-summaries`, `DELETE /api/daily-summaries/{id}`). It is published at once: the student reads the summaries of their section grouped by day (**Daily summary** on their home, `GET /api/daily-summaries/me`) and each parent per child (`GET /api/students/{id}/daily-summaries`). Only the teacher of that subject in that section can write it. Uses the existing `daily_summaries` table, no migration.

## Lesson plans

Teacher menu **Lesson plans**: the teacher picks the section and subject (from their assignments) and a day of the week, and writes the plan of that week (title, objectives, plan) as a **draft**; the week starts on Sunday and there is one plan per teacher, section, subject and week (writing again changes the draft). The teacher **submits** it for approval (`PUT /api/lesson-plans`, `POST /api/lesson-plans/{id}/submit`, `GET`, `DELETE`). **The head of department approves, not the admin:** a head of department sees (**Lesson plans to review** on their home) the submitted plans of the teachers of their own subject (`GET /api/lesson-plans/review`) and **approves** them or **rejects them with a note** (`POST /api/lesson-plans/{id}/review`); they cannot review their own plan, and plans of other departments are refused. A head's own plan, and the plan of a teacher whose subject has no head of department, are approved when submitted; a rejected plan returns to the teacher with the note, who fixes it and submits again. **Editing after sending:** the teacher can **edit** any of their plans (`PUT /api/lesson-plans` for the same section, subject and week). Changing a plan that was submitted or approved takes it back to a **draft** (an approved plan disappears from students and parents until it is approved again; the screen warns before saving). A plan that is waiting for review can be **withdrawn** (`POST /api/lesson-plans/{id}/withdraw`) and any plan can be **deleted** by its teacher. An **approved plan that the teacher chose to share** is read by the section's students (**Lesson plans** on their home, `GET /api/lesson-plans/me`) and by each parent per child (`GET /api/students/{id}/lesson-plans`); status and review notes stay private. Uses the existing `lesson_plans` table, no migration.

## Forgot my password

On the sign-in page, **Forgot my password**: the user types their email or phone, we e-mail a **6-digit code** (valid 15 minutes, 5 wrong tries, one use; stored hashed in `password_reset_codes`), then they type the code and a new password (`POST /api/auth/forgot-password`, `POST /api/auth/reset-password`). **A student's code goes to the parent's e-mail** (the primary parent, else any parent with an e-mail) and the parent gives it to the child; teachers, parents and admins get it on their own e-mail. The answer is always the same whether or not the account exists or an e-mail went out (no account guessing); a disabled account, an unknown one and a student whose parents have no e-mail get nothing, and for them the admin can still reset the password from the Students page. Setting the password signs the user out of every device. Limits: 5 requests a minute per login and IP (20 per IP), 10 code tries a minute.

**E-mail settings** (`backend/.env`): in development `MAIL_MAILER=log`, so the e-mail with the code is written to `storage/logs/laravel.log`. For real e-mails use SMTP, for example Gmail with an App Password: `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_USERNAME=<address>`, `MAIL_PASSWORD=<app password>`, `MAIL_ENCRYPTION=tls`, `MAIL_FROM_ADDRESS=<address>`. Run `php artisan tenants:migrate` for the new table.


## Announcements

Everyone has **Announcements** (a link in the header, and in the admin menu) with a counter of unread ones, checked every 30 seconds (`GET /api/announcements/unread-count`). The list (`GET /api/announcements`) shows the announcements meant for the user, pinned first then newest, new ones marked; opening one marks it read (`POST /api/announcements/{id}/read`).

**Who sees what:** *everyone*; *a group* (all teachers, students, parents or admins); *a section*: the students of that section, their parents (a parent with children in two sections sees both), and the teachers who teach or are homeroom of it. Expired announcements (optional "show until" date) are hidden; an author always sees their own; the admin sees all.

**Who publishes:** the admin to everyone, a group or any section, and can pin to the top; a teacher only to a section they teach (`POST /api/announcements`, `PUT` and `DELETE` by the author or an admin; `GET /api/announcements/options` lists the sections a user may publish to). Students and parents only read. Uses the existing `announcements` table plus the new `announcement_reads`: run `php artisan tenants:migrate`.

## Notifications (the bell)

A bell 🔔 in the header (and the admin menu) shows how many notifications the user has not opened, checked every 30 seconds; it lists the latest 30, opening one marks it read and goes to its page, and "Mark all as read" clears the counter (`GET /api/notifications`, `GET /api/notifications/unread-count`, `POST /api/notifications/{id}/read`, `POST /api/notifications/read-all`). Each user sees only their own. They are stored with Laravel's `notifications` table as a **kind + parameters + link** and the app writes the sentence in the reader's language.

**What rings the bell** (`Notifier`; a failure here never breaks the action that caused it): new **homework** and a new **daily summary** (the section's students and each student's parents, with the child's name for a parent), **grades published** (once, not again for an already published assessment), an **approved and shared lesson plan** (section), a **lesson plan submitted** (the head of department), a plan **approved or rejected** (the teacher, with the reason), and a student's **first answer to a homework** (its teacher). Announcements keep their own counter. No migration (the table already exists); real-time push (Reverb) can replace the 30-second check later.

## Notifications on the phone (web push / PWA)

The bell's events also reach the phone or computer of people who allowed it, **even when the site is closed**, as a normal notification (tapping it opens the right page). The site is installable as an app (PWA: `manifest.webmanifest`, `sw.js`). In the bell, **Get notifications on this phone** asks the browser's permission and subscribes that device (`GET /api/push/config`, `POST /api/push/subscribe`, `POST /api/push/unsubscribe`); one person can have several devices, and a device that signs in as someone else is moved to them. Each message is written in the person's language (`lang/*/push.php`). A subscription the push service reports gone is deleted, and a push failure never breaks the action that caused it.

**Set up once** (free, no provider): in `backend` run `php artisan push:generate-keys`, add the three `VAPID_*` lines it prints to `.env`, then `php artisan config:clear`. On **Windows/XAMPP** the command looks for `openssl.cnf` itself; if it still says it could not create the keys, run it with the path: `php artisan push:generate-keys --openssl-config=C:\xampp\php\extras\openssl\openssl.cnf`. Without keys the button is hidden and nothing changes. Do not change the keys later (everyone would have to enable it again). Run `php artisan tenants:migrate` for the new table.

**Where it works:** Android (Chrome, Edge, Firefox, Samsung) and computers directly; **iPhone (iOS 16.4+) only after "Add to Home Screen"** (the bell explains this). The site must be served over **HTTPS** in production (`localhost` works for testing). Announcements keep their own counter and do not push yet.

## Finance (fees, installments, payments)

**The accountant** is a staff member of type **Accountant** (add them in Staff): their home shows **Finance** instead of the teacher tools. They manage the school's money; **the admin (management) can read everything but not change it**, and **parents** read the statement of their own children (**Fees** on each child's card; students do not see fees). Everyone else is refused (`FinanceAccess`).

- **Yearly fees** (`/finance/fee-structures`): the fee of each grade for the current year (e.g. Tuition, grade 5: 1200). **Give to the grade's students** splits it into equal installments (the last takes the remainder: 1000 in 3 = 333.33 + 333.33 + 333.34) starting on a date and every N months, with an optional discount for each; every enrolled student of that grade gets it once (running it again skips those who have it).
- **A student's account** (`/finance/students/{id}`): add a fee for one student (bus, books...) with a discount and an even split; see every fee with its installments (paid / partly paid / not due yet / **overdue**), totals and payments; delete a fee or change its discount only while it has no payments (the discount re-splits the installments).
- **Payments** (`POST /finance/payments`): amount, method (cash, transfer, card, cheque, online), date (not in the future), reference. A payment is **spread over the oldest unpaid installments first** (or the chosen one first), can never be more than what is still owed, and gets a **receipt number** and a printable receipt. A wrong payment is **cancelled** and the installments get the money back. All money changes are in the **audit log**. Amounts are computed in cents, never in floating point.
- **Balances** (`/finance/accounts`): every enrolled student's fees, paid, remaining and overdue, filtered by grade, section, status (overdue / still owing / settled) and name, paged; **summary** cards: expected, collected, remaining, overdue, collected this month, students with overdue installments. The **currency** is a school setting (default JOD).
- **Reminders:** every morning `finance:remind` tells the parents (bell and phone) about installments due in the next 3 days and about overdue ones (again every 7 days, until paid). It is scheduled for every school with `tenants:run finance:remind`; in development run `php artisan schedule:work` (or `php artisan tenants:run finance:remind` by hand).

The demo school (`school:seed-demo`) now has an accountant (`accountant1`, same teacher password). Run `php artisan tenants:migrate` for the new `payment_allocations` table.

## Chat

**Chat** (header link with an unread counter, and the admin menu): conversations on one side, the open one on the other (one at a time on a phone). Messages are text and/or **one photo** (JPG, PNG or WebP up to 5 MB, private to the conversation, `GET /api/chat/messages/{id}/image`); the open conversation refreshes every 4 seconds and the list every 10; a sender can delete their own message.

**Who can chat with whom** (`ChatAccess`, checked again on every send): the **admin** with anyone; a **teacher** with the **students** of the sections they teach or are homeroom of and with those students' **parents**; **students and parents** with the teachers of their section. Students never write to each other or to parents, and a teacher cannot write to a family outside their sections. **New chat** lists only the people you may write to (`GET /api/chat/contacts`, with a label: the teacher's subject, the student's section, the parent's children); the same pair always has one conversation (`POST /api/chat/conversations`). If the pair stops being allowed (the student moves to another section) the history can still be read but nothing new can be sent.

**Groups:** a teacher of a section or the admin makes a **section group** (**New group**: the section, an optional name, *include the parents*, *only the teachers can write*), holding the section's students, its teachers and optionally the parents (`POST /api/chat/groups`). The group **follows the section**: it is synced whenever someone opens it, so new students join and students who left disappear. By default members can write; with *only the teachers can write* it works as a teachers' notice board.

**Read receipts:** in a direct chat one tick ✓ = sent and two blue ticks ✓✓ = read; in a group the sender sees how many have read it (✓ 3/4). Opening a conversation marks its messages read (`message_reads`). **Notifications:** a message rings the bell (and the phone, if enabled) of each recipient, but only once while they have not opened that chat, so a busy chat is one notification; opening it clears it. Uses the existing chat tables plus two columns for groups: run `php artisan tenants:migrate`.

## Automatic fees for new students

Each **yearly fee** (Finance > Yearly fees) now carries its **plan**: number of installments, first due date, months between installments, and **Apply automatically to new students of this grade** (on by default). So the price list is per grade (grade 1: 700, grade 2: 800, grade 3: 900...) and **a student added to a section (or moved to another grade) gets their grade's fee by themselves**, split by that plan; fees the student already has are never touched, and *Give to the grade's students* uses the same plan for students already enrolled (`POST /finance/fee-structures/{id}/apply` with no body).

On the **Add student** form, once a section is chosen, two optional amounts appear: **Transport fee** (empty by default: it depends on where each student lives; it becomes a "Transport" fee split by the grade's plan) and **Discount** (a sum in money taken off the grade's first yearly fee, e.g. a sibling discount; it must be smaller than the fee, and the student is not created otherwise). After saving, the dialog lists the fees the student got. The **Students page** (admin) has a **Remaining fees** column, red when something is overdue, linking to the student's account; the accountant sees the same in Finance > Students' accounts. Run `php artisan tenants:migrate` (the plan columns of `fee_structures`).

**The transport fee can be changed later** (a student can move to another area between the terms): the **Edit student** form shows the current transport fee; change it, or empty it when the student stops using the bus. What was already paid is kept, the unpaid installments are re-split (a new installment is added if all were closed), the new total can never be less than what was paid (the accountant handles a refund), and emptying it with nothing paid removes the fee. Fees have a `kind` (`yearly`, `transport`, `other`) so the transport fee is found whatever the language of the screen; the migration fills it for fees that exist. Run `php artisan tenants:migrate`.


## First payment at registration, and the fee fields in one place

The **Add student** form has a **Fees** section (it appears once a section is chosen; the **Edit** form shows it too): the grade's yearly fee is added by itself, and below it come what differs for this student: **Transport fee** (also on Edit: a student can move to another area), **Discount** (only when adding), and **First payment** with its method (only when adding): the amount received now is taken from the yearly fee first and the rest from transport; it can never be more than the fees owe, it gets a receipt (one per fee it covered, listed in the dialog after saving, printable from the student's account), and if anything is refused the student is not created. The person who registers the student is the receiver of that payment.

The accountant has the same in **Finance > Students' accounts > the student**: **Add / Change the transport fee** (`PUT /finance/students/{id}/transport`), **Change the discount** of a yearly fee (only before any payment), **Record a payment** and **Add a fee**. No migration.

## One schedule per student, payer name on receipts
- Each student has one yearly fee with one installment schedule. Transport and discount are lines inside it (`student_fee_items`), not separate schedules.
- Adding fees to a student who already has a schedule spreads the amount over the **remaining open installments** (e.g. 900 over 9 months, 200 paid → 700 over 7; +100 transport → spread over the same 7).
- The add-fees dialog sets installments, transport, discount and the first payment together.
- Paying an installment accepts a `payer_name`; the receipt is written in that name.
- Requires `php artisan tenants:migrate`.

## Motivation (points, badges, star of the week)
- Teachers (and the admin) open **Points & badges**, pick a section, tick students and tap a category (e.g. *Helping +2*, *Late −1*), or enter a free amount with a reason. Awards can be undone by whoever gave them.
- **Badges** are earned automatically when a student's total reaches the badge's `points_required`, or handed over by a teacher. The student and parents get a notification.
- **Star of the week**: the section's leaders for the current week are suggested; the teacher crowns one (`PUT /api/motivation/star`), the family is notified.
- Admin manages the categories and badges at `/admin/motivation-settings`.
- Students (`/student/motivation`) and parents (`/parent/children/:id/motivation`) see points, badges, the next badge to aim for and the latest awards.
- Requires `php artisan tenants:migrate`.

## Online payment (parents pay by card)
- A parent opens a child's fees and taps **Pay online**: picks an installment (or the whole balance), the amount and the **payer name** (the receipt is written in it). The result is a normal `Payment` with method `online`; the parent gets a notification.
- **No school page ever sees a card number.** A real provider takes the parent to its own hosted page and tells us the result by webhook (`POST /api/payments/webhook/{provider}`, signature checked by the driver). Money is applied only from that webhook, once (retries are safe).
- The provider is pluggable, like messaging: implement `App\Shared\Payments\PaymentGateway` (`start()` and `parseWebhook()`), add the class to `config/payments.php` under its key, put its keys in `.env`, set `PAYMENT_GATEWAY=<key>`. No screen changes.
- `PAYMENT_GATEWAY=demo` (default in development) shows a fake card form: `4242 4242 4242 4242` is approved, `4000 0000 0000 0002` declined. Nothing is charged, the card is not stored, and the demo is switched off in production. `PAYMENT_GATEWAY=none` turns online payment off (the button disappears).
- If the provider took the money but the fee had already been paid another way, the payment is kept with status `review` (table `online_payments`) for a person to refund.
- Requires `php artisan tenants:migrate`.

## Excel export
- **Export to Excel** buttons on the attendance report, the student fee accounts and a subject's results. The file has every row (not just the page shown), uses the page's filters, and the headers follow the interface language (the sheet reads right-to-left in Arabic).
- Endpoints (same permissions as the screens they export): `GET /api/attendance/report/export`, `GET /api/finance/accounts/export`, `GET /api/grading/results/export`.
- Built on `openspout/openspout` v4 (it runs on PHP 8.2; v5 needs 8.3) through `App\Shared\Export\XlsxExport`; a new export is a controller method that passes headers and rows to it.
- Excel import is the next step.

## Excel import (students and staff)
Upload the **school's own file** as it is — no template needed.
1. Admin: **Import** in the side menu, choose Students or Staff, pick the file, **Read the file**.
2. The columns are recognised automatically from the header words (Arabic or English, e.g. "اسم الطالب", "الصف", "جوال ولي الأمر", "Phone") and, for a plain "Name" column, from the script of the values (Arabic letters → Arabic name, Latin → English). The first rows are shown so the admin can fix any wrong guess or ignore columns.
3. **Check the file**: every row is validated with the same rules as the forms and problems are listed by row; nothing is created yet.
4. **Import**: valid rows are created, the rest skipped. Fix and re-upload the same file safely (existing rows are reported as duplicates).
- Generated for the school: student numbers when the file has none (after the highest existing one), student login emails (as when adding a student), staff login emails from the name (`sara.khaled@<domain>`, `STAFF_EMAIL_DOMAIN`; Arabic names are transliterated), the parent account from the parent email (siblings share it), and the grade's yearly fee.
- Sections match however they are written ("5-A", "الصف 5 أ"); gender accepts male/female/ذكر/أنثى; staff positions accept codes, English words and the usual Arabic job titles (معلمة, محاسب, مديرة...) or one default position for the whole file.
- `.xlsx` and `.csv`, up to 1000 rows / 2 MB. A template is still offered for schools that start from scratch.
- Endpoints (admin): `POST /api/import/{students|staff}/analyze`, `POST /api/import/{students|staff}` (`file`, `mapping`, `confirm`, `default_staff_type`), `GET /api/import/{kind}/template`.
- A new kind of import = one `Importer` class (`columns()` with aliases, `check()`, `create()`).

## Parents without an email (sign in with the phone number)
- A parent does not need an email. If a student's parent has only a phone number, the parent account is created with that number (no email is invented) and the parent signs in with it and the default password, then must choose a new one.
- Phone numbers match however they are typed (`0790 000 001`, `079-000-0001`, `+962790000001`, `00962790000001` are the same line). Children with the same number share one parent. If an email is added later, it goes on the same parent account.
- Works when adding a student by hand and in the Excel import (the result table shows how each parent signs in). A phone that belongs to staff or a student cannot be used for a parent.
- "Forgot my password" sends the code by email, so a phone-only parent asks the school; the admin resets it from the students page (back to the default password).

### A phone-only parent adds their own email
- After the parent chooses their own password (the first sign-in), the app offers **Add your email** (they can press *Later*; the parent home reminds them until they do). A 6-digit code is sent to that email and typed in; only then is it saved (`POST /api/auth/email/request`, `/confirm`), so a mistyped or someone else's email can never receive password codes.
- If the email already belongs to **another parent account** (the same parent registered another way), the two accounts are **merged automatically**: every record pointing at the phone-only account (children, notifications, chat, payments...) moves to the kept account, which also gets the phone number and the password they just chose, and the person continues as it (a new token is returned). The merge works over every foreign key to `users`, so new tables are covered.
- An email of staff, a student or a deleted account is never taken. Requires `php artisan tenants:migrate`.

## Backups
- **Every night at 02:30** (`backup:run`) the central database and every school get a backup: one zip with the school's whole database (one JSON-lines file per table, so it needs no `mysqldump`) and its files (homework photos, etc.). At 03:30 `backup:prune` deletes old ones: every backup of the last 14 days and the first of each of the last 6 months are kept (`config/backup.php`); the newest is never deleted.
- Backups go to `storage/app/backups/<school code>/<date>_<time>.zip`. **In production set `BACKUP_DISK` to a disk on another machine** (an S3-compatible bucket or a storage box): a backup on the same server is lost with the server. Set `BACKUP_PASSWORD` to encrypt them (AES-256; keep the password safe, nothing opens without it) and `BACKUP_ALERT_EMAIL` to be emailed when one fails (one school failing never stops the others).
- The server needs the Laravel scheduler running (`* * * * * php artisan schedule:run`).
- Commands: `php artisan backup:run [--school=code]` (now), `backup:status` (newest per school, fails if one is missing or older than 36 hours: use it as a health check), `backup:list <school>`, `backup:prune`.
- **Restore a school**: `php artisan backup:restore <school> [path] [--force]` (default: its newest nightly backup). It first makes a `before-restore` safety backup of the current state, then replaces the school's data and files; if anything fails nothing is changed. A backup only fits the same set of migrations: restore it with the application version that made it, then update.
- Practice a restore on a copy before you need one.

> Backups and account merges list the tables of the **current** database only (`App\Shared\Support\CurrentDatabase`). Laravel's `Schema::getTables()` with no argument lists every database on a MySQL/PostgreSQL server, which mixed the central and the schools' tables.

## Report card (student certificate)
- `GET /api/students/{student}/report-card` and the page **Report card** (parent: per child; student: own; admin: *Open* in the students table). One A4 page with the school name and logo, the student, every subject with each term's percent, the annual percent and result, the term and annual averages, days absent and late per term and for the year, behaviour, the pass mark and signature lines (homeroom teacher, principal, parent). **Print** opens the browser's dialog (which can also save a PDF).
- Only **published** grades count; a subject waiting for a makeup exam or a retake shows "Pending". The annual percent of a subject is the mean of its terms that have a percent. Behaviour is the average of the weekly evaluations (parents and the student see only those marked visible to parents).
- Same access as the grades: the admin, the student and their parents.
## Surveys, polls and parent approvals
- **Admin** (all parents, a range of grades *from … to …*, or hand-picked sections) and **teachers** (several of their own sections) send a **poll** (one question: choices or yes/no), a **survey** (several questions: one choice, several choices, yes/no, written) or an **approval** (e.g. permission for a trip: each parent approves or declines **for each child**; extra questions such as an emergency phone are optional). Parents are notified (bell + phone); an optional closing time, or close it by hand.
- **Who answers**: a poll or survey can be answered by the **parents**, the **students**, or **both** (each gets their own copy and their own page, `/parent/surveys` or `/student/surveys`, with a banner on their home page); an **approval is always the parents'** (students never get one, and the server refuses it).
- **Staff surveys** (admin only): choose **Staff** as who answers and pick the members of staff (search by name, filter by position, tick one by one or everyone shown). Each chosen person gets it on their own page (`/teacher/my-surveys`, `/admin/my-surveys` for the management positions) with a banner on their home page; a poll or survey only (an approval stays with the parents). The results table shows each member with their position.
- **Parents** see what is waiting on their home page and under *Surveys and approvals*; they can change their answer until it closes. Declining an approval needs no other answers.
- The creator sees the counts per choice, the written answers, and **who has not answered** (those first, with their phone); *Remind* notifies only those (at most every 6 hours). A survey that already has answers cannot be deleted, only closed.
- **Table of answers** (*Open the table of answers*): every parent with the child, section, phone, decision and the extra answers; filter to approved / declined / waiting, search, and **Export to Excel** (also per filter).
- A teacher sees only the surveys they sent; the admin sees all. Who can send: the admin account and the management positions (principal, vice principal, general manager), and teachers for the sections they teach or are homeroom of. Requires `php artisan tenants:migrate`.

### Special evaluation (criteria for one student)
- When evaluating a student, under the school's general criteria there is a **Special evaluation** box: write criteria for **that student only** (e.g. "Raises his hand before speaking") and score them 1-5 together with the weekly evaluation. They appear only for that student (the list shows "★ N special" next to him/her) and never for the other students; the general criteria and everyone else's evaluations are untouched.
- Every teacher who evaluates the student and the admin share and manage the student's criteria (add, remove; one already scored is hidden so its history stays). Names must be unique per student.
- The student's behaviour results (staff, parent, student) show a **Special evaluation** block: each criterion with its average and weekly scores; the family sees only the evaluations the teacher marked "visible to parent".
- Endpoints: `GET|POST /api/behavior/students/{student}/special`, `PUT|DELETE /api/behavior/special/{criterion}`; `special_scores` on `PUT /api/behavior/evaluations`. Requires `php artisan tenants:migrate`.

### Electronic signatures on the report card
- **Admin → Signatures** (side menu): upload a photo or scan of a signature on white paper for the school's signatures (principal, vice principal, counsellor... each with a title and the name printed under it, shown in order) and for the homeroom teachers. A **teacher** uploads their own from *My signature*; it prints on the report cards of the section they are the homeroom teacher of.
- The server removes the paper: it finds the paper colour from the edges of the picture, makes it transparent (shadows and a yellowish photo included), keeps the colour of the ink, and crops to the signature. The **"Remove the paper"** slider cleans more or less (the uploaded photo is kept, so it is redone without uploading again); the preview is shown on a checkerboard so the transparency is visible.
- The report card prints the homeroom teacher's signature, then the school's, then a blank line for the parent; they print with the card (**Print** / save as PDF). A person with no signature keeps a plain line with their name.
- Signatures are stored privately per school and sent inside the report card as data (no public URL). Needs the PHP **gd** extension (on XAMPP: `extension=gd` in `php.ini`, enabled by default). Requires `php artisan tenants:migrate`.

## Payroll and staff contracts

- **Contracts** (`/teacher/payroll/contracts`, `/admin/payroll/contracts`): one active contract per person with a base salary, monthly allowances/deductions and an end date. **Renew** creates the next contract the day after the old one ends, with the annual raise (percent, fixed amount, or a new salary); the old one becomes `renewed` and the history keeps every raise.
- **Monthly payroll** (`/teacher/payroll`, `/admin/payroll`): the accountant opens a month (a payslip per staff member with a contract, recurring items copied), adds one-off bonuses and manual deductions (amount + reason), then submits. The principal (admin role) approves or sends it back with a note. The accountant then records the payment; each person sees their own paid payslips (`/teacher/payslips`) and can print them.
- **Reminders**: `php artisan contracts:remind` (daily at 08:10 for every school) notifies the accountant and the principal about contracts ending within 30 days (once per week per contract).
- **Flexible payment method**: each contract has a default way of paying (bank transfer, cheque, cash) plus details (IBAN, payee). A payslip starts with it and the accountant can change it per person and per month until it is paid; "Record payment" pays everyone their own way.
- **My salary**: every staff member sees their current salary, allowances and payment method above their payslips (`/teacher/payslips`).
- After pulling: `php artisan tenants:migrate`.

## Student renewal (re-registration)

- Add the next academic year first (Academic years). Then open **Student renewal** (`/admin/renewals`, or the accountant's home): "Open renewal" lists every enrolled student with their **next grade** (the next `sort_order`); students in the last grade are marked "Last grade".
- **Renew** places the student in a section of the next grade in the new year (that is the automatic move to the new grade; last year's enrollment stays as history) and gives them the new year's fees (`auto_apply` fee structures, with optional discount and transport). Pick a section yourself (with how full it is) or leave it automatic: the emptiest section with free seats that suits the student's gender. "Repeat the same grade" keeps them in their grade.
- **Renew all waiting** places everyone automatically and reports who could not be placed (no section or full). **Decline** records a reason and gives no seat; it can be reopened.
- Parents (and the student) get a notification when the student is renewed. Raise the fees for the new year by setting the new year's fee structures.
- After pulling: `php artisan tenants:migrate`.

## Staff attendance and commitment rules (automatic bonus / deduction)

- **Working day**: each staff member presses "I arrived" / "I'm leaving" on their home page (`/teacher`, `/admin/staff-attendance`). Arriving after the start of the day (School day setting) plus the grace minutes is "late". The principal can fix any day (absence, leave, forgotten check-in); a working day with no record counts as an absence, but days before the school started recording are never held against anyone.
- **Commitment score** (0-100) per month from what the system already records: punctuality (on-time days), daily summaries (one per scheduled lesson) and weekly lesson plans (submitted or approved, one per class and subject each week). A measure that does not apply (e.g. no lessons) is skipped.
- **Rules** (`/admin/payroll/rules`, principal edits, accountant reads): per measure, a bonus at or above a score and a deduction below another, each a percent of the salary or a fixed amount. Off until switched on. When the payroll is prepared the lines are added automatically (the accountant can still remove one); a deduction never takes the net below zero. Each person sees their score and what it earns on "My payslips".
- After pulling: `php artisan tenants:migrate`.

## My salary page and the school's own bonus / deduction rules

- **Own rules** (`/admin/payroll/templates`, `/teacher/payroll/templates`; the principal and the accountant both write them): a name, bonus / allowance / deduction, a fixed amount or a percent of the salary, and an optional category (private lessons, summer club, club, other). In a draft payroll the accountant gives a rule to everyone ("Give a rule to everyone") or to one person ("Rule"); percent rules are worked out from each person's base salary, and a deduction that would take someone's net below zero is skipped for them. Manual lines can carry a category too.
- **My salary** (`/teacher/payslips`, `/admin/payslips`): the last paid month with how it was calculated (base, bonuses, deductions grouped by category), the current contract, the commitment score, a donut of the month (base, additions, deductions, net in the middle; pick the month under it), and the previous months (tap one to see its breakdown).
- After pulling: `php artisan tenants:migrate`.

### Deduction steps and absence

- Each commitment measure can have **steps**: e.g. below 80% lose 10, below 60% lose 25, below 50% lose 20% of the salary. Only the strictest step the score falls under applies (they do not add up). Old single-threshold rules keep working as one step.
- **Absence**: a working day with no check-in (after recording started) costs a fixed amount, or a percent of one day's pay (salary / working days of the month); approved leave costs nothing.

### Assigning rules to staff (summer club, private lessons) and Arabic dates

- On the rules screen press **Assign staff** next to a rule, tick the teachers and choose the first (and optionally the last) month. The rule is added to their payslip automatically in every payroll prepared for those months (inactive rules and deductions that would push the net below zero are skipped). Open-ended = every month until removed. For a one-off in one month use **Rule** inside the draft payroll.
- The commitment rules screen has a **Use suggested steps** button when a measure has no deduction steps yet.
- Dates: the browser draws native date fields left-to-right, so in Arabic the app shows **day / month / year** as three fields from right to left (`DateInput` / `MonthInput`); in English the native field is kept.
- After pulling: `php artisan tenants:migrate`.

## Audit log

- **Audit log** (`/admin/audit-log`, school management only, read only): every create / update / delete of sensitive data (grades, fees and payments, attendance, payroll, contracts, students, accounts...) with who, when, and the changed fields (old → new). Filter by action, kind of record, person, dates and record number; page through; **Export to Excel** (up to 10,000 rows). Password hashes are never shown.
- No migration needed.

## Look and feel (the "Relief" design)

- Cream paper canvas (`#f9f7f0`), one sky-blue accent, deep-navy blocks (home tiles, role cards, admin sidebar), pill buttons, and a flat solid "pop" shadow under primary buttons (never a blurred shadow). Tokens live in `frontend/src/index.css` (`@theme`).
- **Per-school branding**: the accent `--color-brand` is still set at runtime from the school's `primary_color`; the pop shadow and soft washes are derived from it with `color-mix`, so every school keeps its own colour on the same system.
- **Fonts**: Nunito stands in for Gilroy (Latin) and Cairo for Arabic (loaded from Google Fonts in `index.html`). The tight letter-spacing of the style is applied in English only, because it would break the joined Arabic letters.
- Use `.feature-card` for navy tiles and `.cta` for a large call to action; existing `rounded` buttons/inputs/cards are restyled globally (pills, 14px fields, 22px cards).
- Status colours (green paid / amber late / red absent) are kept on purpose: they carry meaning in a school system.

### Layout and icons

- Icons are [lucide-react](https://lucide.dev) (`import { Wallet } from 'lucide-react'`), always with `aria-hidden` next to a text label.
- **Header** (all roles): school name on one side; round icon buttons for chat, announcements and notifications (with unread badges), and one **account menu** (avatar, name and role, language, change password, switch school, log out) instead of a row of text buttons (`UserMenu`, `IconButton`).
- **Admin sidebar**: grouped (People, Learning & follow-up, Schedules, Money & staff, Communication, School setup, System), each item with an icon, sticky and scrollable on desktop.
- **Teacher home**: arrive/leave card, then tiles in groups (Teaching, Follow-up, Department, Me); an accountant sees School money. Each tile has an icon, a title and a hint.

### Landing page

The school's public page (`/`) shows a welcome hero: the school's **logo** (or a graduation-cap badge / first letter when it has none) and **name** as the big title, soft clouds, stars and floating feature chips, a small school-building illustration (inline SVG in the palette), role cards, a features band and a closing call to action. The name, logo and colour come from the school's branding; motion is switched off for people who prefer reduced motion.

The same welcome page (`LandingView`) is the very first page for everyone: with a school chosen it shows that school's logo and name and a login button; before a school is chosen (a fresh visitor) it shows the platform ("مدرستي") with **Create account** and **Sign in** (find my school). Signed-in users are sent straight to their home.

### Parent and student homes

- **Parent**: a navy welcome band with one tab per child (avatar + name), then the chosen child at a glance (class, student number, days absent / late with the dates on demand) and every section as an icon tile (report card, grades, timetable, homework, summaries, lesson plans, behaviour, points, fees), and the child's sign-in email at the bottom.
- **Student**: a welcome band with the class, the same icon tiles (timetable, homework, summaries, lesson plans, report card, behaviour, points, surveys with a count), then "My grades" as subject cards with a percentage bar.
- Shared: `SectionTiles` (icon tiles).

### Survey reminders only reach people who still owe an answer

"Remind" goes only to people who have not answered everything the survey asks of them (a parent with two children and an approval each is reminded only while one is still open). When someone finishes, their invitation and any reminder still unread in their bell are marked read, so nobody is nagged about something already done.

## Exam timetable

- **Principal** (`/admin/exams`, under Schedules): choose the term, the dates, and **which periods the exams take** (first period and how many, e.g. periods 2 and 3), exam times per day, how many exams a grade may have per day, which grades (none = all) and days off. **Preview** shows how many exams over how many days and any warnings; **Publish** replaces the exams of those grades. Needs the school day (periods), each grade's curriculum (subjects) and sections.
- **An exam replaces lessons**: it sits in the school's own periods (times come from the school day), and **the teacher who already has that section in those periods on the timetable watches it automatically**. A period where the section has no teacher on the timetable gets a free teacher (one with no lesson then, fewest duties first) or, if you switch that off, a warning.
- **Rules**: every section of a grade sits the same subject at the same time; a grade never has more than the allowed exams a day; only working days; too few days is refused with the number needed.
- **By hand**: move an exam to other periods (times and watchers follow the timetable) or give it a custom time; clashes (same section, or a watcher already in another room) are refused; delete an exam.
- **Everyone else**: students see their section's exams (`/student/exams`), parents each child's (`/parent/children/:id/exams`), teachers the rooms they watch and the exams of the classes they teach (`/teacher/exams`). Publishing notifies them.
- After pulling: `php artisan tenants:migrate` (adds the periods to exam sessions).

### Monthly and final exams

An exam has a **type**: *monthly exam 1, 2, 3...* (in lesson periods; the section's own teacher watches) or *final exams* (own start time, length and gap; students come only for the exam; free teachers watch, never the subject's own teacher and never two rooms at once). Each round is independent: generating one replaces only that round of those grades. The list can be filtered by type, and every exam shows its type. After pulling: `php artisan tenants:migrate` (adds `kind` and `round` to exam sessions and lets a subject have one exam per round).

## Duty roster (المناوبات)

- **Posts** (`/admin/duties`, under Schedules): where staff supervise (the gate, the yard at break...), the time, how many people, and the days (none = every working day).
- **Build the roster** of a period: preview, then publish. For each working day and post it picks teachers who have **no lesson during the post's time** and no other duty that day, sharing the duties fairly (fewest duties so far, then the one who waited longest). Days off can be excluded. Too few free teachers is a warning. Publishing replaces the *unlocked* duties of those posts in the range and notifies the people who got one.
- **By hand**: add a duty (a post or a custom time and place), change the person or time, delete. A hand-made change is **locked**: regenerating keeps it and counts it. A person cannot have two overlapping duties.
- **Teachers** (`/teacher/duties`): coming duties (the next one highlighted), the last 30 days, and a **reminder the afternoon before** (`duties:remind`, scheduled daily at 15:30 for every school).
- After pulling: `php artisan tenants:migrate`.

### Who does duty

On `/admin/duties` the section **Who does duty** lists the teachers with a checkbox each (search, select all / none, "12 of 32 teachers"). Only the ticked teachers are put on the generated roster; everyone is ticked by default. Duties can still be added by hand for anyone. After pulling: `php artisan tenants:migrate` (adds `does_duty` to staff profiles).

### No teacher in two exam rooms at once

When exams are generated, teachers already watching other exams (other grades, rounds or kinds that the run does not replace) are not used again at that time, and a teacher who has two sections in consecutive periods (1-A in period 2, 1-B in period 3) watches each **in its own period** (the lists show "Sara (Period 3)" and a teacher's own list shows only her window). Moving an exam or changing its watchers by hand is refused when a teacher would be in two rooms at the same moment. After pulling: `php artisan tenants:migrate` (adds the periods to exam watchers); regenerate the exams to apply it to existing ones.

### Editing or deleting all exams at once

Above the exam list, the buttons act on **what the list shows** (term, the type/round chosen in "Show", and the grade): **Delete all shown** (asks first) and **Edit all shown**: move every date by some days (e.g. `7` or `-7`), put the monthly exams in other periods (times and watchers follow the timetable), or start the final exams at another time (their length is kept). Everything is checked together and nothing changes if a section or teacher would clash or a date lands on a day off. To redo a whole round from scratch, "Publish" again replaces that round.

### Confirmation dialog

Every delete asks first in the same styled dialog (`ConfirmDialog`: a red trash icon, the question, what will be removed, a clear red button and a Cancel that has the focus, so Enter does not delete by accident). The browser's own `confirm()` is no longer used. Chips that hold a number or a class name (`1-A`, "Monthly exam 1") are isolated with `<bdi>` so Arabic text never splits them.

## Class feed (stories)

- **Post** (`/teacher/stories`, `/admin/stories`): text and up to 6 photos (jpg/png/webp, 5 MB each), for a class, a grade or the whole school, with comments on or off. A **teacher posts only to the classes they teach or lead**; the **principal** to any class, grade or the whole school.
- **Read** (`/student/stories`, `/parent/stories`, and teachers): each person sees only what is for them: their class, their grade, the whole school (a parent sees what is for any of their children's classes). Newest first, pinned posts on top, "show more" pages of 8.
- **React and comment**: a heart (press again to take it back) and comments (500 characters) for anyone who can read the post; the author is told of comments. The author or the principal can pin, close the comments, delete the post (its photos go with it) and delete any comment; a person can delete their own comment.
- **Notifications**: students and their parents are told when something is posted for their class (teachers when it is for the whole school).
- **Photos are private**: they are fetched with the sign-in token and open only for people who may read the post.
- After pulling: `php artisan tenants:migrate` (replaces the old unused `stories` / `story_media` tables).

## Purchase requests (procurement board)

- **Ask** (`/teacher/procurement`, `/admin/procurement`): any member of staff files a request: what, details, quantity, category, priority (low / normal / high / urgent), estimated cost, needed by. They edit or delete it while it waits.
- **Decide**: the **principal** approves (the accountant is told to buy) or declines **with a reason** (the requester reads it).
- **Buy**: the **accountant** records the purchase of an approved request: where from, what it really cost, the invoice number and date. The requester is told.
- **Receive**: the requester (or the principal / accountant) presses "It arrived".
- **Board**: columns Waiting → Approved → Bought → Received (and Declined when there is one), urgent first; tabs on a phone. The principal and the accountant see everything with "waiting for a decision", "approved, to spend" and "spent so far"; everyone else sees only their own requests. Every change is in the audit log.
- After pulling: `php artisan tenants:migrate` (replaces the old unused `procurement_requests` table).

## School transport (المواصلات)
- **Rounds** (any number) belong to the **morning** (going to school) or the **evening** (going home), each with an optional start/end time.
- **Buses**: number, driver name and phone, seats, the area it covers (from → to), the rounds it serves, active or not.
- **Rides**: each student has two ways, each with its own round and bus: e.g. morning round 1 on bus 11 and evening round 2 on bus 11 (or on another bus). Either way can be left empty.
- Any active bus can be chosen for a round: it is added to that round automatically. Seats are counted per bus and per round; a round or bus that still carries students cannot be deleted.
- Transport fees already live in the student's fee account (`transport` line) and are unchanged.
- Principal: `/admin/transport` (tabs Buses / Rounds / Students). Student and parent: a "School bus" tile (`/student/transport`, `/parent/children/:id/transport`).
- API: `GET /api/transport`, rounds and buses CRUD under `/api/transport/...`, `PUT/DELETE /api/transport/students/{student}` (`pickup_round_id`, `pickup_bus_id`, `dropoff_round_id`, `dropoff_bus_id`), `GET /api/students/{student}/transport`. Needs `php artisan tenants:migrate`.

## Tasks (المهام)
- Management (admin role) gives a task to **any teacher**; a **head of department** gives one to the teachers of **their own department** (same subject). Other teachers only receive tasks.
- A task has a title, details, due date, priority and one or more people (the people list has a name search and a job filter; "select all" takes only who is shown). Each person has **their own status** (To do / In progress / Done) and a note; the giver follows the progress (done count + bar) and can edit, add or remove people (progress is kept) or delete.
- Notifications: new task, task finished (to the giver), and a daily `tasks:remind` (07:30, per school) for tasks due tomorrow or overdue since yesterday.
- Screens: `/teacher/tasks` (tile "Tasks"), `/admin/tasks` (People group). API: `GET /api/tasks/mine|given|people`, `POST /api/tasks`, `PUT|DELETE /api/tasks/{id}`, `PUT /api/tasks/{id}/status`. Needs `php artisan tenants:migrate`.

## Attachments (files and photos)
- One reusable system (`attachments` table, private storage, `/api/attachments/...`) for anything that needs files. For now: **tasks** (the giver attaches files to the task; each assignee hands in files/photos), and **surveys, polls and parent approvals** (the author attaches an image or files that the recipients see).
- Allowed: images, PDF, Word, Excel, PowerPoint, text/CSV; up to 10 files of 10 MB each per owner. Files open only for people who may see the owner (task people, the giver and management; a survey's author and recipients; a hand-in only by its author, the giver and management). Deleting the owner deletes its files.
- Frontend: `AttachmentsField` (choose files in a form, uploaded after saving), `LiveAttachments` (add/remove at once), `AttachmentList` (show) in `components/shared/Attachments.jsx`: reuse them for any new screen that needs files.
- Needs `php artisan tenants:migrate`.

### Payroll: updating a draft from the attendance
- The automatic lines (punctuality, summaries, plans, **absence**) are worked out when the month's payroll is prepared. If absences are recorded or fixed afterwards, press **Update from attendance** on the draft payroll: it works them out again (lines added by hand, contract items and assigned rules are kept; it never charges twice). Absence deduction also needs the **absence** rule switched on with an amount in the commitment rules, and days before anybody's attendance was first recorded are not counted.

## What staff see (screen visibility)
- The principal opens **What staff see** (`/admin/staff-access`) and ticks, per member of staff (or for several people at once, with search and a job filter), which screens they see. A screen that is unticked disappears from the person's home page, the chat/announcement icons, and its address shows "not available". Default: everybody sees what their job allows.
- Stored in `staff_profiles.hidden_features` (list of keys, see `StaffProfile::FEATURES` and `frontend/src/features/StaffAccess/staffFeatures.js`; keep both lists in sync when adding a screen). Only staff are affected: students and parents are not.
- This hides the screens (home, menus, addresses); the API itself still follows the job's permissions.
- API: `GET/PUT /api/staff-visibility` (admin). Needs `php artisan tenants:migrate`.

### My salary: "This month so far"
- The top card of **My salary** shows, at once and without waiting for the payroll: the salary, the contract's monthly items, the automatic deductions/bonuses so far (e.g. **"Absent 2 days: −24.00"** with the dates missed) and the expected net. It is an estimate; the real amount is the approved payroll. `GET /api/payroll/commitment` returns it as `estimate` (and `scores.punctuality.absent_dates`). The absence deduction still needs the absence rule switched on in the commitment rules.

## Header icons (communication)
- Every account (principal, staff, students, parents) has the communication tools as round icons at the top, next to the user menu: **chat, announcements, class feed, notifications**. They are no longer tiles on the home pages or links in the principal's side menu. A member of staff does not see an icon the principal switched off (see "What staff see"). Shared component: `components/shared/HeaderIcons.jsx`.

### My salary: the donut
- The donut is the first thing on **My salary**. The whole circle is what the person earned (salary + additions); the **red slice is what was taken off it** (absence, lateness, other deductions) and the blue part is what they receive (in the middle). The first chip is **this month so far** (an estimate, so an absence shows up at once); the others are the paid months.

## Load testing
- `loadtest/` has a load test (virtual users, per-endpoint latency, queries per request) and `php artisan tenants:run loadtest:seed` to grow a school to a realistic size. See `loadtest/README.md` for how to run it, the results and the production advice (OPcache, `artisan optimize`, PHP-FPM, Octane).
- The header counters (notifications, announcements, chat, surveys) are one request, `GET /api/badges`, every 30 seconds per open tab (`useBadges` on the frontend).

## Arrange your own screens
- Every person arranges their own icons and menus with an **Arrange** button: the principal's side menu (groups and entries), the staff home (groups and tiles), and the parent and student home tiles. Press **Arrange**, move things with the arrows (they follow the language direction), press **Done**. **Back to the default order** restores it.
- Saved on the account (`users.layout`, `PUT /api/auth/layout` with `{surface, order: {groups, items}}`; `null` resets), so it follows the person to any device. Surfaces: `admin_nav`, `teacher_home`, `parent_home`, `student_home`. New screens added later appear after the ones the person arranged. Helpers: `features/Layout/useLayout.js`, `arrange.js`, `components/shared/Arrange.jsx`.
- Needs `php artisan tenants:migrate`.
