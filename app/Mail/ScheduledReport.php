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

    public function __construct(protected string $from, protected string $to, protected \Illuminate\Support\Collection $rows) {}

    public function build()
    {
        $this->subject("Hogan Guards Attendance Report — {$this->from} to {$this->to}")
            ->view('emails.scheduled-report')
            ->with([
                'from' => $this->from,
                'to' => $this->to,
            ]);

        $excel = Excel::raw(new \App\Exports\AttendanceReportExport($this->rows), \Maatwebsite\Excel\Excel::XLSX);

        return $this->attachData($excel, 'attendance_report.xlsx', [
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
