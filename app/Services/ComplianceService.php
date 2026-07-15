<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ComplianceService
{
    public function monthlyScore(Staff $staff, Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $workingDays = $this->workingDaysInRange($from, $to);
        $leaves = (new LeaveService)->upcomingForStaff($staff, 90);
        $leaveDates = collect($leaves)->pluck('date')->all();

        $attendanceCount = Attendance::query()
            ->where('staff_id', $staff->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('clock_out')
            ->count();

        $validDays = $workingDays - count(array_intersect($leaveDates, $this->datesBetween($from, $to)));
        $validDays = max(1, $validDays);

        $score = round(min(100, ($attendanceCount / $validDays) * 100), 1);

        return [
            'month' => $month->format('Y-m'),
            'staff_id' => $staff->id,
            'staff_name' => $staff->full_name,
            'staff_code' => $staff->staff_id,
            'department' => $staff->department,
            'working_days' => $workingDays,
            'leave_days' => count(array_intersect($leaveDates, $this->datesBetween($from, $to))),
            'attended_days' => $attendanceCount,
            'score' => $score,
        ];
    }

    public function departmentComparison(string $from, string $to): array
    {
        $departments = collect(config('hg.departments', []));
        $results = [];

        foreach ($departments as $dept) {
            $staff = Staff::query()->where('department', $dept)->where('employment_status', 'Active')->get();
            if ($staff->isEmpty()) {
                continue;
            }

            $totalScore = 0;
            $count = 0;

            foreach ($staff as $s) {
                $score = $this->monthlyScore($s, Carbon::parse($from));
                $totalScore += $score['score'];
                $count++;
            }

            $avg = $count > 0 ? round($totalScore / $count, 1) : 0;

            $lateCount = Attendance::query()
                ->whereHas('staff', fn ($q) => $q->where('department', $dept))
                ->whereBetween('date', [$from, $to])
                ->where('is_late', true)
                ->count();

            $otCount = Attendance::query()
                ->whereHas('staff', fn ($q) => $q->where('department', $dept))
                ->whereBetween('date', [$from, $to])
                ->where('overtime_minutes', '>', 0)
                ->count();

            $results[] = [
                'department' => $dept,
                'staff_count' => $count,
                'avg_score' => $avg,
                'late_count' => $lateCount,
                'overtime_count' => $otCount,
            ];
        }

        return $results;
    }

    private function workingDaysInRange(Carbon $from, Carbon $to): int
    {
        $days = 0;
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            if (in_array($cursor->format('w'), ['1','2','3','4','5'])) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }

    private function datesBetween(Carbon $from, Carbon $to): array
    {
        $dates = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }
}
