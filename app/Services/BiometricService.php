<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Staff;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BiometricService
{
    public function __construct(
        protected ScheduleService $schedules
    ) {}

    public function handlePunch(string $identifier, string $deviceId, ?array $metadata = null): array
    {
        $staff = Staff::query()
            ->where('staff_id', $identifier)
            ->orWhere('id', $identifier)
            ->first();

        if (! $staff) {
            return ['ok' => false, 'error' => 'not_found', 'message' => 'Staff not found'];
        }

        if (! $staff->isActive()) {
            return ['ok' => false, 'error' => 'inactive', 'message' => 'Access denied'];
        }

        if ($this->isOnLeave($staff)) {
            return ['ok' => false, 'error' => 'on_leave', 'message' => 'Staff is currently on approved leave. Punch is blocked.'];
        }

        $today = Carbon::today();

        return DB::transaction(function () use ($staff, $today, $deviceId, $metadata) {
            $open = Attendance::query()
                ->where('staff_id', $staff->id)
                ->whereDate('date', $today)
                ->whereNull('clock_out')
                ->lockForUpdate()
                ->first();

            $isDayOff = $this->schedules->effectiveShift($staff, $today)['is_day_off'] ?? false;

            if ($isDayOff && ! $open) {
                return [
                    'ok' => true,
                    'action' => 'in',
                    'timestamp' => now()->toIso8601String(),
                    'staff_id' => $staff->staff_id,
                    'full_name' => $staff->full_name,
                    'device_id' => $deviceId,
                ];
            }

            $now = now();

            if ($open) {
                $open->clock_out = $now;
                $open->save();

                return [
                    'ok' => true,
                    'action' => 'out',
                    'timestamp' => $now->toIso8601String(),
                    'staff_id' => $staff->staff_id,
                    'full_name' => $staff->full_name,
                    'device_id' => $deviceId,
                ];
            }

            $row = new Attendance([
                'staff_id' => $staff->id,
                'date' => $today,
                'clock_in' => $now,
            ]);
            $row->save();

            return [
                'ok' => true,
                'action' => 'in',
                'timestamp' => $now->toIso8601String(),
                'staff_id' => $staff->staff_id,
                'full_name' => $staff->full_name,
                'device_id' => $deviceId,
            ];
        });
    }

    private function isOnLeave(Staff $staff): bool
    {
        $today = Carbon::today();

        return Leave::query()
            ->where('staff_id', $staff->id)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->whereIn('status', ['Pending', 'Approved'])
            ->exists();
    }
}