<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Models\Staff;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    private const MAX_QUERY_DAYS = 366;

    private const LEAVE_TYPES = ['Annual', 'Sick', 'Maternity', 'Paternity', 'Emergency', 'Unpaid', 'Other'];

    public function __construct(protected LeaveService $leaves) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'in:Pending,Approved,Rejected'],
            'department' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $from = isset($data['date_from']) ? Carbon::parse($data['date_from'])->startOfDay() : now()->startOfMonth();
        $to = isset($data['date_to']) ? Carbon::parse($data['date_to'])->startOfDay() : now()->endOfMonth();
        $this->validateDateRange($from, $to);

        $query = Leave::query()
            ->with(['staff', 'creator', 'approver'])
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString());

        if ($status = $data['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($department = $data['department'] ?? null) {
            $query->whereHas('staff', fn (Builder $staffQuery) => $staffQuery->where('department', $department));
        }

        return $query->orderByDesc('start_date')->paginate((int) ($data['per_page'] ?? 25));
    }

    public function store(Request $request)
    {
        $this->ensureCanManageLeave($request);
        $data = $request->validate([
            'staff_id' => ['required', 'integer', 'exists:staff,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'type' => ['required', 'in:'.implode(',', self::LEAVE_TYPES)],
            'reason' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:Pending,Approved,Rejected'],
        ]);

        $status = $data['status'] ?? 'Pending';
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();

        $leave = DB::transaction(function () use ($request, $data, $status, $start, $end): Leave {
            $staff = Staff::query()->whereKey($data['staff_id'])->lockForUpdate()->firstOrFail();
            $this->ensureWithinEmploymentDates($staff, $start, $end);
            $this->ensureNoActiveOverlap($data['staff_id'], $start, $end, $status);

            return Leave::query()->create(array_merge($data, [
                'status' => $status,
                'created_by' => $request->user()->id,
                'approved_at' => $status === 'Approved' ? now() : null,
                'approved_by' => $status === 'Approved' ? $request->user()->id : null,
            ]));
        }, 3);

        return response()->json($leave->load('staff', 'creator', 'approver'), 201);
    }

    public function update(Request $request, Leave $leave)
    {
        $this->ensureCanManageLeave($request);
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'type' => ['nullable', 'in:'.implode(',', self::LEAVE_TYPES)],
            'reason' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:Pending,Approved,Rejected'],
        ]);

        $leave = DB::transaction(function () use ($request, $leave, $data): Leave {
            $staff = Staff::query()->whereKey($leave->staff_id)->lockForUpdate()->firstOrFail();
            $locked = Leave::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();

            $start = isset($data['start_date']) ? Carbon::parse($data['start_date'])->startOfDay() : $locked->start_date->copy();
            $end = isset($data['end_date']) ? Carbon::parse($data['end_date'])->startOfDay() : $locked->end_date->copy();

            if ($end->lessThan($start)) {
                throw ValidationException::withMessages([
                    'end_date' => ['The end date must be on or after the effective start date.'],
                ]);
            }

            $status = $data['status'] ?? $locked->status;
            $this->ensureWithinEmploymentDates($staff, $start, $end);
            $this->ensureNoActiveOverlap($locked->staff_id, $start, $end, $status, $locked->id);

            if (array_key_exists('status', $data)) {
                if ($status === 'Approved'
                    && ($locked->status !== 'Approved' || ! $locked->approved_at || ! $locked->approved_by)) {
                    $data['approved_at'] = now();
                    $data['approved_by'] = $request->user()->id;
                } elseif ($status !== 'Approved') {
                    $data['approved_at'] = null;
                    $data['approved_by'] = null;
                }
            }

            $locked->update($data);

            return $locked;
        }, 3);

        return response()->json($leave->fresh()->load('staff', 'creator', 'approver'));
    }

    public function destroy(Request $request, Leave $leave)
    {
        $this->ensureCanManageLeave($request);
        $leave->delete();

        return response()->json(['ok' => true]);
    }

    public function upcoming(Request $request, int $staffId)
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'between:1,366']]);
        $days = (int) ($data['days'] ?? 30);

        return response()->json($this->leaves->upcomingForStaff(Staff::findOrFail($staffId), $days));
    }

    public function show(Leave $leave)
    {
        return response()->json($leave->load('staff', 'creator', 'approver'));
    }

    private function ensureCanManageLeave(Request $request): void
    {
        if (! $request->user()->canManageLeave()) {
            throw ValidationException::withMessages([
                'access' => ['Your role cannot manage leave records.'],
            ]);
        }
    }

    private function validateDateRange(Carbon $from, Carbon $to): void
    {
        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['date_to' => ['The end date must be on or after the start date.']]);
        }

        if ($from->diffInDays($to) > self::MAX_QUERY_DAYS) {
            throw ValidationException::withMessages(['date_to' => ['Leave queries are limited to 366 days.']]);
        }
    }

    private function ensureNoActiveOverlap(int $staffId, Carbon $start, Carbon $end, string $status, ?int $exceptId = null): void
    {
        if ($status === 'Rejected') {
            return;
        }

        $overlapExists = Leave::query()
            ->where('staff_id', $staffId)
            ->whereIn('status', ['Pending', 'Approved'])
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();

        if ($overlapExists) {
            throw ValidationException::withMessages([
                'start_date' => ['This leave overlaps another pending or approved leave for the employee.'],
            ]);
        }
    }

    private function ensureWithinEmploymentDates(Staff $staff, Carbon $start, Carbon $end): void
    {
        $hasHistory = $staff->employmentHistory()->exists();
        $isEmployed = $hasHistory
            ? $staff->employmentHistory()
                ->where('status', 'Active')
                ->whereDate('effective_from', '<=', $start->toDateString())
                ->where(function (Builder $query) use ($end): void {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $end->toDateString());
                })
                ->exists()
            : (! $staff->employment_start_date || ! $start->lessThan($staff->employment_start_date))
                && (! $staff->employment_end_date || ! $end->greaterThan($staff->employment_end_date));

        if (! $isEmployed) {
            throw ValidationException::withMessages([
                'start_date' => ['Leave must fall within the employee\'s employment dates.'],
            ]);
        }
    }
}
