<?php

namespace App\Mail;

use App\Exports\AttendanceReportExport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

class ScheduledReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        protected string $frequency,
        protected string $dateFrom,
        protected string $dateTo,
        protected Collection $rows,
    ) {}

    public function build()
    {
        $this->subject('Hogan Guards '.ucfirst($this->frequency)." Attendance Report — {$this->dateFrom} to {$this->dateTo}")
            ->view('emails.scheduled-report')
            ->with([
                'from' => $this->dateFrom,
                'to' => $this->dateTo,
                'frequency' => $this->frequency,
            ]);

        $excel = Excel::raw(new AttendanceReportExport($this->rows), \Maatwebsite\Excel\Excel::XLSX);

        return $this->attachData($excel, "attendance-report-{$this->dateFrom}-to-{$this->dateTo}.xlsx", [
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
