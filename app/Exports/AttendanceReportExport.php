<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendanceReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn (array $row) => [
            $this->safe($row['full_name']),
            $this->safe($row['staff_code']),
            $this->safe($row['company'] ?? ''),
            $this->safe($row['branch'] ?? ''),
            $this->safe($row['department']),
            $row['date'],
            $this->safe($row['holiday_name'] ?? ''),
            $row['session_number'] ?? '',
            $row['clock_in'],
            $row['clock_out'],
            $row['total_hours'],
            $row['break_minutes'] ?? '',
            $row['late_minutes'],
            $row['overtime_minutes'],
            $this->safe($row['notes'] ?? ''),
            $row['status'],
        ]);
    }

    public function headings(): array
    {
        return [
            'Full Name', 'Staff ID', 'Company', 'Branch', 'Department', 'Date', 'Holiday', 'Session',
            'Clock In', 'Clock Out', 'Total Hours Worked', 'Break (minutes)', 'Late (minutes)',
            'Overtime (minutes)', 'Notes', 'Status',
        ];
    }

    public function title(): string
    {
        return 'Attendance';
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1E3A8A'],
                ],
            ],
        ];
        $colours = [
            'Late' => 'FEE2E2', 'Absent' => 'FEE2E2', 'On Time' => 'DCFCE7',
            'Incomplete' => 'FEF3C7', 'Overtime' => 'DBEAFE', 'Late + Overtime' => 'DBEAFE',
            'On Leave' => 'E5E7EB', 'Day Off' => 'F3E8FF', 'Public Holiday' => 'FFFBEB',
            'Public Holiday Work' => 'FFFBEB', 'Public Holiday Work (Incomplete)' => 'FEF3C7',
        ];

        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $status = (string) $sheet->getCell('P'.$row)->getValue();
            if (isset($colours[$status])) {
                $styles[$row] = [
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => $colours[$status]],
                    ],
                ];
            }
        }

        return $styles;
    }

    private function safe(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
