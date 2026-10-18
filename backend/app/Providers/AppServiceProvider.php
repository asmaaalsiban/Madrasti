<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        // 5 login attempts per minute for the same school + login + IP.
        RateLimiter::for('login', function (Request $request): Limit {
            $key = (tenant('id') ?? 'central').'|'.Str::lower((string) $request->input('login', $request->input('email'))).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });
    }
}
