<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AlertService;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __construct(private readonly AlertService $alerts) {}

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

        return response()->json([
            'date' => $today->toDateString(),
            'open_sessions' => $open,
            'completed_sessions' => $completed,
            'late_clock_ins' => $late,
            'recent' => $recent,
            'alerts' => $this->alerts->missedClockOuts(),
            'absence_alerts' => $this->alerts->absentToday(),
            'late_clock_in_alerts' => $this->alerts->lateClockInsToday(),
        ]);
    }

    public function sessionCategory($category)
    {
        $today = Carbon::today();

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
                        'clock_in' => $a->clock_in?->format('g:i A'),
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
                        'clock_in' => $a->clock_in?->format('g:i A'),
                        'clock_out' => $a->clock_out?->format('g:i A'),
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
                        'clock_in' => $a->clock_in?->format('g:i A'),
                        'clock_out' => $a->clock_out?->format('g:i A'),
                        'total_hours' => $a->total_hours,
                    ]);
                break;

            default:
                $rows = collect();
        }

        return response()->json($rows);
    }
}
