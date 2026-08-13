<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceAudit;

class AuditService
{
    public function log(Attendance $attendance, array $changedFields, ?array $oldValues, ?array $newValues, ?int $userId = null, ?string $ip = null, ?string $reason = null): AttendanceAudit
    {
        return AttendanceAudit::query()->create([
            'attendance_id' => $attendance->id,
            'changed_fields' => $changedFields,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'changed_by' => $userId,
            'ip_address' => $ip,
            'reason' => $reason,
        ]);
    }

    public function forAttendance(int $attendanceId, bool $includeTechnicalDetails = false): array
    {
        return AttendanceAudit::query()
            ->with('changer')
            ->where('attendance_id', $attendanceId)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (AttendanceAudit $audit) use ($includeTechnicalDetails): array {
                $row = [
                    'id' => $audit->id,
                    'reason' => $audit->reason,
                    'changed_by' => $audit->changer?->username,
                    'created_at' => $audit->created_at?->toIso8601String(),
                ];

                if ($includeTechnicalDetails) {
                    $row['ip_address'] = $audit->ip_address;
                }

                return $row;
            })
            ->all();
    }
}
