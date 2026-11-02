<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Building;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Floor;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Modules\Academic\Models\Room;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Models\Term;
use App\Modules\Academic\Policies\AcademicPolicy;
use App\Modules\Finance\Services\FinanceAccess;
use App\Modules\Grading\Models\Assessment;
use App\Modules\People\Policies\UserPolicy;
use App\Modules\Scheduling\Models\Period;
use App\Modules\TeacherTools\Services\BehaviorAccess;
use App\Shared\Enums\Role;
use App\Shared\Messaging\MessageSender;
use App\Shared\Push\PushTransport;
use App\Shared\Push\WebPushTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The messaging provider (WhatsApp, SMS, ...) is chosen in config/messaging.php.
        $this->app->bind(PushTransport::class, WebPushTransport::class);
        $this->app->bind(MessageSender::class, fn ($app): MessageSender => $app->make(config('messaging.drivers')[config('messaging.driver')]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([AcademicYear::class, Classroom::class, GradeLevel::class, Student::class, Subject::class, Term::class, TeachingAssignment::class, Building::class, Floor::class, Room::class, GradeLevelSubject::class, Period::class] as $model) {
            Gate::policy($model, AcademicPolicy::class);
        }

        Gate::policy(User::class, UserPolicy::class);

        // Attendance: an admin, or the homeroom teacher of the classroom.
        Gate::define('take-attendance', fn (User $user, Classroom $classroom): bool => $user->hasRole(Role::Admin)
            || ($user->hasRole(Role::Teacher) && $classroom->homeroom_teacher_id === $user->id));

        // Grades: an admin, or the teacher of the assessment.
        Gate::define('manage-assessment', fn (User $user, Assessment $assessment): bool => $user->hasRole(Role::Admin)
            || ($user->hasRole(Role::Teacher) && $assessment->teacher_id === $user->id));

        // A student's published grades: admin, the student, their parents.
        Gate::define('view-student-grades', fn (User $user, Student $student): bool => match ($user->role) {
            Role::Admin => true,
            Role::Student => $student->user_id === $user->id,
            Role::Guardian => $student->guardians()->whereKey($user->id)->exists(),
            Role::Teacher => false,
        });

        // A student's timetable: admin, the student, their parents.
        Gate::define('view-student-timetable', fn (User $user, Student $student): bool => match ($user->role) {
            Role::Admin => true,
            Role::Student => $student->user_id === $user->id,
            Role::Guardian => $student->guardians()->whereKey($user->id)->exists(),
            Role::Teacher => false,
        });

        // A student's behaviour results: admin, a teacher who may evaluate the student, the student, their parents.
        Gate::define('view-student-behavior', fn (User $user, Student $student): bool => match ($user->role) {
            Role::Admin => true,
            Role::Student => $student->user_id === $user->id,
            Role::Guardian => $student->guardians()->whereKey($user->id)->exists(),
            Role::Teacher => app(BehaviorAccess::class)->canEvaluate($user, $student),
        });

        // A student's points and badges: same people as behaviour.
        Gate::define('view-student-motivation', fn (User $user, Student $student): bool => Gate::forUser($user)->allows('view-student-behavior', $student));

        // A student's homework: admin, the student, their parents.
        Gate::define('view-student-homework', fn (User $user, Student $student): bool => match ($user->role) {
            Role::Admin => true,
            Role::Student => $student->user_id === $user->id,
            Role::Guardian => $student->guardians()->whereKey($user->id)->exists(),
            Role::Teacher => false,
        });

        // A student's fee statement: management, the accountant, and the student's parents (not the student).
        Gate::define('view-student-fees', fn (User $user, Student $student): bool => match ($user->role) {
            Role::Admin => true,
            Role::Guardian => $student->guardians()->whereKey($user->id)->exists(),
            Role::Teacher => FinanceAccess::manages($user),
            Role::Student => false,
        });

        // A student's attendance: admin, the student, their parents, or the homeroom teacher of their classroom.
        Gate::define('view-student-attendance', fn (User $user, Student $student): bool => match ($user->role) {
            Role::Admin => true,
            Role::Student => $student->user_id === $user->id,
            Role::Guardian => $student->guardians()->whereKey($user->id)->exists(),
            Role::Teacher => Enrollment::where('student_id', $student->id)
                ->whereIn('classroom_id', Classroom::where('homeroom_teacher_id', $user->id)->whereHas('academicYear', fn ($q) => $q->where('is_current', true))->select('id'))->exists(),
        });

        // Changing a password asks for the current one: 5 tries a minute per user.
        RateLimiter::for('email-code', fn (Request $request): Limit => Limit::perMinute(10)->by('ec|'.(tenant('id') ?? 'central').'|'.$request->user()?->id));
        RateLimiter::for('change-password', fn (Request $request): Limit => Limit::perMinute(5)->by('cp|'.(tenant('id') ?? 'central').'|'.$request->user()?->id));

        // Public school registration (each call creates a database): 5 per hour per IP, plus a global daily cap.
        RateLimiter::for('register', fn (Request $request): array => [
            Limit::perHour(5)->by('ip|'.$request->ip()),
            Limit::perDay((int) config('tenancy.registration_daily_limit'))->by('register-global'),
        ]);

        // Forgot password: 5 code requests a minute per school + login + IP (and 20 per IP); 10 tries a minute at the code.
        RateLimiter::for('forgot-password', fn (Request $request): array => [
            Limit::perMinute(5)->by('fp|'.(tenant('id') ?? 'central').'|'.Str::lower((string) $request->input('login')).'|'.$request->ip()),
            Limit::perMinute(20)->by('fp|ip|'.$request->ip()),
        ]);
        RateLimiter::for('reset-password', fn (Request $request): Limit => Limit::perMinute(10)->by('rp|'.(tenant('id') ?? 'central').'|'.Str::lower((string) $request->input('login')).'|'.$request->ip()));

        // Login: 5 attempts a minute for the same school + login + IP, and 30 a minute from one IP for a whole school
        // (new accounts share the default password, so one machine must not be able to try it on many emails).
        RateLimiter::for('login', function (Request $request): array {
            $school = tenant('id') ?? 'central';
            $login = Str::lower((string) $request->input('login', $request->input('email')));

            return [
                Limit::perMinute(5)->by($school.'|'.$login.'|'.$request->ip()),
                Limit::perMinute(30)->by($school.'|ip|'.$request->ip()),
            ];
        });
    }
}
