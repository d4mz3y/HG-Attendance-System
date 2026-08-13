<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\PublicHoliday;
use App\Models\Staff;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ReportRowsService
{
    public function __construct(protected ScheduleService $schedules) {}

    /**
     * @param  array{date_from:string,date_to:string,department?:?string,company?:?string,branch?:?string,staff_pk?:?int,status?:?string}  $filters
     * @return Collection<int, array<string, int|string>>
     */
    public function build(array $filters): Collection
    {
        $from = Carbon::parse($filters['date_from'])->startOfDay();
        $to = Carbon::parse($filters['date_to'])->startOfDay();
        $status = $filters['status'] ?? null;
        $staffList = $this->staffForRange($filters, $from, $to);

        if ($staffList->isEmpty()) {
            return collect();
        }

        $staffIds = $staffList->pluck('id')->all();
        $staffById = $staffList->keyBy('id');
        $dates = $this->dateRange($from, $to);
        if ($staffList->count() * count($dates) > (int) config('hg.report_max_matrix_cells', 100000)) {
            throw ValidationException::withMessages([
                'date_from' => ['This report is too large. Narrow the date, company, branch, department, or staff filters.'],
            ]);
        }
        $attendances = Attendance::query()
            ->whereIn('staff_id', $staffIds)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')
            ->orderBy('clock_in')
            ->get();
        $present = $attendances
            ->groupBy(fn (Attendance $attendance) => $attendance->date->toDateString())
            ->map(fn (Collection $group) => $group->pluck('staff_id')->unique()->flip()->all());
        $approvedLeave = $this->approvedLeaveDates($staffIds, $from, $to);
        $scheduleState = $this->scheduleState($staffIds, $dates);
        $holidayDates = PublicHoliday::occurrencesBetween($from, $to)
            ->keyBy(fn (PublicHoliday $holiday) => $holiday->date->toDateString());
        $rows = collect();

        if (in_array($status, ['absent', 'day_off', 'on_leave'], true)) {
            foreach ($dates as $date) {
                foreach ($staffList as $staff) {
                    if (! $this->isEmployedOn($staff, $date)) {
                        continue;
                    }

                    $assignment = $this->assignmentForDate($staff, $date);
                    if (! $this->matchesAssignmentFilters($assignment, $filters)) {
                        continue;
                    }

                    if (isset($present[$date][$staff->id])) {
                        continue;
                    }

                    $onLeave = $approvedLeave[$staff->id][$date] ?? false;
                    $shift = $scheduleState[$staff->id][$date];
                    $isHoliday = $holidayDates->has($date);
                    $isDayOff = $shift['is_day_off'] || ($isHoliday && ! $shift['works_on_public_holiday']);

                    if ($status === 'on_leave' && $onLeave) {
                        $rows->push($this->exceptionRow($staff, $assignment, $date, 'On Leave'));
                    } elseif ($status === 'day_off' && ! $onLeave && $isDayOff) {
                        $rows->push($this->exceptionRow(
                            $staff,
                            $assignment,
                            $date,
                            $isHoliday ? 'Public Holiday' : 'Day Off',
                            $isHoliday ? $holidayDates->get($date)?->name : null
                        ));
                    } elseif ($status === 'absent'
                        && ! $onLeave
                        && ! $isDayOff
                        && $this->absenceIsFinal($date, $shift)) {
                        $rows->push($this->exceptionRow($staff, $assignment, $date, 'Absent'));
                    }
                }
            }

            return $rows;
        }

        if ($status === 'public_holiday_work') {
            $attendancesByStaffDate = $attendances->groupBy(
                fn (Attendance $attendance) => $attendance->staff_id.'|'.$attendance->date->toDateString()
            );

            foreach ($holidayDates as $date => $holiday) {
                foreach ($staffList as $staff) {
                    if (! $this->isEmployedOn($staff, $date)) {
                        continue;
                    }

                    $assignment = $this->assignmentForDate($staff, $date);
                    if (! $this->matchesAssignmentFilters($assignment, $filters)) {
                        continue;
                    }

                    $sessions = $attendancesByStaffDate->get($staff->id.'|'.$date, collect());
                    foreach ($sessions as $attendance) {
                        $rows->push($this->attendanceRow(
                            $attendance,
                            $staff,
                            $assignment,
                            $attendance->clock_out ? 'Public Holiday Work' : 'Public Holiday Work (Incomplete)',
                            $holiday->name
                        ));
                    }
                }
            }

            return $rows;
        }

        return $attendances
            ->filter(function (Attendance $attendance) use ($status, $staffById, $filters): bool {
                if (! $this->matchesStatus($attendance, $status)) {
                    return false;
                }

                $staff = $staffById->get($attendance->staff_id);

                return $staff instanceof Staff
                    && $this->matchesAssignmentFilters(
                        $this->assignmentForDate($staff, $attendance->date->toDateString()),
                        $filters
                    );
            })
            ->map(function (Attendance $attendance) use ($holidayDates, $staffById): array {
                $holiday = $holidayDates->get($attendance->date->toDateString());
                /** @var Staff $staff */
                $staff = $staffById->get($attendance->staff_id);
                $assignment = $this->assignmentForDate($staff, $attendance->date->toDateString());

                return $this->attendanceRow($attendance, $staff, $assignment, holidayName: $holiday?->name);
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Staff>
     */
    private function staffForRange(array $filters, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $hasAssignmentFilters = collect(['department', 'company', 'branch'])
            ->contains(fn (string $field): bool => filled($filters[$field] ?? null));

        return Staff::query()
            ->with(['employmentHistory', 'assignmentHistory'])
            ->where(function (Builder $range) use ($from, $to): void {
                $range->where(function (Builder $current) use ($from, $to): void {
                    $current->where(function (Builder $query) use ($from): void {
                        $query->where('employment_status', 'Active')
                            ->orWhereDate('employment_end_date', '>=', $from->toDateString());
                    })->where(function (Builder $query) use ($to): void {
                        $query->whereNull('employment_start_date')
                            ->orWhereDate('employment_start_date', '<=', $to->toDateString());
                    });
                })->orWhereHas('employmentHistory', function (Builder $history) use ($from, $to): void {
                    $history->where('status', 'Active')
                        ->whereDate('effective_from', '<=', $to->toDateString())
                        ->where(function (Builder $end) use ($from): void {
                            $end->whereNull('effective_to')
                                ->orWhereDate('effective_to', '>=', $from->toDateString());
                        });
                });
            })
            ->when($hasAssignmentFilters, function (Builder $query) use ($filters, $from, $to): void {
                $query->where(function (Builder $assignments) use ($filters, $from, $to): void {
                    $assignments->whereHas('assignmentHistory', function (Builder $history) use ($filters, $from, $to): void {
                        $history->whereDate('effective_from', '<=', $to->toDateString())
                            ->where(function (Builder $end) use ($from): void {
                                $end->whereNull('effective_to')
                                    ->orWhereDate('effective_to', '>=', $from->toDateString());
                            });
                        foreach (['department', 'company', 'branch'] as $field) {
                            if (filled($filters[$field] ?? null)) {
                                $history->where($field, $filters[$field]);
                            }
                        }
                    })->orWhere(function (Builder $legacy) use ($filters): void {
                        $legacy->whereDoesntHave('assignmentHistory');
                        foreach (['department', 'company', 'branch'] as $field) {
                            if (filled($filters[$field] ?? null)) {
                                $legacy->where($field, $filters[$field]);
                            }
                        }
                    });
                });
            })
            ->when($filters['staff_pk'] ?? null, fn (Builder $query, int $staffId) => $query->whereKey($staffId))
            ->orderBy('full_name')
            ->get();
    }

    /** @return array<int, array<string, bool>> */
    private function approvedLeaveDates(array $staffIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $result = array_fill_keys($staffIds, []);
        $leaves = Leave::query()
            ->whereIn('staff_id', $staffIds)
            ->where('status', 'Approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get(['staff_id', 'start_date', 'end_date']);

        foreach ($leaves as $leave) {
            $start = Carbon::parse($leave->start_date)->max(Carbon::instance($from));
            $end = Carbon::parse($leave->end_date)->min(Carbon::instance($to));
            foreach ($this->dateRange($start, $end) as $date) {
                $result[$leave->staff_id][$date] = true;
            }
        }

        return $result;
    }

    /** @return array<int, array<string, array{is_day_off:bool,works_on_public_holiday:bool,shift_start:?string,shift_end:?string,is_overnight:bool,break_minutes:int}>> */
    private function scheduleState(array $staffIds, array $dates): array
    {
        return $this->schedules->effectiveShifts($staffIds, $dates);
    }

    private function matchesStatus(Attendance $attendance, ?string $status): bool
    {
        return match ($status) {
            'late' => (bool) $attendance->is_late,
            'on_time' => ! $attendance->is_late && $attendance->clock_out !== null && (int) $attendance->overtime_minutes === 0,
            'overtime' => (int) $attendance->overtime_minutes > 0,
            'incomplete' => $attendance->clock_out === null,
            null, '' => true,
            default => false,
        };
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
     * @param  array<string, mixed>  $filters
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

    /** @param array{shift_start:?string,shift_end:?string,is_overnight:bool} $shift */
    private function absenceIsFinal(string $date, array $shift): bool
    {
        if (! $shift['shift_start'] || ! $shift['shift_end']) {
            return false;
        }

        $expectedEnd = Carbon::parse($date.' '.$shift['shift_end'], config('app.timezone'));
        if ($shift['is_overnight']) {
            $expectedEnd->addDay();
        }

        return now()->greaterThanOrEqualTo($expectedEnd);
    }

    /** @return string[] */
    private function dateRange(CarbonInterface $from, CarbonInterface $to): array
    {
        $dates = [];
        $cursor = Carbon::instance($from)->startOfDay();
        $end = Carbon::instance($to)->startOfDay();

        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }

    /** @return array<string, int|string> */
    private function exceptionRow(
        Staff $staff,
        array $assignment,
        string $date,
        string $status,
        ?string $holidayName = null
    ): array {
        return [
            'full_name' => $staff->full_name,
            'staff_code' => $staff->staff_id,
            'company' => $assignment['company'],
            'branch' => $assignment['branch'],
            'department' => $assignment['department'],
            'date' => $date,
            'holiday_name' => $holidayName ?? '',
            'session_number' => '',
            'clock_in' => '',
            'clock_out' => '',
            'total_hours' => '',
            'late_minutes' => '',
            'overtime_minutes' => '',
            'break_minutes' => '',
            'notes' => '',
            'status' => $status,
        ];
    }

    /** @return array<string, int|string> */
    private function attendanceRow(
        Attendance $attendance,
        Staff $staff,
        array $assignment,
        ?string $forcedStatus = null,
        ?string $holidayName = null
    ): array {
        $status = $forcedStatus ?? match (true) {
            $attendance->clock_out === null => 'Incomplete',
            $attendance->is_late && (int) $attendance->overtime_minutes > 0 => 'Late + Overtime',
            $attendance->is_late => 'Late',
            (int) $attendance->overtime_minutes > 0 => 'Overtime',
            default => 'On Time',
        };

        return [
            'full_name' => $staff->full_name,
            'staff_code' => $staff->staff_id,
            'company' => $assignment['company'],
            'branch' => $assignment['branch'],
            'department' => $assignment['department'],
            'date' => $attendance->date->toDateString(),
            'holiday_name' => $holidayName ?? '',
            'session_number' => (int) ($attendance->session_number ?? 1),
            'clock_in' => $attendance->clock_in?->format('g:i A') ?? '',
            'clock_out' => $attendance->clock_out?->format('g:i A') ?? '',
            'total_hours' => $attendance->total_hours !== null ? (string) $attendance->total_hours : '',
            'late_minutes' => (int) $attendance->late_minutes,
            'overtime_minutes' => (int) $attendance->overtime_minutes,
            'break_minutes' => (int) ($attendance->break_minutes ?? 0),
            'notes' => $attendance->notes ?? '',
            'status' => $status,
        ];
    }
}
