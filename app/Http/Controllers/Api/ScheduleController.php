<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\ScheduleService;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function __construct(
        protected ScheduleService $schedules
    ) {}

    public function forStaff(Staff $staff)
    {
        return response()->json($this->schedules->forStaff($staff->id));
    }

    public function upsert(Request $request, Staff $staff)
    {
        $data = $request->validate([
            'schedules' => ['required', 'array', 'min:1', 'max:7'],
            'schedules.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'schedules.*.shift_start' => ['nullable', 'date_format:H:i'],
            'schedules.*.shift_end' => ['nullable', 'date_format:H:i', 'after:schedules.*.shift_start'],
            'schedules.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'schedules.*.is_day_off' => ['boolean'],
            'schedules.*.works_on_public_holiday' => ['boolean'],
        ]);

        $this->schedules->upsert($staff->id, $data['schedules']);

        return response()->json(['ok' => true, 'schedules' => $this->schedules->forStaff($staff->id)]);
    }
}
