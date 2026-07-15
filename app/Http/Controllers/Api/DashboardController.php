<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AlertService;
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
}
