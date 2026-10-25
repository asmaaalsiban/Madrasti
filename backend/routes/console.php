<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every morning: tell the parents of every school about installments that are due soon or overdue.
Schedule::command('tenants:run finance:remind')->dailyAt('08:00');
