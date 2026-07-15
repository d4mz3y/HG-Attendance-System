<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceAudit;
use Carbon\Carbon;

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

    public function forAttendance(int $attendanceId): array
    {
        return AttendanceAudit::query()
            ->with('changer')
            ->where('attendance_id', $attendanceId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'changed_fields' => $a->changed_fields,
                'old_values' => $a->old_values,
                'new_values' => $a->new_values,
                'reason' => $a->reason,
                'changed_by' => $a->changer?->username,
                'ip_address' => $a->ip_address,
                'created_at' => $a->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
