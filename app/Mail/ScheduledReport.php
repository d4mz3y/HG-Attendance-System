<?php

namespace App\Mail;

use App\Exports\AttendanceReportExport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;

class ScheduledReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(protected $filters, protected $rows) {}

    public function build()
    {
        $this->subject('Hogan Guards Attendance Report')
            ->view('emails.scheduled-report')
            ->with([
                'filters' => $this->filters,
            ]);

        $excel = Excel::raw(new AttendanceReportExport($this->rows), \Maatwebsite\Excel\Excel::XLSX);

        return $this->attachData($excel, 'attendance_report.xlsx', [
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
