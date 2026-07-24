<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\PublicHoliday;
use App\Models\Staff;
use App\Models\StaffSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReportRowsService
{
    /**
     * @param  array{date_from:string,date_to:string,?department:?string,?staff_pk:?int,?status:?string}  $filters
     */
    public function build(array $filters): Collection
    {
        $from = Carbon::parse($filters['date_from'])->startOfDay();
        $to = Carbon::parse($filters['date_to'])->startOfDay();

        $department = $filters['department'] ?? null;
        $staffPk = $filters['staff_pk'] ?? null;
        $status = $filters['status'] ?? null;

        $staffQuery = Staff::query()->where('employment_status', 'Active');
        if ($department) {
            $staffQuery->where('department', $department);
        }
        if ($staffPk) {
            $staffQuery->where('id', $staffPk);
        }
        $staffList = $staffQuery->orderBy('full_name')->get();

        if ($staffList->isEmpty()) {
            return collect();
        }

        $staffIds = $staffList->pluck('id')->all();
        $dateRange = $this->dateRange($from, $to);

        $presentIdsByDate = Attendance::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->whereIn('staff_id', $staffIds)
            ->get()
            ->groupBy(fn ($a) => $a->date->format('Y-m-d'))
            ->map(fn ($group) => $group->pluck('staff_id')->all())
            ->all();

        $leaveByStaffDate = $this->batchLeaves($staffIds, $from, $to);
        $dayOffByStaffDate = $this->batchDayOffs($staffIds, $from, $to);

        $rows = collect();

        if ($status === 'absent') {
            foreach ($dateRange as $dateStr) {
                $presentIds = $presentIdsByDate[$dateStr] ?? [];

                foreach ($staffList as $staff) {
                    if (in_array($staff->id, $presentIds, true)) {
                        continue;
                    }

                    if ($leaveByStaffDate[$staff->id][$dateStr] ?? false) {
                        $rows->push($this->onLeaveRow($staff, $dateStr));
                    } elseif ($dayOffByStaffDate[$staff->id][$dateStr] ?? false) {
                        $rows->push($this->dayOffRow($staff, $dateStr));
                    } else {
                        $rows->push($this->absentRow($staff, $dateStr));
                    }
                }
            }

            return $rows;
        }

        if ($status === 'day_off') {
            foreach ($dateRange as $dateStr) {
                foreach ($staffList as $staff) {
                    if ($dayOffByStaffDate[$staff->id][$dateStr] ?? false) {
                        $rows->push($this->dayOffRow($staff, $dateStr));
                    }
                }
            }

            return $rows;
        }

        if ($status === 'public_holiday_work') {
            $holidayDates = PublicHoliday::query()
                ->where(function ($q) use ($from, $to) {
                    $q->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                      ->orWhere('is_recurring', true);
                })
                ->get()
                ->pluck('date')
                ->map(fn ($d) => $d->format('Y-m-d'))
                ->all();

            if (empty($holidayDates)) {
                return $rows;
            }

            $attendances = Attendance::query()
                ->whereIn('staff_id', $staffIds)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->with('staff')
                ->get()
                ->groupBy('staff_id');

            $schedules = StaffSchedule::query()
                ->whereIn('staff_id', $staffIds)
                ->where('is_day_off', false)
                ->where('works_on_public_holiday', true)
                ->get()
                ->groupBy('staff_id');

            foreach ($staffList as $staff) {
                $staffAttendances = $attendances[$staff->id] ?? collect();
                $staffSchedules = $schedules[$staff->id] ?? collect();
                $scheduleDays = $staffSchedules->pluck('day_of_week')->all();

                foreach ($staffAttendances as $attendance) {
                    $dateStr = $attendance->date->format('Y-m-d');
                    $dayOfWeek = $attendance->date->format('w');

                    if (in_array($dateStr, $holidayDates) && in_array($dayOfWeek, $scheduleDays)) {
                        $rows->push($this->attendanceRow($attendance, true));
                    }
                }
            }

            return $rows;
        }

        $attQuery = Attendance::query()
            ->with('staff')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        if ($department) {
            $attQuery->whereHas('staff', fn ($q) => $q->where('department', $department));
        }
        if ($staffPk) {
            $attQuery->where('staff_id', $staffPk);
        }

        if ($status === 'late') {
            $attQuery->where('is_late', true);
        } elseif ($status === 'on_time') {
            $attQuery->where('is_late', false)
                ->whereNotNull('clock_out')
                ->where('overtime_minutes', 0);
        } elseif ($status === 'overtime') {
            $attQuery->where('overtime_minutes', '>', 0);
        } elseif ($status === 'incomplete') {
            $attQuery->whereNull('clock_out');
        }

        foreach ($attQuery->orderBy('date')->orderBy('clock_in')->cursor() as $a) {
            if (! $a->staff) {
                continue;
            }
            $rows->push($this->attendanceRow($a));
        }

        return $rows;
    }

    /**
     * @return array<string, array<int, bool>>
     */
    private function batchLeaves(array $staffIds, Carbon $from, Carbon $to): array
    {
        $leaves = Leave::query()
            ->whereIn('staff_id', $staffIds)
            ->whereIn('status', ['Pending', 'Approved'])
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->get(['staff_id', 'start_date', 'end_date'])
            ->groupBy('staff_id');

        $result = [];
        foreach ($staffIds as $id) {
            $result[$id] = [];
        }

        foreach ($leaves as $staffId => $staffLeaves) {
            $staffDateMap = [];
            foreach ($staffLeaves as $leave) {
                $start = Carbon::parse($leave->start_date);
                $end = Carbon::parse($leave->end_date);
                $cursor = $start->copy();
                while ($cursor->lte($end)) {
                    $staffDateMap[$cursor->toDateString()] = true;
                    $cursor->addDay();
                }
            }
            $result[$staffId] = $staffDateMap;
        }

        return $result;
    }

    /**
     * @return array<string, array<int, bool>>
     */
    private function batchDayOffs(array $staffIds, Carbon $from, Carbon $to): array
    {
        $schedules = StaffSchedule::query()
            ->whereIn('staff_id', $staffIds)
            ->where('is_day_off', true)
            ->get(['staff_id', 'day_of_week'])
            ->groupBy('staff_id');

        $result = [];
        foreach ($staffIds as $id) {
            $result[$id] = [];
            $days = $schedules[$id] ?? collect();
            $dayMap = $days->pluck('day_of_week')->all();

            foreach ($this->dateRange($from, $to) as $dateStr) {
                $dayOfWeek = Carbon::parse($dateStr)->format('w');
                if (in_array($dayOfWeek, $dayMap, true)) {
                    $result[$id][$dateStr] = true;
                }
            }
        }

        return $result;
    }

    /**
     * @return string[]
     */
    private function dateRange(Carbon $from, Carbon $to): array
    {
        $dates = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }
        return $dates;
    }

    /**
     * @return array<string, string>
     */
    protected function onLeaveRow(Staff $staff, string $date): array
    {
        return [
            'full_name' => $staff->full_name,
            'staff_code' => $staff->staff_id,
            'department' => $staff->department,
            'date' => $date,
            'clock_in' => '—',
            'clock_out' => '—',
            'total_hours' => '—',
            'late_minutes' => '—',
            'overtime_minutes' => '—',
            'notes' => '',
            'status' => 'On Leave',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function dayOffRow(Staff $staff, string $date): array
    {
        return [
            'full_name' => $staff->full_name,
            'staff_code' => $staff->staff_id,
            'department' => $staff->department,
            'date' => $date,
            'clock_in' => '',
            'clock_out' => '',
            'total_hours' => '',
            'late_minutes' => '',
            'overtime_minutes' => '',
            'notes' => '',
            'status' => 'Day Off',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function absentRow(Staff $staff, string $date): array
    {
        return [
            'full_name' => $staff->full_name,
            'staff_code' => $staff->staff_id,
            'department' => $staff->department,
            'date' => $date,
            'clock_in' => '',
            'clock_out' => '',
            'total_hours' => '',
            'late_minutes' => '',
            'overtime_minutes' => '',
            'notes' => '',
            'status' => 'Absent',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function attendanceRow(Attendance $a, bool $isPublicHolidayWork = false): array
    {
        $staff = $a->staff;
        $status = 'On Time';
        if (! $a->clock_out) {
            $status = 'Incomplete';
        } else {
            if ($a->is_late) {
                $status = $a->overtime_minutes > 0 ? 'Late + Overtime' : 'Late';
            } elseif ($a->overtime_minutes > 0) {
                $status = 'Overtime';
            }
        }

        if ($isPublicHolidayWork) {
            $status = 'Public Holiday Work';
        }

        return [
            'full_name' => $staff->full_name,
            'staff_code' => $staff->staff_id,
            'department' => $staff->department,
            'date' => $a->date->format('Y-m-d'),
            'clock_in' => $a->clock_in?->format('H:i') ?? '',
            'clock_out' => $a->clock_out?->format('H:i') ?? '',
            'total_hours' => $a->total_hours !== null ? (string) $a->total_hours : '',
            'late_minutes' => (string) (int) $a->late_minutes,
            'overtime_minutes' => (string) (int) $a->overtime_minutes,
            'notes' => $a->notes ?? '',
            'status' => $status,
        ];
    }
}