<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('project-desk:automatic-backup')
    ->everyMinute()
    ->withoutOverlapping(60);

Schedule::command('project-desk:sync-notifications')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('project-desk:prune-orphaned-files')
    ->dailyAt('03:30')
    ->timezone((string) config('project-desk.business_timezone', 'Africa/Tripoli'))
    ->withoutOverlapping(60);
