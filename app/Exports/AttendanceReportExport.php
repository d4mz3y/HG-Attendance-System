<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendanceReportExport implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    public function __construct(
        protected Collection $rows
    ) {}

    public function collection(): Collection
    {
        $mainRows = $this->rows->map(fn (array $r) => [
            $r['full_name'],
            $r['staff_code'],
            $r['department'],
            $r['date'],
            $r['clock_in'],
            $r['clock_out'],
            $r['total_hours'],
            $r['late_minutes'],
            $r['overtime_minutes'],
            $r['notes'] ?? '',
            $r['status'],
        ]);

        $publicHolidayRows = $this->rows
            ->filter(fn (array $r) => $r['status'] === 'Public Holiday Work')
            ->map(fn (array $r) => [
                $r['full_name'],
                $r['staff_code'],
                $r['department'],
                $r['date'],
                $r['clock_in'],
                $r['clock_out'],
                $r['total_hours'],
                $r['late_minutes'],
                $r['overtime_minutes'],
                $r['notes'] ?? '',
                $r['status'],
            ]);

        if ($publicHolidayRows->isNotEmpty()) {
            $mainRows = $mainRows->concat([
                [],
                ['PUBLIC HOLIDAY WORK RECORDS'],
                ['Full Name', 'Staff ID', 'Department', 'Date', 'Clock In', 'Clock Out', 'Total Hours', 'Late (minutes)', 'Overtime (minutes)', 'Notes', 'Status'],
            ])->concat($publicHolidayRows);
        }

        return $mainRows;
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
            'Notes',
            'Status',
        ];
    }

    public function title(): string
    {
        return 'Attendance';
    }

    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        $styles = [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1e3a8a'],
                ],
            ],
        ];

        for ($row = 2; $row <= $highestRow; $row++) {
            $statusCell = 'K' . $row;
            $statusValue = $sheet->getCell($statusCell)->getValue();

            $rowStyles = [];
            if ($statusValue === 'Late' || $statusValue === 'Absent') {
                $rowStyles['fill'] = [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FEE2E2'],
                ];
            } elseif ($statusValue === 'On Time') {
                $rowStyles['fill'] = [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'DCFCE7'],
                ];
            } elseif ($statusValue === 'Incomplete') {
                $rowStyles['fill'] = [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FEF3C7'],
                ];
            } elseif ($statusValue === 'Overtime' || $statusValue === 'Late + Overtime') {
                $rowStyles['fill'] = [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'DBEAFE'],
                ];
            } elseif ($statusValue === 'On Leave') {
                $rowStyles['fill'] = [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E5E7EB'],
                ];
            } elseif ($statusValue === 'Day Off') {
                $rowStyles['fill'] = [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F3E8FF'],
                ];
            } elseif ($statusValue === 'Public Holiday Work') {
                $rowStyles['fill'] = [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFFBEB'],
                ];
            }

            if ($rowStyles) {
                $styles[$row] = $rowStyles;
            }
        }

        return $styles;
    }
}
