<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Staff;
use App\Models\PublicHoliday;
use Carbon\Carbon;

class AttendanceRulesService
{
    public function __construct(
        protected AppConfigService $config
    ) {}

    public function applyClockInRules(Attendance $row): void
    {
        if ($this->isHoliday($row->date)) {
            $row->is_late = false;
            $row->late_minutes = 0;
            return;
        }

        $shiftStart = $this->config->shiftStart();
        $clockIn = $row->clock_in->copy();
        $grace = (int) Setting::getValue('grace_period_minutes', '0');

        $expected = Carbon::parse($row->date->format('Y-m-d').' '.$shiftStart->format('H:i:s'), $clockIn->timezone);
        $graceBoundary = $expected->copy()->addMinutes($grace);

        if ($clockIn->greaterThan($graceBoundary)) {
            $row->is_late = true;
            $row->late_minutes = (int) $expected->diffInMinutes($clockIn);
        } else {
            $row->is_late = false;
            $row->late_minutes = 0;
        }
    }

    public function applyClockOutRules(Attendance $row): void
    {
        if (! $row->clock_out) {
            return;
        }

        $shiftEnd = $this->config->shiftEnd();
        $clockOut = $row->clock_out->copy();

        $boundary = Carbon::parse($row->date->format('Y-m-d').' '.$shiftEnd->format('H:i:s'), $clockOut->timezone);

        if ($clockOut->greaterThan($boundary)) {
            $row->overtime_minutes = (int) $boundary->diffInMinutes($clockOut);
        } else {
            $row->overtime_minutes = 0;
        }

        $minutes = (int) $row->clock_in->diffInMinutes($row->clock_out);
        $break = (int) ($row->break_minutes ?? 0);
        $row->total_hours = round(max(0, $minutes - $break) / 60, 2);
    }

    private function isHoliday(Carbon $date): bool
    {
        $dateStr = $date->toDateString();
        
        return PublicHoliday::query()
            ->where(function ($q) use ($dateStr) {
                $q->whereDate('date', $dateStr)
                  ->orWhere('is_recurring', true);
            })
            ->exists();
    }
}
