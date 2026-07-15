<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\Staff;
use Carbon\Carbon;

class LeaveService
{
    public function upcomingForStaff(Staff $staff, int $days = 30): array
    {
        $from = Carbon::today();
        $to = $from->copy()->addDays($days);

        return Leave::query()
            ->where('staff_id', $staff->id)
            ->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', ['Pending', 'Approved'])
            ->orderBy('start_date')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'start_date' => $l->start_date->toDateString(),
                'end_date' => $l->end_date->toDateString(),
                'type' => $l->type,
                'reason' => $l->reason,
                'status' => $l->status,
            ])
            ->all();
    }

    public function isOnLeave(Staff $staff, Carbon $date): bool
    {
        return Leave::query()
            ->where('staff_id', $staff->id)
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->whereIn('status', ['Pending', 'Approved'])
            ->exists();
    }

    public function approvedBetween(string $from, string $to, ?string $department = null): array
    {
        $q = Leave::query()
            ->with('staff')
            ->whereBetween('start_date', [$from, $to])
            ->where('status', 'Approved');

        if ($department) {
            $q->whereHas('staff', fn ($s) => $s->where('department', $department));
        }

        return $q->orderByDesc('start_date')->get()->map(fn ($l) => [
            'id' => $l->id,
            'start_date' => $l->start_date->toDateString(),
            'end_date' => $l->end_date->toDateString(),
            'type' => $l->type,
            'status' => $l->status,
            'staff' => $l->staff ? [
                'id' => $l->staff->id,
                'full_name' => $l->staff->full_name,
                'staff_id' => $l->staff->staff_id,
                'department' => $l->staff->department,
            ] : null,
        ])->all();
    }
}
