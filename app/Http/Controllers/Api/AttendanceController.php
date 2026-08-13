<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Staff;
use App\Services\AppConfigService;
use App\Services\AttendanceRulesService;
use App\Services\AuditService;
use App\Services\BreakService;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    private const MAX_QUERY_DAYS = 366;

    private const MAX_SESSION_HOURS = 72;

    /** @var list<string> */
    private const AUDITED_FIELDS = [
        'staff_id',
        'date',
        'session_number',
        'clock_in',
        'clock_out',
        'break_minutes',
        'total_hours',
        'is_late',
        'late_minutes',
        'overtime_minutes',
        'notes',
    ];

    public function __construct(
        protected AttendanceRulesService $rules,
        protected AuditService $audits,
        protected BreakService $breaks,
        protected ScheduleService $schedules,
        protected AppConfigService $config
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'staff_pk' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:late,on_time,overtime,incomplete'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $from = isset($data['date_from']) ? Carbon::parse($data['date_from'])->startOfDay() : Carbon::today()->startOfMonth();
        $to = isset($data['date_to']) ? Carbon::parse($data['date_to'])->startOfDay() : Carbon::today();
        $this->validateDateRange($from, $to);

        $query = Attendance::query()
            ->with('staff')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('session_number')
            ->orderByDesc('clock_in');

        if ($department = $data['department'] ?? null) {
            $query->whereHas('staff', fn (Builder $staffQuery) => $staffQuery->where('department', $department));
        }

        if ($staffId = $data['staff_pk'] ?? null) {
            $query->where('staff_id', $staffId);
        }

        if ($status = $data['status'] ?? null) {
            $this->applyStatusFilter($query, $status);
        }

        return $query->paginate((int) ($data['per_page'] ?? 25));
    }

    public function update(Request $request, Attendance $attendance)
    {
        $this->ensureCanManageAttendance($request);

        $data = $request->validate([
            'clock_in' => ['required', 'date'],
            'clock_out' => ['nullable', 'date', 'after:clock_in'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $clockIn = Carbon::parse($data['clock_in'])->setTimezone(config('app.timezone'));
        $clockOut = isset($data['clock_out'])
            ? Carbon::parse($data['clock_out'])->setTimezone(config('app.timezone'))
            : null;
        $this->validateNotFutureDated($clockIn, $clockOut);
        $this->validateSessionDuration($clockIn, $clockOut);

        $attendance = DB::transaction(function () use ($request, $attendance, $data, $clockIn, $clockOut): Attendance {
            $staff = Staff::query()->whereKey($attendance->staff_id)->lockForUpdate()->firstOrFail();
            $locked = Attendance::query()->whereKey($attendance->id)->lockForUpdate()->firstOrFail();
            $oldValues = $this->auditSnapshot($locked);
            $newDate = $clockIn->toDateString();

            $this->ensureWithinEmploymentDates($staff, $newDate);
            $this->ensureSessionDoesNotOverlap($staff->id, $clockIn, $clockOut, $locked->id);

            if ($locked->date->toDateString() !== $newDate) {
                $locked->session_number = $this->nextSessionNumber($staff->id, $newDate, $locked->id);
            }

            $locked->date = $newDate;
            $locked->clock_in = $clockIn;
            $locked->clock_out = $clockOut;

            if (array_key_exists('notes', $data)) {
                $locked->notes = $data['notes'];
            }
            if (array_key_exists('break_minutes', $data)) {
                $this->breaks->applyBreak($locked, (int) $data['break_minutes']);
            }

            $this->recalculate($locked, $staff);
            $this->saveOrFailWithValidation($locked);

            $newValues = $this->auditSnapshot($locked);
            $changedFields = array_keys(array_filter(
                $newValues,
                fn ($value, $field) => $value !== ($oldValues[$field] ?? null),
                ARRAY_FILTER_USE_BOTH
            ));

            if ($changedFields !== []) {
                $this->audits->log(
                    $locked,
                    $changedFields,
                    array_intersect_key($oldValues, array_flip($changedFields)),
                    array_intersect_key($newValues, array_flip($changedFields)),
                    $request->user()->id,
                    $request->ip(),
                    $data['change_reason'] ?? 'Manual attendance update'
                );
            }

            return $locked;
        }, 3);

        return response()->json($attendance->fresh()->load('staff'));
    }

    public function storeManual(Request $request)
    {
        $this->ensureCanManageAttendance($request);

        $data = $request->validate([
            'staff_id' => ['required', 'integer', 'exists:staff,id'],
            'date' => ['required', 'date'],
            'clock_in' => ['nullable', 'date'],
            'clock_out' => ['nullable', 'date', 'after:clock_in'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $workDate = Carbon::parse($data['date'])->startOfDay();
        $staff = Staff::query()->findOrFail($data['staff_id']);
        $shift = $this->schedules->effectiveShift($staff, $workDate);
        $defaultStart = $shift['shift_start'] ?: $this->config->shiftStart()->format('H:i');
        $clockIn = ! empty($data['clock_in'])
            ? Carbon::parse($data['clock_in'])->setTimezone(config('app.timezone'))
            : Carbon::parse($workDate->toDateString().' '.$defaultStart);
        $clockOut = ! empty($data['clock_out'])
            ? Carbon::parse($data['clock_out'])->setTimezone(config('app.timezone'))
            : null;

        if ($clockIn->toDateString() !== $workDate->toDateString()) {
            throw ValidationException::withMessages([
                'clock_in' => ['Clock-in must begin on the selected attendance date. Overnight clock-out on the following date is allowed.'],
            ]);
        }

        $this->validateNotFutureDated($clockIn, $clockOut);
        $this->validateSessionDuration($clockIn, $clockOut);

        $attendance = DB::transaction(function () use ($request, $data, $clockIn, $clockOut, $workDate): Attendance {
            $staff = Staff::query()->whereKey($data['staff_id'])->lockForUpdate()->firstOrFail();
            $shift = $this->schedules->effectiveShift($staff, $workDate);
            $this->ensureWithinEmploymentDates($staff, $workDate->toDateString());
            $this->ensureSessionDoesNotOverlap($staff->id, $clockIn, $clockOut);

            $attendance = new Attendance([
                'staff_id' => $staff->id,
                'date' => $workDate,
                'session_number' => $this->nextSessionNumber($staff->id, $workDate->toDateString()),
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                'notes' => $data['notes'] ?? null,
                'break_minutes' => (int) ($data['break_minutes'] ?? $shift['break_minutes']),
            ]);

            $this->recalculate($attendance, $staff);
            $this->saveOrFailWithValidation($attendance);

            $newValues = $this->auditSnapshot($attendance);
            $this->audits->log(
                $attendance,
                array_keys($newValues),
                null,
                $newValues,
                $request->user()->id,
                $request->ip(),
                $data['change_reason'] ?? 'Manual attendance creation'
            );

            return $attendance;
        }, 3);

        return response()->json($attendance->fresh()->load('staff'), 201);
    }

    protected function applyStatusFilter(Builder $query, string $status): Builder
    {
        return match ($status) {
            'late' => $query->where('is_late', true),
            'on_time' => $query->where('is_late', false)->whereNotNull('clock_out')->where('overtime_minutes', 0),
            'overtime' => $query->where('overtime_minutes', '>', 0),
            'incomplete' => $query->whereNull('clock_out'),
        };
    }

    private function ensureCanManageAttendance(Request $request): void
    {
        if (! $request->user()->canManageAttendance()) {
            throw ValidationException::withMessages([
                'access' => ['Your role cannot modify attendance records.'],
            ]);
        }
    }

    private function validateDateRange(Carbon $from, Carbon $to): void
    {
        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['date_to' => ['The end date must be on or after the start date.']]);
        }

        if ($from->diffInDays($to) > self::MAX_QUERY_DAYS) {
            throw ValidationException::withMessages(['date_to' => ['Attendance queries are limited to 366 days.']]);
        }
    }

    private function validateSessionDuration(Carbon $clockIn, ?Carbon $clockOut): void
    {
        if (! $clockOut) {
            return;
        }

        if ($clockOut->lessThanOrEqualTo($clockIn)) {
            throw ValidationException::withMessages(['clock_out' => ['Clock-out must occur after clock-in.']]);
        }

        if ($clockIn->diffInHours($clockOut) > self::MAX_SESSION_HOURS) {
            throw ValidationException::withMessages(['clock_out' => ['An attendance session cannot exceed 72 hours.']]);
        }
    }

    private function validateNotFutureDated(Carbon $clockIn, ?Carbon $clockOut): void
    {
        $latestAllowed = now()->addSeconds(max(0, $this->config->scanClockSkewSeconds()));
        if ($clockIn->greaterThan($latestAllowed) || $clockOut?->greaterThan($latestAllowed)) {
            throw ValidationException::withMessages([
                'clock_in' => ['Attendance cannot be recorded in the future. Correct the workstation clock or wait until the event occurs.'],
            ]);
        }
    }

    private function ensureSessionDoesNotOverlap(int $staffId, Carbon $clockIn, ?Carbon $clockOut, ?int $exceptId = null): void
    {
        $query = Attendance::query()
            ->where('staff_id', $staffId)
            ->when($exceptId, fn (Builder $attendanceQuery) => $attendanceQuery->whereKeyNot($exceptId))
            ->where(function (Builder $attendanceQuery) use ($clockIn): void {
                $attendanceQuery->whereNull('clock_out')->orWhere('clock_out', '>', $clockIn);
            });

        if ($clockOut) {
            $query->where('clock_in', '<', $clockOut);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'clock_in' => ['This session overlaps another attendance session for the same employee.'],
            ]);
        }
    }

    private function nextSessionNumber(int $staffId, string $date, ?int $exceptId = null): int
    {
        return ((int) Attendance::query()
            ->where('staff_id', $staffId)
            ->whereDate('date', $date)
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->max('session_number')) + 1;
    }

    private function recalculate(Attendance $attendance, Staff $staff): void
    {
        $this->rules->applyClockInRules($attendance, $staff);

        if ($attendance->clock_out) {
            $this->rules->applyClockOutRules($attendance, $staff);

            return;
        }

        $attendance->total_hours = null;
        $attendance->overtime_minutes = 0;
    }

    /** @return array<string, bool|int|string|null> */
    private function auditSnapshot(Attendance $attendance): array
    {
        $values = [];

        foreach (self::AUDITED_FIELDS as $field) {
            $value = $attendance->{$field};

            if ($value instanceof \DateTimeInterface) {
                $value = $field === 'date' ? $value->format('Y-m-d') : $value->format(DATE_ATOM);
            } elseif (is_float($value)) {
                $value = number_format($value, 2, '.', '');
            }

            $values[$field] = $value;
        }

        return $values;
    }

    private function saveOrFailWithValidation(Attendance $attendance): void
    {
        try {
            $attendance->save();
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)
                || str_contains(strtolower($exception->getMessage()), 'unique')
                || str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                throw ValidationException::withMessages([
                    'clock_in' => ['The attendance session conflicts with an existing session. Refresh and try again.'],
                ]);
            }

            throw $exception;
        }
    }

    private function ensureWithinEmploymentDates(Staff $staff, string $date): void
    {
        $hasHistory = $staff->employmentHistory()->exists();
        $isEmployed = $hasHistory
            ? $staff->employmentHistory()
                ->where('status', 'Active')
                ->whereDate('effective_from', '<=', $date)
                ->where(function (Builder $query) use ($date): void {
                    $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
                })
                ->exists()
            : (! $staff->employment_start_date || $date >= $staff->employment_start_date->toDateString())
                && (! $staff->employment_end_date || $date <= $staff->employment_end_date->toDateString());

        if (! $isEmployed) {
            throw ValidationException::withMessages([
                'date' => ['Attendance must fall within the employee\'s employment dates.'],
            ]);
        }
    }
}
