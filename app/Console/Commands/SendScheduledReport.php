<?php

namespace App\Console\Commands;

use App\Mail\ScheduledReport;
use App\Models\Setting;
use App\Services\ReportRowsService;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendScheduledReport extends Command
{
    protected $signature = 'reports:send
                            {--frequency= : daily, weekly, or monthly; defaults to the saved setting}
                            {--to= : End date for a manual run (YYYY-MM-DD)}
                            {--force : Resend the latest period even if it was already delivered}';

    protected $description = 'Email the configured attendance report for the most recently completed period';

    public function handle(ReportRowsService $reportRows, SubscriptionService $subscription): int
    {
        if (! $subscription->isSubscriptionActive()) {
            $this->error('The attendance licence is inactive; no scheduled report was sent.');

            return self::FAILURE;
        }

        $email = trim((string) Setting::getValue('report_email', ''));

        if ($email === '') {
            $this->error('No report email is configured.');

            return self::FAILURE;
        }

        $frequency = (string) ($this->option('frequency') ?: Setting::getValue('report_frequency', 'daily'));

        if (! in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            $this->error('Frequency must be daily, weekly, or monthly.');

            return self::INVALID;
        }

        try {
            $manualDate = $this->option('to');
            $periodEnd = $manualDate
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $manualDate, config('app.timezone'))
                : $this->lastCompletedPeriodEnd($frequency);

            if ($periodEnd === false) {
                throw new \InvalidArgumentException('The --to value must use YYYY-MM-DD.');
            }

            [$from, $to] = $this->period($frequency, $periodEnd);
            $periodKey = "{$frequency}:{$from}:{$to}";

            if (! $manualDate
                && ! $this->option('force')
                && Setting::getValue('scheduled_report_last_period', '') === $periodKey) {
                $this->info("The {$frequency} report for {$from} through {$to} was already sent.");

                return self::SUCCESS;
            }

            $rows = $reportRows->build([
                'date_from' => $from,
                'date_to' => $to,
            ]);

            Mail::to($email)->send(new ScheduledReport($frequency, $from, $to, $rows));

            if (! $manualDate) {
                Setting::setValue('scheduled_report_last_period', $periodKey);
            }

            $this->info("Attendance report sent to {$email} for {$from} through {$to}.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Log::error('Scheduled attendance report failed.', [
                'email' => $email,
                'frequency' => $frequency,
                'exception' => $exception,
            ]);
            $this->error('Failed to send the attendance report. Check the application log for details.');

            return self::FAILURE;
        }
    }

    private function lastCompletedPeriodEnd(string $frequency): CarbonImmutable
    {
        $today = now()->toImmutable()->startOfDay();

        return match ($frequency) {
            'weekly' => $today->startOfWeek()->subDay(),
            'monthly' => $today->startOfMonth()->subDay(),
            default => $today->subDay(),
        };
    }

    /** @return array{0: string, 1: string} */
    private function period(string $frequency, CarbonImmutable $periodEnd): array
    {
        $from = match ($frequency) {
            'weekly' => $periodEnd->startOfWeek(),
            'monthly' => $periodEnd->startOfMonth(),
            default => $periodEnd,
        };

        $to = match ($frequency) {
            'weekly' => $periodEnd->endOfWeek(),
            'monthly' => $periodEnd->endOfMonth(),
            default => $periodEnd,
        };

        return [$from->toDateString(), $to->toDateString()];
    }
}
