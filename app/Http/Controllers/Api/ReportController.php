<?php

namespace App\Http\Controllers\Api;

use App\Exports\AttendanceReportExport;
use App\Http\Controllers\Controller;
use App\Services\ComplianceService;
use App\Services\ReportRowsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        ]);

        return $pdf->download($filename);
    }

    public function compliance(Request $request)
    {
        $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'staff_pk' => ['nullable', 'integer'],
            'department' => ['nullable', 'string'],
        ]);

        $month = \Carbon\Carbon::parse($request->string('month'));
        $staffPk = $request->integer('staff_pk');
        $department = $request->string('department')->toString();

        if ($staffPk) {
            $staff = \App\Models\Staff::findOrFail($staffPk);
            return response()->json($this->compliance->monthlyScore($staff, $month));
        }

        $q = \App\Models\Staff::query()->where('employment_status', 'Active');
        if ($department) {
            $q->where('department', $department);
        }

        $results = $q->get()->map(fn ($s) => $this->compliance->monthlyScore($s, $month));

        return response()->json($results);
    }

    public function comparisons(Request $request)
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $from = $request->string('date_from');
        $to = $request->string('date_to');

        return response()->json($this->compliance->departmentComparison($from, $to));
    }

    protected function validatedFilters(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'department' => ['nullable', 'string', 'max:128'],
            'staff_pk' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:late,on_time,overtime,absent,incomplete'],
        ]);

        return [
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'department' => $data['department'] ?? null,
            'staff_pk' => $data['staff_pk'] ?? null,
            'status' => $data['status'] ?? null,
        ];
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
