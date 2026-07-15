<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceAudit;
use App\Models\Staff;
use Carbon\Carbon;

class BreakService
{
    public function applyBreak(Attendance $row, int $breakMinutes): void
    {
        $row->break_minutes = max(0, $breakMinutes);
    }

    public function netHours(Attendance $row): ?float
    {
        if (! $row->clock_in || ! $row->clock_out) {
            return $row->total_hours;
        }

        $minutes = (int) $row->clock_in->diffInMinutes($row->clock_out);
        $break = (int) ($row->break_minutes ?? 0);
        $net = max(0, $minutes - $break);

        return round($net / 60, 2);
    }

    public function breakMinutesForAttendance(Attendance $attendance): int
    {
        $schedule = (new ScheduleService)->effectiveShift($attendance->staff, $attendance->date);

        return (int) ($attendance->break_minutes ?? $schedule['break_minutes'] ?? 60);
    }
}
