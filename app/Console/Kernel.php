<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $frequency = \App\Models\Setting::getValue('report_frequency', 'daily');

        if ($frequency === 'weekly') {
            $schedule->command('reports:weekly')->weekly();
        } elseif ($frequency === 'monthly') {
            $schedule->command('reports:weekly')->monthly();
        } else {
            $schedule->command('reports:weekly')->daily();
        }
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
    }
}