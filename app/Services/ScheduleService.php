<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\StaffSchedule;
use Carbon\Carbon;

class ScheduleService
{
    public function effectiveShift(Staff $staff, Carbon $date): array
    {
        $dayOfWeek = (int) $date->format('w');

        $schedule = StaffSchedule::query()
            ->where('staff_id', $staff->id)
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if ($schedule && $schedule->is_day_off) {
            return [
                'is_day_off' => true,
                'shift_start' => null,
                'shift_end' => null,
                'break_minutes' => 0,
            ];
        }

        if ($schedule && $schedule->shift_start && $schedule->shift_end) {
            return [
                'is_day_off' => false,
                'shift_start' => $schedule->shift_start,
                'shift_end' => $schedule->shift_end,
                'break_minutes' => (int) $schedule->break_minutes,
            ];
        }

        return [
            'is_day_off' => false,
            'shift_start' => null,
            'shift_end' => null,
            'break_minutes' => 60,
        ];
    }

    public function forStaff(int $staffId): array
    {
        return StaffSchedule::query()
            ->where('staff_id', $staffId)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'day_of_week' => (int) $s->day_of_week,
                'day_name' => Carbon::create(2024, 1, 7 + (int) $s->day_of_week)->format('l'),
                'shift_start' => $s->shift_start ? $s->shift_start->format('H:i') : null,
                'shift_end' => $s->shift_end ? $s->shift_end->format('H:i') : null,
                'break_minutes' => (int) $s->break_minutes,
                'is_day_off' => (bool) $s->is_day_off,
                'works_on_public_holiday' => (bool) ($s->works_on_public_holiday ?? false),
            ])
            ->all();
    }

    public function upsert(int $staffId, array $items): void
    {
        foreach ($items as $item) {
            StaffSchedule::query()->updateOrCreate(
                [
                    'staff_id' => $staffId,
                    'day_of_week' => (string) $item['day_of_week'],
                ],
                [
                    'shift_start' => $item['shift_start'] ?? null,
                    'shift_end' => $item['shift_end'] ?? null,
                    'break_minutes' => (int) ($item['break_minutes'] ?? 60),
                    'is_day_off' => (bool) ($item['is_day_off'] ?? false),
                    'works_on_public_holiday' => (bool) ($item['works_on_public_holiday'] ?? false),
                ]
            );
        }
    }
}
