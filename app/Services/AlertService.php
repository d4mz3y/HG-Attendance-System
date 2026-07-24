<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class AlertService
{
    public function missedClockOuts(): array
    {
        $today = Carbon::today();
        $cutoff = Carbon::now()->subHours(2);

        $rows = Attendance::query()
            ->with('staff')
            ->whereDate('date', $today)
            ->whereNull('clock_out')
            ->where('clock_in', '<', $cutoff)
            ->get();

        return $rows->map(fn ($a) => [
            'id' => $a->id,
            'staff_id' => $a->staff_id,
            'staff_name' => $a->staff?->full_name,
            'staff_code' => $a->staff?->staff_id,
            'department' => $a->staff?->department,
            'clock_in' => $a->clock_in?->toIso8601String(),
            'hours_open' => Carbon::now()->diffInMinutes($a->clock_in),
        ])->all();
    }

    public function absentToday(): array
    {
        $today = Carbon::today();
        $cutoff = Carbon::now()->subHours(2);

        $presentIds = Attendance::query()
            ->whereDate('date', $today)
            ->pluck('staff_id')
            ->all();

        $dayOffIds = Staff::query()
            ->where('employment_status', 'Active')
            ->whereNotIn('id', $presentIds)
            ->get()
            ->filter(fn ($s) => app(ScheduleService::class)->effectiveShift($s, $today)['is_day_off'])
            ->pluck('id')
            ->all();

        return Staff::query()
            ->where('employment_status', 'Active')
            ->whereNotIn('id', $presentIds)
            ->whereNotIn('id', $dayOffIds)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'staff_id' => $s->id,
                'staff_name' => $s->full_name,
                'staff_code' => $s->staff_id,
                'department' => $s->department,
            ])
            ->all();
    }
}
