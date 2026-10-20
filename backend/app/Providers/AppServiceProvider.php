<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Policies\AcademicPolicy;
use App\Modules\People\Policies\UserPolicy;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([AcademicYear::class, Classroom::class, GradeLevel::class, Student::class, Subject::class] as $model) {
            Gate::policy($model, AcademicPolicy::class);
        }

        Gate::policy(User::class, UserPolicy::class);

        // Changing a password asks for the current one: 5 tries a minute per user.
        RateLimiter::for('change-password', fn (Request $request): Limit => Limit::perMinute(5)->by('cp|'.(tenant('id') ?? 'central').'|'.$request->user()?->id));

        // Public school registration (each call creates a database): 5 per hour per IP, plus a global daily cap.
        RateLimiter::for('register', fn (Request $request): array => [
            Limit::perHour(5)->by('ip|'.$request->ip()),
            Limit::perDay((int) config('tenancy.registration_daily_limit'))->by('register-global'),
        ]);

        // 5 login attempts per minute for the same school + login + IP.
        RateLimiter::for('login', function (Request $request): Limit {
            $key = (tenant('id') ?? 'central').'|'.Str::lower((string) $request->input('login', $request->input('email'))).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });
    }
}
