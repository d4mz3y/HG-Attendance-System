<?php

use App\Models\Setting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reports:send')
    ->dailyAt((string) config('hg.report_schedule_time', '06:00'))
    ->when(function (): bool {
        return Setting::getValue('enable_scheduled_reports', '0') === '1';
    })
    ->withoutOverlapping(180)
    ->onOneServer()
    ->name('scheduled-attendance-report');

Schedule::command('subscriptions:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->name('subscription-payment-reconciliation');

Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('02:20')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('expired-access-token-pruning');

Schedule::command('holidays:freeze-history')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('public-holiday-history-freeze');
