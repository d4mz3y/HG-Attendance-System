<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;

class AppConfigService
{
    public function shiftStart(): Carbon
    {
        return Carbon::createFromFormat('H:i', $this->string('shift_start', '08:00'));
    }

    public function shiftEnd(): Carbon
    {
        return Carbon::createFromFormat('H:i', $this->string('shift_end', '17:00'));
    }

    public function scanDebounceSeconds(): int
    {
        return $this->integer('scan_debounce_seconds', 2);
    }

    public function scanCooldownSeconds(): int
    {
        return $this->integer('scan_cooldown_seconds', 1800);
    }

    public function branchLabel(): string
    {
        return $this->string('branch_label', 'Headquarters');
    }

    public function gracePeriodMinutes(): int
    {
        return $this->integer('grace_period_minutes', 0);
    }

    public function defaultBreakMinutes(): int
    {
        return $this->integer('default_break_minutes', 60);
    }

    public function alertsEnabled(): bool
    {
        return $this->boolean('enable_alerts', true);
    }

    public function missedClockOutAlertMinutes(): int
    {
        return $this->integer('missed_clock_out_alert_minutes', 60);
    }

    public function absenceAlertMinutes(): int
    {
        return $this->integer('absence_alert_minutes', 60);
    }

    public function scheduledReportsEnabled(): bool
    {
        return $this->boolean('enable_scheduled_reports', false);
    }

    public function offlineMaxAgeHours(): int
    {
        return $this->integer('offline_max_age_hours', 72);
    }

    public function scanClockSkewSeconds(): int
    {
        return $this->integer('scan_clock_skew_seconds', 300);
    }

    /** @return string[] */
    public function allowedScanCidrs(): array
    {
        return array_values(array_filter(
            preg_split('/[,;\s]+/', trim($this->string('scan_allowed_ips', ''))) ?: []
        ));
    }

    /** @return int[] */
    public function defaultWorkDays(): array
    {
        $days = json_decode($this->string('default_work_days', '[1,2,3,4,5]'), true);

        return array_values(array_unique(array_map('intval', is_array($days) ? $days : [])));
    }

    /** @return array<string, mixed> */
    public function allSettings(): array
    {
        return [
            'shift_start' => $this->string('shift_start', '08:00'),
            'shift_end' => $this->string('shift_end', '17:00'),
            'default_work_days' => $this->defaultWorkDays(),
            'scan_debounce_seconds' => $this->scanDebounceSeconds(),
            'scan_cooldown_seconds' => $this->scanCooldownSeconds(),
            'offline_max_age_hours' => $this->offlineMaxAgeHours(),
            'scan_clock_skew_seconds' => $this->scanClockSkewSeconds(),
            'branch_label' => $this->branchLabel(),
            'grace_period_minutes' => $this->gracePeriodMinutes(),
            'default_break_minutes' => $this->defaultBreakMinutes(),
            'enable_alerts' => $this->alertsEnabled(),
            'missed_clock_out_alert_minutes' => $this->missedClockOutAlertMinutes(),
            'absence_alert_minutes' => $this->absenceAlertMinutes(),
            'enable_scheduled_reports' => $this->scheduledReportsEnabled(),
            'report_email' => $this->string('report_email', ''),
            'report_frequency' => $this->string('report_frequency', 'daily'),
            'scan_allowed_ips' => $this->string('scan_allowed_ips', ''),
            'dark_mode_default' => $this->boolean('dark_mode_default', false),
        ];
    }

    private function string(string $key, string $default): string
    {
        return (string) Setting::getValue($key, $default);
    }

    private function integer(string $key, int $default): int
    {
        return (int) Setting::getValue($key, (string) $default);
    }

    private function boolean(string $key, bool $default): bool
    {
        return filter_var(
            Setting::getValue($key, $default ? '1' : '0'),
            FILTER_VALIDATE_BOOL
        );
    }
}
