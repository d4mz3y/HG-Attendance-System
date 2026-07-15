<?php

namespace App\Console\Commands;

use App\Mail\ScheduledReport;
use App\Models\Setting;
use App\Services\ReportRowsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;

class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled';
    protected $description = 'Send scheduled attendance reports to configured recipients';

    public function handle(ReportRowsService $rowsService): int
    {
        if (Setting::getValue('enable_scheduled_reports', '0') !== '1') {
            $this->info('Scheduled reports are disabled.');

            return 0;
        }

        $email = Setting::getValue('report_email');
        $frequency = Setting::getValue('report_frequency', 'daily');

        if (! $email) {
            $this->warn('No report email configured.');

            return 0;
        }

        $filters = $this->buildFilters($frequency);
        $rows = $rowsService->build($filters);

        if ($rows->isEmpty()) {
            $this->info('No data for the selected period.');

            return 0;
        }

        try {
            Mail::to($email)->send(new ScheduledReport($filters, $rows));
            $this->info("Report sent to {$email}.");
        } catch (\Throwable $e) {
            $this->error('Failed to send report: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    private function buildFilters(string $frequency): array
    {
        $to = now();

        return match ($frequency) {
            'weekly' => [
                'date_from' => $to->copy()->subWeek()->startOfDay()->toDateString(),
                'date_to' => $to->copy()->endOfDay()->toDateString(),
                'department' => null,
                'staff_pk' => null,
                'status' => null,
            ],
            'monthly' => [
                'date_from' => $to->copy()->subMonth()->startOfMonth()->toDateString(),
                'date_to' => $to->copy()->endOfMonth()->toDateString(),
                'department' => null,
                'staff_pk' => null,
                'status' => null,
            ],
            default => [
                'date_from' => $to->copy()->startOfDay()->toDateString(),
                'date_to' => $to->copy()->endOfDay()->toDateString(),
                'department' => null,
                'staff_pk' => null,
                'status' => null,
            ],
        };
    }
}
