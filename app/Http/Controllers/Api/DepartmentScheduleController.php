<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\ScheduleService;
use Illuminate\Http\Request;

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

        $results = [];
        foreach ($staff as $s) {
            $results[] = [
                'staff' => $s,
                'schedules' => $this->schedules->forStaff($s->id),
            ];
        }

        return response()->json($results);
    }

    public function upsert(Request $request, string $department)
    {
        $data = $request->validate([
            'schedules' => ['required', 'array', 'min:1', 'max:7'],
            'schedules.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'schedules.*.shift_start' => ['nullable', 'date_format:H:i'],
            'schedules.*.shift_end' => ['nullable', 'date_format:H:i', 'after:schedules.*.shift_start'],
            'schedules.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'schedules.*.is_day_off' => ['boolean'],
        ]);

        $staff = Staff::query()
            ->where('department', $department)
            ->where('employment_status', 'Active')
            ->get('id');

        foreach ($staff as $s) {
            $this->schedules->upsert($s->id, $data['schedules']);
        }

        return response()->json(['ok' => true, 'affected_staff' => $staff->count()]);
    }
}
