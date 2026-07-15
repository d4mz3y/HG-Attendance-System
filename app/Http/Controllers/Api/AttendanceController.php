<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AttendanceRulesService;
use App\Services\AuditService;
use App\Services\BreakService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceRulesService $rules,
        protected AuditService $audits,
        protected BreakService $breaks
    ) {}

    public function index(Request $request)
    {
        $from = $request->date('date_from') ?? Carbon::today()->startOfMonth();
        $to = $request->date('date_to') ?? Carbon::today();

        $q = Attendance::query()
            ->with('staff')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('date')
            ->orderByDesc('clock_in');

        if ($dept = $request->string('department')->toString()) {
            $q->whereHas('staff', fn ($s) => $s->where('department', $dept));
        }

        if ($sid = $request->integer('staff_pk')) {
            $q->where('staff_id', $sid);
        }

        if ($status = $request->string('status')->toString()) {
            $q = $this->applyStatusFilter($q, $status);
        }

        return $q->paginate((int) $request->get('per_page', 25));
    }

    public function update(Request $request, Attendance $attendance)
    {
        $data = $request->validate([
            'clock_in' => ['required', 'date'],
            'clock_out' => ['nullable', 'date', 'after:clock_in'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
        ]);

        $oldClockIn = $attendance->clock_in;
        $oldClockOut = $attendance->clock_out;
        $oldBreak = $attendance->break_minutes;

        $clockIn = Carbon::parse($data['clock_in']);
        $clockOut = isset($data['clock_out']) ? Carbon::parse($data['clock_out']) : null;

        if ($clockOut && $clockIn->toDateString() !== $clockOut->toDateString()) {
            throw ValidationException::withMessages([
                'clock_out' => ['Clock out must be on the same day as clock in.'],
            ]);
        }

        $attendance->clock_in = $clockIn;
        $attendance->clock_out = $clockOut;
        $attendance->date = $clockIn->toDateString();
        $attendance->notes = $data['notes'] ?? $attendance->notes;

        if (isset($data['break_minutes'])) {
            $attendance->break_minutes = (int) $data['break_minutes'];
        }

        $this->rules->applyClockInRules($attendance);

        if ($clockOut) {
            $this->rules->applyClockOutRules($attendance);
        } else {
            $attendance->total_hours = null;
            $attendance->overtime_minutes = 0;
        }

        $attendance->save();

        $changedFields = [];
        $oldValues = [];
        $newValues = [];

        if ($oldClockIn != $attendance->clock_in) {
            $changedFields[] = 'clock_in';
            $oldValues['clock_in'] = (string) $oldClockIn;
            $newValues['clock_in'] = (string) $attendance->clock_in;
        }
        if ($oldClockOut != $attendance->clock_out) {
            $changedFields[] = 'clock_out';
            $oldValues['clock_out'] = $oldClockOut;
            $newValues['clock_out'] = $attendance->clock_out;
        }
        if ($oldBreak != $attendance->break_minutes) {
            $changedFields[] = 'break_minutes';
            $oldValues['break_minutes'] = (string) $oldBreak;
            $newValues['break_minutes'] = (string) $attendance->break_minutes;
        }

        if (! empty($changedFields)) {
            $this->audits->log(
                $attendance,
                $changedFields,
                $oldValues,
                $newValues,
                $request->user()->id,
                $request->ip(),
                $data['notes'] ?? null
            );
        }

        return response()->json($attendance->fresh()->load('staff'));
    }

    protected function applyStatusFilter($q, string $status)
    {
        return match ($status) {
            'late' => $q->where('is_late', true),
            'on_time' => $q->where('is_late', false)->whereNotNull('clock_out')->where('overtime_minutes', 0),
            'overtime' => $q->where('overtime_minutes', '>', 0),
            'incomplete' => $q->whereNull('clock_out'),
            default => $q,
        };
    }
}
