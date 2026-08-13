<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\ScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepartmentScheduleController extends Controller
{
    public function __construct(
        protected ScheduleService $schedules
    ) {}

    public function forDepartment(string $department)
    {
        $staff = Staff::query()
            ->where('department', $department)
            ->where('employment_status', 'Active')
            ->get(['id', 'full_name', 'staff_id', 'department']);
        $schedulesByStaff = $this->schedules->forStaffIds($staff->pluck('id')->all());

        $results = [];
        foreach ($staff as $s) {
            $results[] = [
                'staff' => $s,
                'schedules' => $schedulesByStaff[$s->id] ?? [],
            ];
        }

        return response()->json($results);
    }

    public function upsert(Request $request, string $department)
    {
        abort_unless($request->user()->canManageSchedules(), 403, 'You cannot change schedules.');

        $data = $request->validate([
            'schedules' => ['required', 'array', 'min:1', 'max:7'],
            'schedules.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'schedules.*.shift_start' => ['nullable', 'date_format:H:i'],
            'schedules.*.shift_end' => ['nullable', 'date_format:H:i'],
            'schedules.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'schedules.*.is_day_off' => ['boolean'],
            'schedules.*.works_on_public_holiday' => ['boolean'],
        ]);

        $staff = DB::transaction(function () use ($department, $data, $request) {
            $staff = Staff::query()
                ->where('department', $department)
                ->where('employment_status', 'Active')
                ->lockForUpdate()
                ->get('id');

            foreach ($staff as $staffMember) {
                $this->schedules->upsert(
                    $staffMember->id,
                    $data['schedules'],
                    $request->user()->id,
                    "Department schedule updated for {$department}"
                );
            }

            return $staff;
        }, 3);

        return response()->json(['ok' => true, 'affected_staff' => $staff->count()]);
    }

    public function reset(Request $request, string $department)
    {
        abort_unless($request->user()->canManageSchedules(), 403, 'You cannot change schedules.');

        $affected = DB::transaction(function () use ($department, $request): int {
            $staffIds = Staff::query()
                ->where('department', $department)
                ->where('employment_status', 'Active')
                ->lockForUpdate()
                ->pluck('id');

            foreach ($staffIds as $staffId) {
                $this->schedules->reset(
                    $staffId,
                    $request->user()->id,
                    "Department schedule reset for {$department}"
                );
            }

            return $staffIds->count();
        }, 3);

        return response()->json(['ok' => true, 'affected_staff' => $affected]);
    }
}
