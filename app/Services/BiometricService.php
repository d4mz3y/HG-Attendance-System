<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BiometricService
{
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

        $today = Carbon::today();

        return DB::transaction(function () use ($staff, $today, $deviceId, $metadata) {
            $open = Attendance::query()
                ->where('staff_id', $staff->id)
                ->whereDate('date', $today)
                ->whereNull('clock_out')
                ->lockForUpdate()
                ->first();

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
}
