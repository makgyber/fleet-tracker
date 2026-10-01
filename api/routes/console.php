<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

// Pull the tbss daily field schedule each morning and build optimized trips.
Schedule::command('fleet:import-schedule')
    ->dailyAt('05:30')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();
