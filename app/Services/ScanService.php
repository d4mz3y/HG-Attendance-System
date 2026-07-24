<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ScanService
{
    public function __construct(
        protected AppConfigService $config,
        protected AttendanceRulesService $rules,
        protected ScheduleService $schedules
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handleScan(string $rawCode): array
    {
        $code = trim($rawCode);
        if ($code === '') {
            return ['ok' => false, 'error' => 'empty', 'message' => 'Empty scan'];
        }

        $staff = Staff::query()->where('staff_id', $code)->first();
        if (! $staff) {
            return [
                'ok' => false,
                'error' => 'not_found',
                'message' => 'Staff not found',
            ];
        }

        if (! $staff->isActive()) {
            return [
                'ok' => false,
                'error' => 'inactive',
                'message' => 'Access denied',
            ];
        }

        if ($this->isOnLeave($staff)) {
            return [
                'ok' => false,
                'error' => 'on_leave',
                'message' => 'Staff is currently on approved leave. Clock-in is blocked.',
            ];
        }

        $today = Carbon::today();

        return DB::transaction(function () use ($staff, $today) {
            if (! Cache::add('scan_rapid_'.$staff->id, 1, 1)) {
                return [
                    'ok' => false,
                    'error' => 'debounce',
                    'message' => 'Scanner sent reads too quickly. Please try again.',
                ];
            }

            $open = Attendance::query()
                ->where('staff_id', $staff->id)
                ->whereDate('date', $today)
                ->whereNull('clock_out')
                ->lockForUpdate()
                ->first();

            $debounce = max(0, $this->config->scanDebounceSeconds());

            if (! $open && $debounce > 0) {
                $lastClosed = Attendance::query()
                    ->where('staff_id', $staff->id)
                    ->whereDate('date', $today)
                    ->whereNotNull('clock_out')
                    ->orderByDesc('clock_out')
                    ->lockForUpdate()
                    ->first();

                if ($lastClosed && $lastClosed->clock_out->diffInSeconds(now()) < $debounce) {
                    Cache::forget('scan_rapid_'.$staff->id);

                    return [
                        'ok' => false,
                        'error' => 'debounce',
                        'message' => 'Please wait before scanning again (duplicate / re-entry protection).',
                    ];
                }
            }

            $now = now();
            $isDayOff = $this->schedules->effectiveShift($staff, $today)['is_day_off'] ?? false;

            if ($open) {
                $open->clock_out = $now;
                $this->rules->applyClockOutRules($open);
                $open->save();

                return $this->successPayload($staff, 'out', $open->clock_out, $open);
            }

            $row = new Attendance([
                'staff_id' => $staff->id,
                'date' => $today,
                'clock_in' => $now,
            ]);
            $this->rules->applyClockInRules($row);

            if ($isDayOff) {
                $row->is_late = false;
                $row->late_minutes = 0;
            }

            $row->save();

            return $this->successPayload($staff, 'in', $now, $row);
        });
    }

/**
     * @return array<string, mixed>
     */
    protected function successPayload(Staff $staff, string $action, Carbon $at, Attendance $row): array
    {
        return [
            'ok' => true,
            'action' => $action,
            'timestamp' => $at->toIso8601String(),
            'staff' => [
                'staff_id' => $staff->staff_id,
                'full_name' => $staff->full_name,
                'department' => $staff->department,
                'job_title' => $staff->job_title,
                'photo_url' => $staff->photo_url,
            ],
            'attendance' => [
                'is_late' => (bool) $row->is_late,
                'late_minutes' => (int) $row->late_minutes,
                'overtime_minutes' => (int) ($row->overtime_minutes ?? 0),
                'total_hours' => $row->total_hours,
            ],
        ];
    }
}
