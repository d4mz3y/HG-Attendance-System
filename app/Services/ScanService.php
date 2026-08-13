<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\PublicHoliday;
use App\Models\Staff;
use App\Models\StaffEmploymentHistory;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ScanService
{
    private const MAX_SESSION_HOURS = 72;

    public function __construct(
        protected AppConfigService $config,
        protected AttendanceRulesService $rules,
        protected ScheduleService $schedules,
        protected SubscriptionService $subscription
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handleScan(string $rawCode, ?CarbonInterface $occurredAt = null): array
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

        $now = $occurredAt
            ? Carbon::parse($occurredAt->toIso8601String())->setTimezone(config('app.timezone'))
            : now();

        return DB::transaction(function () use ($staff, $now, $code) {
            // Serialize all state transitions for an individual member of
            // staff. The database's one-open-session constraint remains the
            // final line of defence if another writer bypasses this service.
            $staff = Staff::query()
                ->whereKey($staff->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals($staff->staff_id, $code)) {
                return [
                    'ok' => false,
                    'error' => 'not_found',
                    'message' => 'Staff not found',
                ];
            }

            $openSession = Attendance::query()
                ->where('staff_id', $staff->id)
                ->where('open_session', true)
                ->lockForUpdate()
                ->first();

            if ($openSession) {
                return $this->clockOut($staff, $openSession, $now);
            }

            $workDate = $this->resolveWorkDate($staff, $now);

            // A signed offline event is evaluated at the instant it occurred,
            // rather than against today's status. A person terminated since a
            // valid past scan may still have that evidence replayed; a fresh
            // scan today remains blocked because today is not an Active
            // employment interval.
            if (! $this->isWithinEmploymentDates($staff, $workDate)) {
                return [
                    'ok' => false,
                    'error' => 'inactive',
                    'message' => 'Access denied',
                ];
            }

            $lastClockOut = Attendance::query()
                ->where('staff_id', $staff->id)
                ->whereNotNull('clock_out')
                ->max('clock_out');

            if ($lastClockOut) {
                $lastClockOut = Carbon::parse($lastClockOut);
                $elapsedSinceClockOut = (int) $lastClockOut->diffInSeconds($now);
                $debounce = max(0, $this->config->scanDebounceSeconds());

                if ($now->greaterThanOrEqualTo($lastClockOut)
                    && $debounce > 0
                    && $elapsedSinceClockOut < $debounce) {
                    return [
                        'ok' => false,
                        'error' => 'debounce',
                        'message' => 'This scan was ignored because it occurred too soon after the previous scan.',
                    ];
                }
            }

            $hasLaterTransition = Attendance::query()
                ->where('staff_id', $staff->id)
                ->where(function ($query) use ($now): void {
                    $query->where('clock_in', '>=', $now)
                        ->orWhere('clock_out', '>=', $now);
                })
                ->exists();

            if ($hasLaterTransition) {
                return [
                    'ok' => false,
                    'error' => 'out_of_order',
                    'message' => 'This scan occurred before an attendance event that is already recorded. It requires manual review.',
                ];
            }

            if ($this->isOnApprovedLeave($staff, $workDate)) {
                return [
                    'ok' => false,
                    'error' => 'on_leave',
                    'message' => 'Staff is currently on approved leave. Clock-in is blocked.',
                ];
            }

            $shift = $this->schedules->effectiveShift($staff, $workDate);

            if ($shift['is_day_off']) {
                return [
                    'ok' => false,
                    'error' => 'day_off',
                    'message' => 'Today is a scheduled day off. An authorized manager must add any exceptional attendance.',
                ];
            }

            if (PublicHoliday::occursOn($workDate) && ! $shift['works_on_public_holiday']) {
                return [
                    'ok' => false,
                    'error' => 'public_holiday',
                    'message' => 'Staff is not scheduled to work on this public holiday.',
                ];
            }

            if (! $this->subscription->consumeClockInAllowance($staff->id, $now)) {
                return [
                    'ok' => false,
                    'error' => 'scan_cap_reached',
                    'message' => 'Daily scan limit reached. Upgrade to continue scanning.',
                ];
            }

            $sessionNumber = ((int) Attendance::query()
                ->where('staff_id', $staff->id)
                ->whereDate('date', $workDate)
                ->max('session_number')) + 1;

            try {
                $row = new Attendance([
                    'staff_id' => $staff->id,
                    'date' => $workDate,
                    'session_number' => $sessionNumber,
                    'clock_in' => $now,
                    'break_minutes' => (int) $shift['break_minutes'],
                ]);
                $this->rules->applyClockInRules($row, $staff);
                $row->save();
            } catch (QueryException $exception) {
                if ($this->isUniqueConstraintViolation($exception)) {
                    return [
                        'ok' => false,
                        'error' => 'scan_conflict',
                        'message' => 'Another scan was recorded at the same time. Refresh before trying again.',
                    ];
                }

                throw $exception;
            }

            return $this->successPayload($staff, 'in', $now, $row);
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function clockOut(Staff $staff, Attendance $session, Carbon $occurredAt): array
    {
        if (! $session->clock_in || $occurredAt->lessThan($session->clock_in)) {
            return [
                'ok' => false,
                'error' => 'out_of_order',
                'message' => 'Clock-out must occur after clock-in. This scan requires manual review.',
            ];
        }

        $elapsedSeconds = (int) $session->clock_in->diffInSeconds($occurredAt);

        if ($session->clock_in->diffInHours($occurredAt) > self::MAX_SESSION_HOURS) {
            return [
                'ok' => false,
                'error' => 'stale_open_session',
                'message' => 'This session has been open for more than 72 hours and requires an authorized manual correction.',
            ];
        }

        $debounce = max(0, $this->config->scanDebounceSeconds());

        if ($debounce > 0 && $elapsedSeconds < $debounce) {
            return [
                'ok' => false,
                'error' => 'debounce',
                'message' => 'This scan was ignored because it occurred too soon after the previous scan.',
            ];
        }

        if ($occurredAt->equalTo($session->clock_in)) {
            return [
                'ok' => false,
                'error' => 'out_of_order',
                'message' => 'Clock-out must occur after clock-in. This scan requires manual review.',
            ];
        }

        $laterTransitionExists = Attendance::query()
            ->where('staff_id', $staff->id)
            ->whereKeyNot($session->id)
            ->where(function ($query) use ($occurredAt): void {
                $query->where('clock_in', '>=', $occurredAt)
                    ->orWhere('clock_out', '>=', $occurredAt);
            })
            ->exists();

        if ($laterTransitionExists) {
            return [
                'ok' => false,
                'error' => 'out_of_order',
                'message' => 'This clock-out precedes a newer attendance event and requires manual review.',
            ];
        }

        $cooldown = max(0, $this->config->scanCooldownSeconds());

        if ($cooldown > 0 && $elapsedSeconds < $cooldown) {
            $minutesRemaining = (int) ceil(($cooldown - $elapsedSeconds) / 60);

            return [
                'ok' => false,
                'error' => 'cooldown',
                'message' => "Clock-out is locked for {$minutesRemaining} more minute(s) after clock-in. Please wait before clocking out.",
            ];
        }

        $session->clock_out = $occurredAt;
        $this->rules->applyClockOutRules($session, $staff);

        try {
            $session->save();
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return [
                    'ok' => false,
                    'error' => 'scan_conflict',
                    'message' => 'Another scan changed this attendance session. Refresh before trying again.',
                ];
            }

            throw $exception;
        }

        $this->subscription->recordScan($staff->id, $occurredAt);

        return $this->successPayload($staff, 'out', $session->clock_out, $session);
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

    private function isOnApprovedLeave(Staff $staff, CarbonInterface $date): bool
    {
        return Leave::query()
            ->where('staff_id', $staff->id)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->where('status', 'Approved')
            ->exists();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique')
            || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }

    private function isWithinEmploymentDates(Staff $staff, CarbonInterface $date): bool
    {
        if ($staff->employmentHistory()->exists()) {
            return StaffEmploymentHistory::query()
                ->where('staff_id', $staff->id)
                ->where('status', 'Active')
                ->whereDate('effective_from', '<=', $date->toDateString())
                ->where(function ($query) use ($date): void {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $date->toDateString());
                })
                ->exists();
        }

        return $staff->isActive()
            && (! $staff->employment_start_date || $date->toDateString() >= $staff->employment_start_date->toDateString())
            && (! $staff->employment_end_date || $date->toDateString() <= $staff->employment_end_date->toDateString());
    }

    private function resolveWorkDate(Staff $staff, Carbon $occurredAt): Carbon
    {
        $calendarDate = $occurredAt->copy()->startOfDay();
        $previousDate = $calendarDate->copy()->subDay();
        $previousShift = $this->schedules->effectiveShift($staff, $previousDate);

        if ($previousShift['is_day_off']
            || ! $previousShift['is_overnight']
            || ! $previousShift['shift_start']
            || ! $previousShift['shift_end']) {
            return $calendarDate;
        }

        $previousStart = Carbon::parse(
            $previousDate->toDateString().' '.$previousShift['shift_start'],
            $occurredAt->timezone
        );
        $previousEnd = Carbon::parse(
            $previousDate->toDateString().' '.$previousShift['shift_end'],
            $occurredAt->timezone
        )->addDay();

        return $occurredAt->betweenIncluded($previousStart, $previousEnd)
            ? $previousDate
            : $calendarDate;
    }
}
