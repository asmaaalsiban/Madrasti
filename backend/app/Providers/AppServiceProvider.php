<?php

namespace App\Providers;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Policies\AcademicPolicy;
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
        foreach ([AcademicYear::class, Classroom::class, GradeLevel::class, Subject::class] as $model) {
            Gate::policy($model, AcademicPolicy::class);
        }

        // Public school registration: 5 per hour per IP (each call creates a database).
        RateLimiter::for('register', fn (Request $request): Limit => Limit::perHour(5)->by((string) $request->ip()));

        // 5 login attempts per minute for the same school + login + IP.
        RateLimiter::for('login', function (Request $request): Limit {
            $key = (tenant('id') ?? 'central').'|'.Str::lower((string) $request->input('login', $request->input('email'))).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });
    }
}
