<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class AttendanceReportExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        protected Collection $rows
    ) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn (array $r) => [
            $r['full_name'],
            $r['staff_code'],
            $r['department'],
            $r['date'],
            $r['clock_in'],
            $r['clock_out'],
            $r['total_hours'],
            $r['late_minutes'],
            $r['overtime_minutes'],
            $r['status'],
        ]);
    }

    public function headings(): array
    {
        return [
            'Full Name',
            'Staff ID',
            'Department',
            'Date',
            'Clock In',
            'Clock Out',
            'Total Hours Worked',
            'Late (minutes)',
            'Overtime (minutes)',
            'Status',
        ];
    }

    public function title(): string
    {
        return 'Attendance';
    }
}
