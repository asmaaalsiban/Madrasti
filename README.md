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

## Chat

**Chat** (header link with an unread counter, and the admin menu): conversations on one side, the open one on the other (one at a time on a phone). Messages are text and/or **one photo** (JPG, PNG or WebP up to 5 MB, private to the conversation, `GET /api/chat/messages/{id}/image`); the open conversation refreshes every 4 seconds and the list every 10; a sender can delete their own message.

**Who can chat with whom** (`ChatAccess`, checked again on every send): the **admin** with anyone; a **teacher** with the **students** of the sections they teach or are homeroom of and with those students' **parents**; **students and parents** with the teachers of their section. Students never write to each other or to parents, and a teacher cannot write to a family outside their sections. **New chat** lists only the people you may write to (`GET /api/chat/contacts`, with a label: the teacher's subject, the student's section, the parent's children); the same pair always has one conversation (`POST /api/chat/conversations`). If the pair stops being allowed (the student moves to another section) the history can still be read but nothing new can be sent.

**Groups:** a teacher of a section or the admin makes a **section group** (**New group**: the section, an optional name, *include the parents*, *only the teachers can write*), holding the section's students, its teachers and optionally the parents (`POST /api/chat/groups`). The group **follows the section**: it is synced whenever someone opens it, so new students join and students who left disappear. By default members can write; with *only the teachers can write* it works as a teachers' notice board.

**Read receipts:** in a direct chat one tick ✓ = sent and two blue ticks ✓✓ = read; in a group the sender sees how many have read it (✓ 3/4). Opening a conversation marks its messages read (`message_reads`). **Notifications:** a message rings the bell (and the phone, if enabled) of each recipient, but only once while they have not opened that chat, so a busy chat is one notification; opening it clears it. Uses the existing chat tables plus two columns for groups: run `php artisan tenants:migrate`.
