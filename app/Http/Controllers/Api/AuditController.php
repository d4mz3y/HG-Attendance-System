<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(
        protected AuditService $audits
    ) {}

    public function forAttendance(int $attendanceId)
    {
        return response()->json($this->audits->forAttendance($attendanceId));
    }

    public function index(Request $request)
    {
        $from = $request->date('date_from') ?? now()->startOfMonth();
        $to = $request->date('date_to') ?? now()->endOfMonth();

        $rows = \App\Models\AttendanceAudit::query()
            ->with(['attendance.staff', 'changer'])
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->paginate((int) $request->get('per_page', 25));

        return response()->json($rows->through(fn ($a) => [
            'id' => $a->id,
            'attendance_id' => $a->attendance_id,
            'changed_fields' => $a->changed_fields,
            'reason' => $a->reason,
            'changed_by' => $a->changer?->username,
            'ip_address' => $a->ip_address,
            'created_at' => $a->created_at?->toIso8601String(),
            'attendance' => $a->attendance ? [
                'id' => $a->attendance->id,
                'date' => $a->attendance->date?->toDateString(),
                'staff' => $a->attendance->staff ? [
                    'full_name' => $a->attendance->staff->full_name,
                    'staff_id' => $a->attendance->staff->staff_id,
                ] : null,
            ] : null,
        ]));
    }
}
