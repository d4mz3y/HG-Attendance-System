<?php

namespace App\Console\Commands;

use App\Exports\AttendanceReportExport;
use App\Mail\ScheduledReport;
use App\Models\Setting;
use App\Services\ReportRowsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWeeklyReport extends Command
{
    protected $signature = 'reports:weekly';

    protected $description = 'Send weekly attendance report email';

    public function handle(): int
    {
        $email = Setting::getValue('report_email');

        if (! $email) {
            $this->error('No report email configured.');

            return 1;
        }

        $now = now();
        $from = $now->copy()->startOfWeek()->toDateString();
        $to = $now->copy()->endOfWeek()->toDateString();

        try {
            $rows = (new ReportRowsService)->build([
                'date_from' => $from,
                'date_to' => $to,
            ]);

            Mail::to($email)->send(new ScheduledReport($from, $to, $rows));
            $this->info("Weekly report sent to {$email} for {$from} to {$to}");

            return 0;
        } catch (\Throwable $e) {
            Log::channel('daily')->error(
                'Weekly report email failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $this->error('Failed to send weekly report: ' . $e->getMessage());

            return 1;
        }
    }
}