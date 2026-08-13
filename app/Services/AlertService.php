<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\PublicHoliday;
use App\Models\Staff;
use Carbon\Carbon;

class AlertService
{
    public function __construct(
        private readonly AppConfigService $config,
        private readonly ScheduleService $schedules
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function missedClockOuts(): array
    {
        if (! $this->config->alertsEnabled()) {
            return [];
        }

        $now = now();

        return Attendance::query()
            ->with('staff')
            ->whereNull('clock_out')
            ->where('clock_in', '<=', $now)
            ->get()
            ->filter(function (Attendance $attendance) use ($now): bool {
                if (! $attendance->staff) {
                    return false;
                }

                $shift = $this->schedules->effectiveShift($attendance->staff, $attendance->date);
                $endTime = $shift['shift_end'] ?: $this->config->shiftEnd()->format('H:i');
                $startTime = $shift['shift_start'] ?: $this->config->shiftStart()->format('H:i');
                $expectedEnd = Carbon::parse($attendance->date->toDateString().' '.$endTime, $now->timezone);
                if (($shift['is_overnight'] ?? false) || $endTime <= $startTime) {
                    $expectedEnd->addDay();
                }

                return $now->greaterThanOrEqualTo(
                    $expectedEnd->addMinutes($this->config->missedClockOutAlertMinutes())
                );
            })
            ->map(fn (Attendance $attendance) => [
                'id' => $attendance->id,
                'staff_id' => $attendance->staff_id,
                'staff_name' => $attendance->staff?->full_name,
                'staff_code' => $attendance->staff?->staff_id,
                'department' => $attendance->staff?->department,
                'clock_in' => $attendance->clock_in?->toIso8601String(),
                'minutes_open' => max(0, (int) $attendance->clock_in?->diffInMinutes(now())),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function absentToday(): array
    {
        if (! $this->config->alertsEnabled()) {
            return [];
        }

        $today = today();
        $now = now();
        $presentIds = Attendance::query()->whereDate('date', $today)->pluck('staff_id');

        return Staff::query()
            ->where('employment_status', 'Active')
            ->where(function ($query) use ($today): void {
                $query->whereNull('employment_start_date')
                    ->orWhereDate('employment_start_date', '<=', $today->toDateString());
            })
            ->where(function ($query) use ($today): void {
                $query->whereNull('employment_end_date')
                    ->orWhereDate('employment_end_date', '>=', $today->toDateString());
            })
            ->whereNotIn('id', $presentIds)
            ->get()
            ->filter(function (Staff $staff) use ($today, $now): bool {
                $shift = $this->schedules->effectiveShift($staff, $today);
                if ($shift['is_day_off']) {
                    return false;
                }

                if (PublicHoliday::occursOn($today) && ! $shift['works_on_public_holiday']) {
                    return false;
                }

                if (Leave::query()
                    ->where('staff_id', $staff->id)
                    ->where('status', 'Approved')
                    ->whereDate('start_date', '<=', $today)
                    ->whereDate('end_date', '>=', $today)
                    ->exists()) {
                    return false;
                }

                $startTime = $shift['shift_start'] ?: $this->config->shiftStart()->format('H:i');
                $alertAt = Carbon::parse($today->toDateString().' '.$startTime, $now->timezone)
                    ->addMinutes($this->config->absenceAlertMinutes());

                return $now->greaterThanOrEqualTo($alertAt);
            })
            ->map(fn (Staff $staff) => [
                'id' => $staff->id,
                'staff_id' => $staff->id,
                'staff_name' => $staff->full_name,
                'staff_code' => $staff->staff_id,
                'department' => $staff->department,
            ])
            ->values()
            ->all();
    }

    /**
     * Return today's late arrivals in the same compact shape as the other
     * dashboard alert rails. The attendance rule already accounts for each
     * person's shift and any configured grace period, so this is deliberately
     * a read-only presentation of that decision.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lateClockInsToday(): array
    {
        if (! $this->config->alertsEnabled()) {
            return [];
        }

        return Attendance::query()
            ->with('staff')
            ->whereDate('date', today())
            ->where('is_late', true)
            ->orderBy('clock_in')
            ->get()
            ->filter(fn (Attendance $attendance): bool => $attendance->staff !== null)
            ->map(fn (Attendance $attendance) => [
                'id' => $attendance->id,
                'staff_id' => $attendance->staff_id,
                'staff_name' => $attendance->staff?->full_name,
                'staff_code' => $attendance->staff?->staff_id,
                'department' => $attendance->staff?->department,
                'clock_in' => $attendance->clock_in?->toIso8601String(),
                'late_minutes' => (int) $attendance->late_minutes,
            ])
            ->values()
            ->all();
    }
}
