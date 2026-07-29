<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Staff;
use App\Services\AlertService;
use App\Services\ScheduleService;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function today()
    {
        $today = Carbon::today();

        $open = Attendance::query()
            ->whereDate('date', $today)
            ->whereNull('clock_out')
            ->count();

        $completed = Attendance::query()
            ->whereDate('date', $today)
            ->whereNotNull('clock_out')
            ->count();

        $late = Attendance::query()
            ->whereDate('date', $today)
            ->where('is_late', true)
            ->count();

        $recent = Attendance::query()
            ->with('staff')
            ->whereDate('date', $today)
            ->orderByDesc('clock_in')
            ->limit(12)
            ->get();

        $alerts = (new AlertService)->missedClockOuts();

        return response()->json([
            'date' => $today->toDateString(),
            'open_sessions' => $open,
            'completed_sessions' => $completed,
            'late_clock_ins' => $late,
            'recent' => $recent,
            'alerts' => $alerts,
        ]);
    }

    public function sessionCategory($category)
    {
        $today = Carbon::today();
        $graceMinutes = (int) app(\App\Services\AppConfigService::class)->shiftStart()
            ->diffInMinutes(Carbon::today()->setTime(8, 0));

        switch ($category) {
            case 'open':
                $rows = Attendance::query()
                    ->with('staff')
                    ->whereDate('date', $today)
                    ->whereNull('clock_out')
                    ->orderBy('clock_in')
                    ->get()
                    ->map(fn ($a) => [
                        'id' => $a->id,
                        'full_name' => $a->staff?->full_name,
                        'staff_code' => $a->staff?->staff_id,
                        'department' => $a->staff?->department,
                        'clock_in' => $a->clock_in?->format('H:i'),
                        'clock_out' => null,
                        'total_hours' => null,
                    ]);
                break;

            case 'completed':
                $rows = Attendance::query()
                    ->with('staff')
                    ->whereDate('date', $today)
                    ->whereNotNull('clock_out')
                    ->orderByDesc('clock_in')
                    ->get()
                    ->map(fn ($a) => [
                        'id' => $a->id,
                        'full_name' => $a->staff?->full_name,
                        'staff_code' => $a->staff?->staff_id,
                        'department' => $a->staff?->department,
                        'clock_in' => $a->clock_in?->format('H:i'),
                        'clock_out' => $a->clock_out?->format('H:i'),
                        'total_hours' => $a->total_hours,
                    ]);
                break;

            case 'late':
                $rows = Attendance::query()
                    ->with('staff')
                    ->whereDate('date', $today)
                    ->where('is_late', true)
                    ->orderBy('clock_in')
                    ->get()
                    ->map(fn ($a) => [
                        'id' => $a->id,
                        'full_name' => $a->staff?->full_name,
                        'staff_code' => $a->staff?->staff_id,
                        'department' => $a->staff?->department,
                        'clock_in' => $a->clock_in?->format('H:i'),
                        'clock_out' => $a->clock_out?->format('H:i'),
                        'total_hours' => $a->total_hours,
                    ]);
                break;

            default:
                $rows = collect();
        }

        return response()->json($rows);
    }
}
