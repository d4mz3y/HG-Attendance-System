<?php

namespace App\Http\Controllers\Api;

use App\Exports\AttendanceReportExport;
use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\ComplianceService;
use App\Services\ReportRowsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(
        protected ReportRowsService $rowsService,
        protected ComplianceService $compliance
    ) {}

    public function index(Request $request)
    {
        $filters = $this->validatedFilters($request);
        $rows = $this->rowsService->build($filters);

        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 25)));

        $slice = $rows->forPage($page, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $slice,
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return response()->json($paginator);
    }

    public function export(Request $request)
    {
        $filters = $this->validatedFilters($request);
        $rows = $this->rowsService->build($filters);
        $filename = $this->buildFilename($filters);

        return Excel::download(new AttendanceReportExport($rows), $filename);
    }

    public function exportPdf(Request $request)
    {
        $filters = $this->validatedFilters($request);
        $rows = $this->rowsService->build($filters);
        $filename = $this->buildPdfFilename($filters);

        $pdf = Pdf::loadView('reports.attendance', [
            'rows' => $rows,
            'filters' => $filters,
            'title' => 'Attendance Report',
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    public function compliance(Request $request)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'staff_pk' => ['nullable', 'integer'],
            'department' => ['nullable', 'string'],
            'company' => ['nullable', 'string', 'max:128'],
            'branch' => ['nullable', 'string', 'max:64'],
        ]);

        $month = Carbon::createFromFormat('!Y-m', $request->string('month')->toString())->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $staffPk = $request->integer('staff_pk');
        $department = $request->string('department')->toString();
        $assignmentFilters = [
            'department' => $department ?: null,
            'company' => $request->string('company')->toString() ?: null,
            'branch' => $request->string('branch')->toString() ?: null,
        ];

        $q = Staff::query()
            ->with(['employmentHistory', 'assignmentHistory'])
            ->where(function ($range) use ($month, $monthEnd): void {
                $range->where(function ($current) use ($month, $monthEnd): void {
                    $current->where(function ($query) use ($month): void {
                        $query->where(function ($active): void {
                            $active->where('employment_status', 'Active')
                                ->whereNull('employment_end_date');
                        })->orWhereDate('employment_end_date', '>=', $month->toDateString());
                    })->where(function ($query) use ($monthEnd): void {
                        $query->whereNull('employment_start_date')
                            ->orWhereDate('employment_start_date', '<=', $monthEnd->toDateString());
                    });
                })->orWhereHas('employmentHistory', function ($history) use ($month, $monthEnd): void {
                    $history->where('status', 'Active')
                        ->whereDate('effective_from', '<=', $monthEnd->toDateString())
                        ->where(function ($end) use ($month): void {
                            $end->whereNull('effective_to')
                                ->orWhereDate('effective_to', '>=', $month->toDateString());
                        });
                });
            });
        if ($staffPk) {
            $q->whereKey($staffPk);
        }
        if (collect($assignmentFilters)->contains(fn (?string $value): bool => filled($value))) {
            $q->where(function (Builder $assignments) use ($assignmentFilters, $month, $monthEnd): void {
                $assignments->whereHas('assignmentHistory', function (Builder $history) use ($assignmentFilters, $month, $monthEnd): void {
                    $history->whereDate('effective_from', '<=', $monthEnd->toDateString())
                        ->where(function (Builder $end) use ($month): void {
                            $end->whereNull('effective_to')
                                ->orWhereDate('effective_to', '>=', $month->toDateString());
                        });
                    foreach ($assignmentFilters as $field => $value) {
                        if (filled($value)) {
                            $history->where($field, $value);
                        }
                    }
                })->orWhere(function (Builder $legacy) use ($assignmentFilters): void {
                    $legacy->whereDoesntHave('assignmentHistory');
                    foreach ($assignmentFilters as $field => $value) {
                        if (filled($value)) {
                            $legacy->where($field, $value);
                        }
                    }
                });
            });
        }

        $results = $q->get()->map(
            fn (Staff $staff) => $this->compliance->monthlyScore($staff, $month, $assignmentFilters)
        );

        if ($staffPk) {
            if ($results->isEmpty()) {
                Staff::query()->findOrFail($staffPk);
                throw ValidationException::withMessages([
                    'staff_pk' => ['The staff member was not employed in the selected assignment during this month.'],
                ]);
            }

            return response()->json($results->first());
        }

        return response()->json($results);
    }

    public function comparisons(Request $request)
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $this->assertRangeIsReasonable($request->string('date_from')->toString(), $request->string('date_to')->toString());

        $from = $request->string('date_from')->toString();
        $to = $request->string('date_to')->toString();

        return response()->json($this->compliance->departmentComparison($from, $to));
    }

    protected function validatedFilters(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'department' => ['nullable', 'string', 'max:128'],
            'company' => ['nullable', 'string', 'max:128'],
            'branch' => ['nullable', 'string', 'max:64'],
            'staff_pk' => ['nullable', 'integer', 'exists:staff,id'],
            'status' => ['nullable', 'in:late,on_time,overtime,absent,incomplete,day_off,public_holiday_work,on_leave'],
        ]);

        $this->assertRangeIsReasonable($data['date_from'], $data['date_to']);

        return [
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'department' => $data['department'] ?? null,
            'company' => $data['company'] ?? null,
            'branch' => $data['branch'] ?? null,
            'staff_pk' => $data['staff_pk'] ?? null,
            'status' => $data['status'] ?? null,
        ];
    }

    private function assertRangeIsReasonable(string $from, string $to): void
    {
        $days = Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay());
        if ($days > (int) config('hg.report_max_days', 366)) {
            throw ValidationException::withMessages([
                'date_to' => ['The selected report range is too large.'],
            ]);
        }
    }

    protected function buildFilename(array $filters): string
    {
        $dept = $filters['department'] ?? 'AllDepartments';
        $safeDept = preg_replace('/[^A-Za-z0-9_-]+/', '_', $dept) ?: 'AllDepartments';
        $from = str_replace('-', '', $filters['date_from']);
        $to = str_replace('-', '', $filters['date_to']);

        return "HoganGuards_Attendance_{$safeDept}_{$from}-{$to}.xlsx";
    }

    protected function buildPdfFilename(array $filters): string
    {
        $dept = $filters['department'] ?? 'AllDepartments';
        $safeDept = preg_replace('/[^A-Za-z0-9_-]+/', '_', $dept) ?: 'AllDepartments';
        $from = str_replace('-', '', $filters['date_from']);
        $to = str_replace('-', '', $filters['date_to']);

        return "HoganGuards_Attendance_{$safeDept}_{$from}-{$to}.pdf";
    }
}
