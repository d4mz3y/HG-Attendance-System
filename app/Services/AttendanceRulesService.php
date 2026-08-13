<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\PublicHoliday;
use App\Models\Staff;
use Carbon\Carbon;

class AttendanceRulesService
{
    public function __construct(
        protected AppConfigService $config,
        protected ScheduleService $schedules
    ) {}

    public function applyClockInRules(Attendance $row, ?Staff $staff = null): void
    {
        $shift = $staff
            ? $this->schedules->effectiveShift($staff, $row->date)
            : null;

        if (($shift['is_day_off'] ?? false)
            || ($this->isHoliday($row->date) && ! ($shift['works_on_public_holiday'] ?? false))
            || (int) ($row->session_number ?? 1) > 1) {
            $row->is_late = false;
            $row->late_minutes = 0;

            return;
        }

        $shiftStart = $shift['shift_start'] ?? $this->config->shiftStart()->format('H:i');
        if ($shiftStart instanceof \DateTimeInterface) {
            $shiftStart = $shiftStart->format('H:i');
        }
        $shiftStart = substr((string) $shiftStart, 0, 5);

        $clockIn = $row->clock_in->copy();
        $grace = $this->config->gracePeriodMinutes();

        if ($shiftStart === '') {
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

        $minutes = (int) $row->clock_in->diffInMinutes($row->clock_out);
        $break = min($minutes, (int) ($row->break_minutes ?? 0));
        $row->total_hours = round(max(0, $minutes - $break) / 60, 2);

        if (($shift['is_day_off'] ?? false)
            || ($this->isHoliday($row->date) && ! ($shift['works_on_public_holiday'] ?? false))) {
            $row->overtime_minutes = max(0, $minutes - $break);

            return;
        }

        $shiftEnd = $shift['shift_end'] ?? $this->config->shiftEnd()->format('H:i');
        if ($shiftEnd instanceof \DateTimeInterface) {
            $shiftEnd = $shiftEnd->format('H:i');
        }
        $shiftEnd = substr((string) $shiftEnd, 0, 5);

        $clockOut = $row->clock_out->copy();

        if ($shiftEnd === '') {
            $row->overtime_minutes = 0;

            return;
        }

        $boundary = Carbon::parse($row->date->format('Y-m-d').' '.$shiftEnd, $clockOut->timezone);
        $shiftStart = $shift['shift_start'] ?? $this->config->shiftStart()->format('H:i');
        if ($shiftStart instanceof \DateTimeInterface) {
            $shiftStart = $shiftStart->format('H:i');
        }
        $shiftStart = substr((string) $shiftStart, 0, 5);

        if ($shiftStart !== '' && $shiftEnd <= $shiftStart) {
            $boundary->addDay();
        }

        if ($clockOut->greaterThan($boundary)) {
            $row->overtime_minutes = (int) $boundary->diffInMinutes($clockOut);
        } else {
            $row->overtime_minutes = 0;
        }

    }

    public function isHoliday(Carbon $date): bool
    {
        return PublicHoliday::occursOn($date);
    }
}
