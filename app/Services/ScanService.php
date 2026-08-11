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
        protected ScheduleService $schedules,
        protected SubscriptionService $subscription
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

        if ($this->isOnApprovedLeave($staff)) {
            return [
                'ok' => false,
                'error' => 'on_leave',
                'message' => 'Staff is currently on approved leave. Clock-in is blocked.',
            ];
        }

        if (! $this->subscription->isSubscriptionActive()) {
            $remaining = $this->subscription->getScanCapRemaining($staff->id);

            if ($remaining <= 0) {
                return [
                    'ok' => false,
                    'error' => 'scan_cap_reached',
                    'message' => 'Daily scan limit reached. Upgrade to continue scanning.',
                ];
            }
        }

        $today = Carbon::today();
        $now = now();

        return DB::transaction(function () use ($staff, $today, $now) {
            $todayRecords = Attendance::query()
                ->where('staff_id', $staff->id)
                ->whereDate('date', $today)
                ->lockForUpdate()
                ->get();

            $count = $todayRecords->count();

            if ($count === 0) {
                if ($this->isOnApprovedLeave($staff)) {
                    return [
                        'ok' => false,
                        'error' => 'on_leave',
                        'message' => 'Staff is currently on approved leave. Clock-in is blocked.',
                    ];
                }

                try {
                    $row = new Attendance([
                        'staff_id' => $staff->id,
                        'date' => $today,
                        'clock_in' => $now,
                    ]);
                    $this->rules->applyClockInRules($row, $staff);

                    $isDayOff = $this->schedules->effectiveShift($staff, $today)['is_day_off'] ?? false;
                    if ($isDayOff) {
                        $row->is_late = false;
                        $row->late_minutes = 0;
                    }

                    $row->save();
                    $this->subscription->recordScan($staff->id);

                    return $this->successPayload($staff, 'in', $now, $row);
                } catch (\Illuminate\Database\QueryException $e) {
                    if (str_contains($e->getMessage(), 'SQLSTATE[23000]') || str_contains($e->getMessage(), 'Duplicate')) {
                        $row = Attendance::query()
                            ->where('staff_id', $staff->id)
                            ->whereDate('date', $today)
                            ->first();

                        if ($row && $row->clock_out) {
                            return [
                                'ok' => false,
                                'error' => 'already_signed_out',
                                'message' => 'You\'ve already signed out for today. Try again tomorrow.',
                            ];
                        }

                        return $this->successPayload($staff, 'in', $row->clock_in, $row);
                    }

                    throw $e;
                }
            }

            $lastRecord = $todayRecords->sortByDesc('clock_in')->first();

            if ($count >= 2) {
                return [
                    'ok' => false,
                    'error' => 'already_signed_out',
                    'message' => 'You\'ve already signed out for today. Try again tomorrow.',
                ];
            }

            if ($lastRecord->clock_out !== null) {
                return [
                    'ok' => false,
                    'error' => 'already_signed_out',
                    'message' => 'You\'ve already signed out for today. Try again tomorrow.',
                ];
            }

            $cooldown = max(0, $this->config->scanCooldownSeconds());

            if ($cooldown > 0 && $lastRecord->clock_in && $now->diffInSeconds($lastRecord->clock_in) < $cooldown) {
                $minutesRemaining = (int) ceil(($cooldown - $now->diffInSeconds($lastRecord->clock_in)) / 60);

                return [
                    'ok' => false,
                    'error' => 'cooldown',
                    'message' => "Clock-out is locked for {$minutesRemaining} more minute(s) after clock-in. Please wait before clocking out.",
                ];
            }

            $lastRecord->clock_out = $now;
            $this->rules->applyClockOutRules($lastRecord, $staff);
$lastRecord->save();
                $this->subscription->recordScan($staff->id);

                return $this->successPayload($staff, 'out', $lastRecord->clock_out, $lastRecord);
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

    private function isOnApprovedLeave(Staff $staff): bool
    {
        $today = Carbon::today();

        return Leave::query()
            ->where('staff_id', $staff->id)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->where('status', 'Approved')
            ->exists();
    }
}