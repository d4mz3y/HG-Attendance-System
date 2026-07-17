<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Staff;
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

        $rows = collect();

        if ($status === 'absent') {
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $dateStr = $cursor->toDateString();
                $presentIds = Attendance::query()
                    ->whereDate('date', $dateStr)
                    ->pluck('staff_id')
                    ->all();

                foreach ($staffList as $staff) {
                    if (in_array($staff->id, $presentIds, true)) {
                        continue;
                    }

                    if ($this->isOnLeave($staff, $dateStr)) {
                        $rows->push($this->onLeaveRow($staff, $dateStr));
                    } else {
                        $rows->push($this->absentRow($staff, $dateStr));
                    }
                }
                $cursor->addDay();
            }

            return $rows;
        }

        if ($status === 'on_leave') {
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $dateStr = $cursor->toDateString();

                foreach ($staffList as $staff) {
                    if ($this->isOnLeave($staff, $dateStr)) {
                        $rows->push($this->onLeaveRow($staff, $dateStr));
                    }
                }
                $cursor->addDay();
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

    private function isOnLeave(Staff $staff, string $date): bool
    {
        return Leave::query()
            ->where('staff_id', $staff->id)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->whereIn('status', ['Pending', 'Approved'])
            ->exists();
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
            'status' => 'On Leave',
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
            'status' => 'Absent',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function attendanceRow(Attendance $a): array
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
            'status' => $status,
        ];
    }
}
