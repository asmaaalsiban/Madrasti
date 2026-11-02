<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every morning: tell the parents of every school about installments that are due soon or overdue.
Schedule::command('tenants:run finance:remind')->dailyAt('08:00');

// Every night: back up the central database and every school, then delete the old backups.
Schedule::command('backup:run')->dailyAt('02:30')->onOneServer();
Schedule::command('backup:prune')->dailyAt('03:30')->onOneServer();

// Every morning: tell the accountants and the principal about staff contracts that end soon.
Schedule::command('tenants:run contracts:remind')->dailyAt('08:10');
