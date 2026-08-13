<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\PublicHoliday;
use App\Models\Staff;
use App\Models\StaffAssignmentHistory;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class ComplianceService
{
    public function __construct(protected ScheduleService $schedules) {}

    /** @param array{company?:?string,branch?:?string,department?:?string} $assignmentFilters */
    public function monthlyScore(Staff $staff, Carbon $month, array $assignmentFilters = []): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        if ($to->isFuture()) {
            $to = now()->startOfDay()->min($to);
        }

        return $this->rangeScore($staff, $from, $to, $month->format('Y-m'), $assignmentFilters);
    }

    public function rangeScore(
        Staff $staff,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $period = null,
        array $assignmentFilters = []
    ): array {
        $start = Carbon::instance($from)->startOfDay();
        $end = Carbon::instance($to)->startOfDay();
        $staff->loadMissing(['employmentHistory', 'assignmentHistory']);
        $hasHistory = $staff->employmentHistory->isNotEmpty();

        if (! $hasHistory && $staff->employment_start_date && $staff->employment_start_date->greaterThan($start)) {
            $start = $staff->employment_start_date->copy()->startOfDay();
        }
        if (! $hasHistory && $staff->employment_end_date && $staff->employment_end_date->lessThan($end)) {
            $end = $staff->employment_end_date->copy()->startOfDay();
        }

        $expectedDates = $end->lt($start) ? [] : $this->expectedWorkDates($staff, $start, $end);
        if ($hasHistory) {
            $expectedDates = array_values(array_filter(
                $expectedDates,
                fn (string $date): bool => $this->isEmployedOn($staff, $date)
            ));
        }
        $expectedDates = array_values(array_filter(
            $expectedDates,
            fn (string $date): bool => $this->matchesAssignmentFilters(
                $this->assignmentForDate($staff, $date),
                $assignmentFilters
            )
        ));
        $approvedLeaveDates = $end->lt($start) ? [] : $this->approvedLeaveDates($staff, $start, $end);
        $requiredDates = array_values(array_diff($expectedDates, $approvedLeaveDates));

        $attendedDates = $end->lt($start)
            ? []
            : Attendance::query()
                ->where('staff_id', $staff->id)
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())
                ->whereNotNull('clock_out')
                ->distinct()
                ->pluck('date')
                ->map(fn ($date) => Carbon::parse($date)->toDateString())
                ->all();
        $attendedRequiredDays = count(array_intersect($requiredDates, $attendedDates));
        $requiredCount = count($requiredDates);
        $score = $requiredCount === 0
            ? null
            : round(min(100, ($attendedRequiredDays / $requiredCount) * 100), 1);
        $assignment = $this->assignmentForRange($staff, $start, $end, $assignmentFilters);

        return [
            'period' => $period ?? $start->toDateString().' to '.$end->toDateString(),
            'month' => $period,
            'staff_id' => $staff->id,
            'staff_name' => $staff->full_name,
            'staff_code' => $staff->staff_id,
            'company' => $assignment['company'] ?? $staff->company,
            'branch' => $assignment['branch'] ?? $staff->branch,
            'department' => $assignment['department'] ?? $staff->department,
            'working_days' => count($expectedDates),
            'leave_days' => count(array_intersect($expectedDates, $approvedLeaveDates)),
            'required_days' => $requiredCount,
            'attended_days' => $attendedRequiredDays,
            'score' => $score,
            'not_applicable' => $requiredCount === 0,
        ];
    }

    /**
     * @return array<int, array<string, int|float|string>>
     */
    public function departmentComparison(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $staff = Staff::query()
            ->with(['employmentHistory', 'assignmentHistory'])
            ->where(function ($range) use ($start, $end): void {
                $range->where(function ($current) use ($start, $end): void {
                    $current->where(function ($query) use ($start): void {
                        $query->where('employment_status', 'Active')
                            ->orWhereDate('employment_end_date', '>=', $start->toDateString());
                    })->where(function ($query) use ($end): void {
                        $query->whereNull('employment_start_date')
                            ->orWhereDate('employment_start_date', '<=', $end->toDateString());
                    });
                })->orWhereHas('employmentHistory', function ($history) use ($start, $end): void {
                    $history->where('status', 'Active')
                        ->whereDate('effective_from', '<=', $end->toDateString())
                        ->where(function ($historyEnd) use ($start): void {
                            $historyEnd->whereNull('effective_to')
                                ->orWhereDate('effective_to', '>=', $start->toDateString());
                        });
                });
            })
            ->get();

        if ($staff->isEmpty()) {
            return [];
        }

        $staffById = $staff->keyBy('id');
        $attendances = Attendance::query()
            ->whereIn('staff_id', $staffById->keys())
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get(['staff_id', 'date', 'is_late', 'overtime_minutes']);
        $departments = collect(app(StaffIdService::class)->departments())
            ->merge(
                StaffAssignmentHistory::query()
                    ->whereDate('effective_from', '<=', $end->toDateString())
                    ->where(function ($historyEnd) use ($start): void {
                        $historyEnd->whereNull('effective_to')
                            ->orWhereDate('effective_to', '>=', $start->toDateString());
                    })
                    ->distinct()
                    ->pluck('department')
            )
            ->merge($staff->filter(fn (Staff $employee): bool => $employee->assignmentHistory->isEmpty())->pluck('department'))
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $results = [];

        foreach ($departments as $department) {
            $departmentStaff = $staff->filter(
                fn (Staff $employee): bool => $this->hasAssignmentInRange(
                    $employee,
                    $start,
                    $end,
                    ['department' => $department]
                )
            );

            if ($departmentStaff->isEmpty()) {
                continue;
            }

            $scores = $departmentStaff->map(
                fn (Staff $employee) => $this->rangeScore(
                    $employee,
                    $start,
                    $end,
                    assignmentFilters: ['department' => $department]
                )
            );
            $applicableScores = $scores->pluck('score')->filter(fn ($score): bool => $score !== null);
            $departmentAttendances = $attendances->filter(function (Attendance $attendance) use ($staffById, $department): bool {
                $employee = $staffById->get($attendance->staff_id);

                return $employee instanceof Staff
                    && $this->matchesAssignmentFilters(
                        $this->assignmentForDate($employee, $attendance->date->toDateString()),
                        ['department' => $department]
                    );
            });

            $results[] = [
                'department' => $department,
                'staff_count' => $departmentStaff->count(),
                'avg_score' => $applicableScores->isEmpty()
                    ? null
                    : round((float) $applicableScores->avg(), 1),
                'late_count' => $departmentAttendances->where('is_late', true)->count(),
                'overtime_count' => $departmentAttendances->where('overtime_minutes', '>', 0)->count(),
            ];
        }

        return $results;
    }

    /** @return string[] */
    private function expectedWorkDates(Staff $staff, CarbonInterface $from, CarbonInterface $to): array
    {
        $holidayDates = PublicHoliday::occurrencesBetween($from, $to)
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->flip();
        $calendarDates = [];
        $cursor = Carbon::instance($from)->startOfDay();
        $end = Carbon::instance($to)->startOfDay();
        while ($cursor->lte($end)) {
            $calendarDates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $scheduleState = $this->schedules->effectiveShifts([$staff->id], $calendarDates)[$staff->id] ?? [];
        $dates = [];

        foreach ($calendarDates as $date) {
            $shift = $scheduleState[$date];
            $works = ! $shift['is_day_off'];
            $holiday = $holidayDates->has($date);

            if ($works && (! $holiday || $shift['works_on_public_holiday'])) {
                $expectedEnd = Carbon::parse($date.' '.$shift['shift_end'], config('app.timezone'));
                if ($shift['is_overnight']) {
                    $expectedEnd->addDay();
                }

                if (now()->greaterThanOrEqualTo($expectedEnd)) {
                    $dates[] = $date;
                }
            }
        }

        return $dates;
    }

    /** @return string[] */
    private function approvedLeaveDates(Staff $staff, CarbonInterface $from, CarbonInterface $to): array
    {
        $dates = [];
        $leaves = Leave::query()
            ->where('staff_id', $staff->id)
            ->where('status', 'Approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get(['start_date', 'end_date']);

        foreach ($leaves as $leave) {
            $cursor = Carbon::parse($leave->start_date)->max(Carbon::instance($from));
            $end = Carbon::parse($leave->end_date)->min(Carbon::instance($to));
            while ($cursor->lte($end)) {
                $dates[] = $cursor->toDateString();
                $cursor->addDay();
            }
        }

        return array_values(array_unique($dates));
    }

    private function isEmployedOn(Staff $staff, string $date): bool
    {
        if ($staff->relationLoaded('employmentHistory') && $staff->employmentHistory->isNotEmpty()) {
            return $staff->employmentHistory->contains(function ($history) use ($date): bool {
                return $history->status === 'Active'
                    && $date >= $history->effective_from->toDateString()
                    && (! $history->effective_to || $date <= $history->effective_to->toDateString());
            });
        }

        return (! $staff->employment_start_date
                || $date >= $staff->employment_start_date->toDateString())
            && (! $staff->employment_end_date
                || $date <= $staff->employment_end_date->toDateString());
    }

    /** @return array{company:string,branch:string,department:string,job_title:?string}|null */
    private function assignmentForDate(Staff $staff, string $date): ?array
    {
        if ($staff->relationLoaded('assignmentHistory') && $staff->assignmentHistory->isNotEmpty()) {
            $assignment = $staff->assignmentHistory->first(function ($history) use ($date): bool {
                return $date >= $history->effective_from->toDateString()
                    && (! $history->effective_to || $date <= $history->effective_to->toDateString());
            });

            return $assignment ? [
                'company' => (string) $assignment->company,
                'branch' => (string) $assignment->branch,
                'department' => (string) $assignment->department,
                'job_title' => $assignment->job_title,
            ] : null;
        }

        return [
            'company' => (string) ($staff->company ?? ''),
            'branch' => (string) ($staff->branch ?? ''),
            'department' => (string) $staff->department,
            'job_title' => $staff->job_title,
        ];
    }

    /**
     * @param  array{company:string,branch:string,department:string,job_title:?string}|null  $assignment
     * @param  array{company?:?string,branch?:?string,department?:?string}  $filters
     */
    private function matchesAssignmentFilters(?array $assignment, array $filters): bool
    {
        if ($assignment === null) {
            return false;
        }

        foreach (['company', 'branch', 'department'] as $field) {
            if (filled($filters[$field] ?? null) && $assignment[$field] !== $filters[$field]) {
                return false;
            }
        }

        return true;
    }

    /** @param array{company?:?string,branch?:?string,department?:?string} $filters */
    private function assignmentForRange(
        Staff $staff,
        CarbonInterface $from,
        CarbonInterface $to,
        array $filters
    ): ?array {
        if ($staff->relationLoaded('assignmentHistory') && $staff->assignmentHistory->isNotEmpty()) {
            $fromDate = Carbon::instance($from)->toDateString();
            $toDate = Carbon::instance($to)->toDateString();
            $assignment = $staff->assignmentHistory->first(function ($history) use ($fromDate, $toDate, $filters): bool {
                $values = [
                    'company' => (string) $history->company,
                    'branch' => (string) $history->branch,
                    'department' => (string) $history->department,
                    'job_title' => $history->job_title,
                ];

                return $history->effective_from->toDateString() <= $toDate
                    && (! $history->effective_to || $history->effective_to->toDateString() >= $fromDate)
                    && $this->matchesAssignmentFilters($values, $filters);
            });

            return $assignment ? [
                'company' => (string) $assignment->company,
                'branch' => (string) $assignment->branch,
                'department' => (string) $assignment->department,
                'job_title' => $assignment->job_title,
            ] : null;
        }

        $current = $this->assignmentForDate($staff, Carbon::instance($to)->toDateString());

        return $this->matchesAssignmentFilters($current, $filters) ? $current : null;
    }

    /** @param array{company?:?string,branch?:?string,department?:?string} $filters */
    private function hasAssignmentInRange(
        Staff $staff,
        CarbonInterface $from,
        CarbonInterface $to,
        array $filters
    ): bool {
        return $this->assignmentForRange($staff, $from, $to, $filters) !== null;
    }
}
