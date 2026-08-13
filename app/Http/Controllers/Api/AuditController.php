<?php

namespace App\Http\Controllers\Api;

use App\Auth\Permission;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AttendanceAudit;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(
        protected AuditService $audits
    ) {}

    public function forAttendance(Request $request, int $attendanceId)
    {
        return response()->json($this->audits->forAttendance(
            $attendanceId,
            $request->user()?->hasPermission(Permission::AUDIT_ACTIVITY_VIEW) ?? false
        ));
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $from = isset($data['date_from']) ? Carbon::parse($data['date_from'])->startOfDay() : now()->startOfMonth();
        $to = isset($data['date_to']) ? Carbon::parse($data['date_to'])->endOfDay() : now()->endOfMonth();

        $includeTechnicalDetails = $request->user()?->hasPermission(Permission::AUDIT_ACTIVITY_VIEW) ?? false;
        $rows = AttendanceAudit::query()
            ->with(['attendance.staff', 'changer'])
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return response()->json($rows->through(function (AttendanceAudit $audit) use ($includeTechnicalDetails): array {
            $row = [
                'id' => $audit->id,
                'attendance_id' => $audit->attendance_id,
                'reason' => $audit->reason,
                'changed_by' => $audit->changer?->username,
                'created_at' => $audit->created_at?->toIso8601String(),
                'attendance' => $audit->attendance ? [
                    'id' => $audit->attendance->id,
                    'date' => $audit->attendance->date?->toDateString(),
                    'staff' => $audit->attendance->staff ? [
                        'full_name' => $audit->attendance->staff->full_name,
                        'staff_id' => $audit->attendance->staff->staff_id,
                    ] : null,
                ] : null,
            ];

            if ($includeTechnicalDetails) {
                $row['ip_address'] = $audit->ip_address;
            }

            return $row;
        }));
    }

    public function activityIndex(Request $request)
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'action' => ['nullable', 'string', 'max:160'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $from = isset($data['date_from']) ? Carbon::parse($data['date_from'])->startOfDay() : now()->startOfMonth();
        $to = isset($data['date_to']) ? Carbon::parse($data['date_to'])->endOfDay() : now()->endOfMonth();

        $query = ActivityLog::query()
            ->with('user:id,username')
            ->whereBetween('created_at', [$from, $to]);
        if (! empty($data['action'])) {
            $query->where('action', 'like', '%'.addcslashes($data['action'], '%_\\').'%');
        }

        return response()->json(
            $query->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(min(100, max(1, $request->integer('per_page', 25))))
        );
    }

    /**
     * A super administrator can permanently remove an individual audit row.
     * The route itself remains subject to mutation auditing, so the action is
     * recorded unless that new record is deliberately removed as well.
     */
    public function destroyAttendanceAudit(Request $request, AttendanceAudit $attendanceAudit)
    {
        $this->ensureSuperAdministrator($request);
        $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $id = $attendanceAudit->id;
        $attendanceAudit->delete();

        return response()->json(['ok' => true, 'id' => $id]);
    }

    /**
     * Activity rows are also individually removable by a super administrator.
     */
    public function destroyActivityLog(Request $request, ActivityLog $activityLog)
    {
        $this->ensureSuperAdministrator($request);
        $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $id = $activityLog->id;
        $activityLog->delete();

        return response()->json(['ok' => true, 'id' => $id]);
    }

    private function ensureSuperAdministrator(Request $request): void
    {
        abort_unless(
            $request->user()?->isSuperAdmin(),
            403,
            'Only the super administrator can permanently delete audit records.'
        );
    }
}
