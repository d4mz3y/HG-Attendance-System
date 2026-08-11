<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\PublicHoliday;
use App\Models\Setting;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceRulesService
{
    public function __construct(
        protected AppConfigService $config,
        protected ScheduleService $schedules
    ) {}

    public function applyClockInRules(Attendance $row, ?Staff $staff = null): void
    {
        if ($this->isHoliday($row->date)) {
            $row->is_late = false;
            $row->late_minutes = 0;
            return;
        }

        $shift = $staff
            ? $this->schedules->effectiveShift($staff, $row->date)
            : null;

        $shiftStart = $shift['shift_start'] ?? $this->config->shiftStart()->format('H:i');
        if ($shiftStart instanceof \DateTimeInterface) {
            $shiftStart = $shiftStart->format('H:i');
        }
        $shiftStart = substr((string) $shiftStart, 0, 5);

        $clockIn = $row->clock_in->copy();
        $grace = (int) Setting::getValue('grace_period_minutes', '0');

        if ($shiftStart === '' || $shiftStart === null) {
            $row->is_late = false;
            $row->late_minutes = 0;
            return;
        }

        $expected = Carbon::parse($row->date->format('Y-m-d').' '.$shiftStart, $clockIn->timezone);
        $graceBoundary = $expected->copy()->addMinutes($grace);

        if ($clockIn->greaterThan($graceBoundary)) {
            $row->is_late = true;
            $row->late_minutes = (int) $expected->diffInMinutes($clockIn);
        } else {
            $row->is_late = false;
            $row->late_minutes = 0;
        }
    }

    public function applyClockOutRules(Attendance $row, ?Staff $staff = null): void
    {
        if (! $row->clock_out) {
            return;
        }

        $shift = $staff
            ? $this->schedules->effectiveShift($staff, $row->date)
            : null;

        $shiftEnd = $shift['shift_end'] ?? $this->config->shiftEnd()->format('H:i');
        if ($shiftEnd instanceof \DateTimeInterface) {
            $shiftEnd = $shiftEnd->format('H:i');
        }
        $shiftEnd = substr((string) $shiftEnd, 0, 5);

        $clockOut = $row->clock_out->copy();

        if ($shiftEnd === '' || $shiftEnd === null) {
            $row->overtime_minutes = 0;
            $minutes = (int) $row->clock_in->diffInMinutes($row->clock_out);
            $break = (int) ($row->break_minutes ?? 0);
            $row->total_hours = round(max(0, $minutes - $break) / 60, 2);
            return;
        }

        $boundary = Carbon::parse($row->date->format('Y-m-d').' '.$shiftEnd, $clockOut->timezone);

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
        $monthDay = $date->format('m-d');

        return PublicHoliday::query()
            ->where(function ($q) use ($dateStr, $monthDay) {
                $q->whereDate('date', $dateStr)
                  ->orWhere(function ($q2) use ($monthDay) {
                      $q2->where('is_recurring', true)
                         ->whereRaw("DATE_FORMAT(date, '%m-%d') = ?", [$monthDay]);
                  });
            })
            ->exists();
    }
}
