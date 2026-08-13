<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\AppConfigService;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function __construct(
        protected ScheduleService $schedules,
        protected AppConfigService $config,
    ) {}

    public function defaults()
    {
        $workDays = $this->config->defaultWorkDays();

        return response()->json(collect(range(0, 6))->map(fn (int $day): array => [
            'day_of_week' => $day,
            'day_name' => Carbon::create(2024, 1, 7 + $day)->format('l'),
            'shift_start' => in_array($day, $workDays, true) ? $this->config->shiftStart()->format('H:i') : null,
            'shift_end' => in_array($day, $workDays, true) ? $this->config->shiftEnd()->format('H:i') : null,
            'break_minutes' => in_array($day, $workDays, true) ? $this->config->defaultBreakMinutes() : 0,
            'is_day_off' => ! in_array($day, $workDays, true),
            'works_on_public_holiday' => false,
            'inherited' => true,
        ])->all());
    }

    public function forStaff(Staff $staff)
    {
        return response()->json($this->schedules->forStaff($staff->id));
    }

    public function upsert(Request $request, Staff $staff)
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

        $this->schedules->upsert($staff->id, $data['schedules'], $request->user()->id);

        return response()->json(['ok' => true, 'schedules' => $this->schedules->forStaff($staff->id)]);
    }

    public function reset(Request $request, Staff $staff)
    {
        abort_unless($request->user()->canManageSchedules(), 403, 'You cannot change schedules.');

        $this->schedules->reset($staff->id, $request->user()->id);

        return response()->json(['ok' => true, 'schedules' => []]);
    }
}
