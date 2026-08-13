<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\AttendanceAudit;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_administrator_can_permanently_delete_individual_audit_records(): void
    {
        $superAdmin = User::query()->create([
            'username' => 'system.owner',
            'password' => 'StrongPassword!234',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $itManager = User::query()->create([
            'username' => 'system.it',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        $staff = Staff::query()->create([
            'staff_id' => 'HGL/LA/SEC/901',
            'full_name' => 'Audit Record Guard',
            'department' => 'Security',
            'employment_status' => 'Active',
        ]);
        $attendance = Attendance::query()->create([
            'staff_id' => $staff->id,
            'date' => '2026-08-13',
            'clock_in' => '2026-08-13 08:00:00',
            'clock_out' => '2026-08-13 17:00:00',
        ]);
        $attendanceAudit = AttendanceAudit::query()->create([
            'attendance_id' => $attendance->id,
            'changed_fields' => ['notes'],
            'reason' => 'Duplicate correction record',
        ]);
        $activityLog = ActivityLog::query()->create([
            'action' => 'Example.action',
            'method' => 'POST',
            'path' => '/api/example',
            'status_code' => 200,
        ]);

        Sanctum::actingAs($itManager, $itManager->permissions());
        $this->deleteJson("/api/audits/{$attendanceAudit->id}", [
            'reason' => 'Removing a duplicated audit record.',
        ])->assertForbidden();
        $this->deleteJson("/api/activity-logs/{$activityLog->id}", [
            'reason' => 'Removing a duplicated activity record.',
        ])->assertForbidden();

        Sanctum::actingAs($superAdmin, $superAdmin->permissions());
        $this->deleteJson("/api/audits/{$attendanceAudit->id}", [
            'reason' => 'Removing a duplicated audit record.',
        ])->assertOk()->assertJsonPath('id', $attendanceAudit->id);
        $this->deleteJson("/api/activity-logs/{$activityLog->id}", [
            'reason' => 'Removing a duplicated activity record.',
        ])->assertOk()->assertJsonPath('id', $activityLog->id);

        $this->assertDatabaseMissing('attendance_audits', ['id' => $attendanceAudit->id]);
        $this->assertDatabaseMissing('activity_logs', ['id' => $activityLog->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'AuditController.destroyAttendanceAudit',
            'subject_id' => $attendanceAudit->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'AuditController.destroyActivityLog',
            'subject_id' => $activityLog->id,
        ]);
    }

    public function test_audit_visibility_is_retained_when_the_subscription_is_expired(): void
    {
        $hr = User::query()->create([
            'username' => 'audit.hr',
            'password' => 'StrongPassword!234',
            'role' => 'hr',
            'is_active' => true,
        ]);
        Setting::setValue('subscription_status', 'unpaid');
        Setting::setValue('subscription_expiry', '');

        Sanctum::actingAs($hr, $hr->permissions());
        $this->getJson('/api/audits')->assertOk();
        $this->getJson('/api/activity-logs')->assertForbidden();
    }

    public function test_hr_receives_only_simple_attendance_audit_data_while_it_can_view_technical_activity(): void
    {
        $hr = User::query()->create([
            'username' => 'simple.audit.hr',
            'password' => 'StrongPassword!234',
            'role' => 'hr',
            'is_active' => true,
        ]);
        $itManager = User::query()->create([
            'username' => 'simple.audit.it',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        $staff = Staff::query()->create([
            'staff_id' => 'HGL/LA/SEC/902',
            'full_name' => 'Simple Audit Guard',
            'department' => 'Security',
            'employment_status' => 'Active',
        ]);
        $attendance = Attendance::query()->create([
            'staff_id' => $staff->id,
            'date' => '2026-08-13',
            'clock_in' => '2026-08-13 08:00:00',
            'clock_out' => '2026-08-13 17:00:00',
        ]);
        AttendanceAudit::query()->create([
            'attendance_id' => $attendance->id,
            'changed_fields' => ['clock_in', 'is_late'],
            'old_values' => ['clock_in' => '2026-08-13 08:00:00'],
            'new_values' => ['clock_in' => '2026-08-13 08:15:00'],
            'reason' => 'Corrected a paper sign-in time.',
            'ip_address' => '10.1.2.3',
        ]);

        Sanctum::actingAs($hr, $hr->permissions());
        $summary = $this->getJson('/api/audits')->assertOk()->json('data.0');
        $this->assertSame('Corrected a paper sign-in time.', $summary['reason']);
        $this->assertArrayNotHasKey('changed_fields', $summary);
        $this->assertArrayNotHasKey('ip_address', $summary);
        $detail = $this->getJson("/api/attendances/{$attendance->id}/audits")->assertOk()->json('0');
        $this->assertArrayNotHasKey('changed_fields', $detail);
        $this->assertArrayNotHasKey('old_values', $detail);
        $this->assertArrayNotHasKey('new_values', $detail);
        $this->assertArrayNotHasKey('ip_address', $detail);
        $this->getJson('/api/activity-logs')->assertForbidden();

        Sanctum::actingAs($itManager, $itManager->permissions());
        $technicalSummary = $this->getJson('/api/audits')->assertOk()->json('data.0');
        $this->assertSame('10.1.2.3', $technicalSummary['ip_address']);
        $technicalDetail = $this->getJson("/api/attendances/{$attendance->id}/audits")->assertOk()->json('0');
        $this->assertSame('10.1.2.3', $technicalDetail['ip_address']);
        $this->getJson('/api/activity-logs')->assertOk();
    }
}
