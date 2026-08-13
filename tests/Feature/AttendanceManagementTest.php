<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceAudit;
use App\Models\Leave;
use App\Models\PublicHoliday;
use App\Models\Staff;
use App\Models\StaffEmploymentHistory;
use App\Models\User;
use App\Services\LeaveService;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');

        $user = User::query()->create([
            'username' => 'attendance-reviewer',
            'password' => 'a-secure-test-password',
            'role' => 'hr',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        Sanctum::actingAs($user, ['*']);
    }

    private function createStaff(string $staffId = 'TEST/001'): Staff
    {
        return Staff::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'Test Staff',
            'department' => 'Operations',
            'job_title' => 'Guard',
            'employment_status' => 'Active',
        ]);
    }

    public function test_manual_overnight_session_is_calculated_and_fully_audited(): void
    {
        $staff = $this->createStaff();

        $response = $this->postJson('/api/attendances/manual', [
            'staff_id' => $staff->id,
            'date' => '2026-08-10',
            'clock_in' => '2026-08-10 20:00:00',
            'clock_out' => '2026-08-11 06:00:00',
            'break_minutes' => 60,
            'notes' => 'Night post',
            'change_reason' => 'Approved paper timesheet',
        ])->assertCreated();

        $attendance = Attendance::query()->findOrFail($response->json('id'));
        $this->assertSame('2026-08-10', $attendance->date->toDateString());
        $this->assertSame('9.00', $attendance->total_hours);

        $audit = AttendanceAudit::query()->where('attendance_id', $attendance->id)->sole();
        $this->assertSame('Approved paper timesheet', $audit->reason);
        $this->assertContains('clock_in', $audit->changed_fields);
        $this->assertContains('clock_out', $audit->changed_fields);
        $this->assertContains('total_hours', $audit->changed_fields);
        $this->assertSame('Night post', $audit->new_values['notes']);
    }

    public function test_manual_update_audits_every_derived_change_with_before_and_after_values(): void
    {
        $staff = $this->createStaff();
        $attendance = Attendance::query()->create([
            'staff_id' => $staff->id,
            'date' => '2026-08-10',
            'session_number' => 1,
            'clock_in' => '2026-08-10 08:00:00',
            'clock_out' => '2026-08-10 17:00:00',
            'break_minutes' => 60,
            'total_hours' => 8,
            'notes' => 'Original',
        ]);

        $this->patchJson("/api/attendances/{$attendance->id}", [
            'clock_in' => '2026-08-10 08:30:00',
            'clock_out' => '2026-08-10 18:30:00',
            'break_minutes' => 30,
            'notes' => 'Corrected',
            'change_reason' => 'Supervisor correction',
        ])->assertOk();

        $audit = AttendanceAudit::query()->where('attendance_id', $attendance->id)->sole();
        foreach (['clock_in', 'clock_out', 'break_minutes', 'total_hours', 'overtime_minutes', 'notes'] as $field) {
            $this->assertContains($field, $audit->changed_fields);
            $this->assertArrayHasKey($field, $audit->old_values);
            $this->assertArrayHasKey($field, $audit->new_values);
        }
        $this->assertSame('Supervisor correction', $audit->reason);
    }

    public function test_manual_sessions_cannot_overlap(): void
    {
        $staff = $this->createStaff();
        Attendance::query()->create([
            'staff_id' => $staff->id,
            'date' => '2026-08-10',
            'session_number' => 1,
            'clock_in' => '2026-08-10 08:00:00',
            'clock_out' => '2026-08-10 12:00:00',
        ]);

        $this->postJson('/api/attendances/manual', [
            'staff_id' => $staff->id,
            'date' => '2026-08-10',
            'clock_in' => '2026-08-10 11:00:00',
            'clock_out' => '2026-08-10 13:00:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('clock_in');
    }

    public function test_attendance_index_includes_rows_on_both_date_boundaries(): void
    {
        $staff = $this->createStaff();
        foreach (['2026-08-01', '2026-08-10'] as $date) {
            Attendance::query()->create([
                'staff_id' => $staff->id,
                'date' => $date,
                'session_number' => 1,
                'clock_in' => "{$date} 08:00:00",
                'clock_out' => "{$date} 17:00:00",
            ]);
        }

        $this->getJson('/api/attendances?date_from=2026-08-01&date_to=2026-08-10')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_leave_creation_persists_ownership_and_approval_lifecycle(): void
    {
        $staff = $this->createStaff();
        $user = auth()->user();

        $response = $this->postJson('/api/leaves', [
            'staff_id' => $staff->id,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-12',
            'type' => 'Annual',
            'status' => 'Approved',
        ])->assertCreated();

        $leave = Leave::query()->findOrFail($response->json('id'));
        $this->assertSame($user->id, $leave->created_by);
        $this->assertSame($user->id, $leave->approved_by);
        $this->assertNotNull($leave->approved_at);
        $this->assertTrue(app(LeaveService::class)->isOnLeave($staff, Carbon::parse('2026-08-11')));

        $this->putJson("/api/leaves/{$leave->id}", ['status' => 'Pending'])->assertOk();

        $leave->refresh();
        $this->assertNull($leave->approved_by);
        $this->assertNull($leave->approved_at);
        $this->assertFalse(app(LeaveService::class)->isOnLeave($staff, Carbon::parse('2026-08-11')));
    }

    public function test_leave_index_includes_records_overlapping_the_query_window(): void
    {
        $staff = $this->createStaff();
        Leave::query()->create([
            'staff_id' => $staff->id,
            'start_date' => '2026-07-25',
            'end_date' => '2026-08-05',
            'type' => 'Annual',
            'status' => 'Approved',
        ]);

        $this->getJson('/api/leaves?date_from=2026-08-01&date_to=2026-08-10')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_recurring_holidays_are_projected_into_requested_year_and_upcoming_window(): void
    {
        PublicHoliday::query()->create([
            'date' => '2020-12-25',
            'name' => 'Christmas Day',
            'is_recurring' => true,
        ]);

        $this->getJson('/api/public-holidays?year=2027')
            ->assertOk()
            ->assertJsonFragment(['date' => '2027-12-25', 'source_date' => '2020-12-25']);

        Carbon::setTestNow('2027-12-20 09:00:00');
        try {
            $this->getJson('/api/public-holidays/upcoming?days=10')
                ->assertOk()
                ->assertJsonFragment(['date' => '2027-12-25']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_overnight_schedules_are_accepted_but_zero_length_shifts_are_rejected(): void
    {
        $staff = $this->createStaff();

        $this->putJson("/api/schedules/{$staff->id}", [
            'schedules' => [[
                'day_of_week' => 1,
                'shift_start' => '20:00',
                'shift_end' => '06:00',
                'break_minutes' => 60,
                'is_day_off' => false,
                'works_on_public_holiday' => false,
            ]],
        ])->assertOk();

        $this->putJson("/api/schedules/{$staff->id}", [
            'schedules' => [[
                'day_of_week' => 2,
                'shift_start' => '08:00',
                'shift_end' => '08:00',
                'break_minutes' => 60,
                'is_day_off' => false,
                'works_on_public_holiday' => false,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('schedules.0.shift_end');
    }

    public function test_department_schedule_update_is_atomic_and_loaded_without_per_staff_queries(): void
    {
        $this->createStaff('TEST/001');
        $this->createStaff('TEST/002');
        $payload = [
            'schedules' => [[
                'day_of_week' => 1,
                'shift_start' => '20:00',
                'shift_end' => '06:00',
                'break_minutes' => 45,
                'is_day_off' => false,
                'works_on_public_holiday' => true,
            ]],
        ];

        $this->putJson('/api/schedules/department/Operations', $payload)
            ->assertOk()
            ->assertJsonPath('affected_staff', 2);

        $response = $this->getJson('/api/schedules/department/Operations')->assertOk();
        $response->assertJsonCount(2);
        foreach ($response->json() as $staffSchedule) {
            $this->assertCount(1, $staffSchedule['schedules']);
        }
        $response->assertJsonFragment(['is_overnight' => true, 'works_on_public_holiday' => true]);
    }

    public function test_rehire_history_preserves_prior_period_for_manual_attendance_and_leave_corrections(): void
    {
        $staff = Staff::query()->create([
            'staff_id' => 'HGL/LA/OPS/901',
            'company' => 'Hogan Guards',
            'full_name' => 'Rehired Guard',
            'department' => 'Operations',
            'job_title' => 'Guard',
            'branch' => 'Lagos (HQ)',
            'employment_status' => 'Active',
            'employment_start_date' => '2026-01-01',
        ]);
        $base = [
            'staff_id' => $staff->staff_id,
            'company' => $staff->company,
            'full_name' => $staff->full_name,
            'department' => $staff->department,
            'job_title' => $staff->job_title,
            'branch' => $staff->branch,
        ];

        $this->putJson("/api/staff/{$staff->id}", [
            ...$base,
            'employment_status' => 'Inactive',
            'employment_start_date' => '2026-01-01',
            'employment_end_date' => '2026-03-31',
            'employment_change_reason' => 'Initial contract ended.',
        ])->assertOk();
        $this->putJson("/api/staff/{$staff->id}", [
            ...$base,
            'employment_status' => 'Active',
            'employment_start_date' => '2026-05-01',
            'employment_end_date' => null,
            'employment_change_reason' => 'Rehired after the approved break.',
        ])->assertOk();

        $this->postJson('/api/attendances/manual', [
            'staff_id' => $staff->id,
            'date' => '2026-02-15',
            'clock_in' => '2026-02-15 08:00:00',
            'clock_out' => '2026-02-15 17:00:00',
            'change_reason' => 'Correcting a prior contract paper timesheet.',
        ])->assertCreated();
        $this->postJson('/api/leaves', [
            'staff_id' => $staff->id,
            'start_date' => '2026-02-20',
            'end_date' => '2026-02-20',
            'type' => 'Annual',
            'status' => 'Approved',
        ])->assertCreated();

        $this->putJson("/api/staff/{$staff->id}", [
            ...$base,
            'employment_status' => 'Active',
            'employment_start_date' => '2026-02-01',
            'employment_end_date' => null,
            'employment_change_reason' => 'Attempted historical rewrite.',
        ])->assertUnprocessable()->assertJsonValidationErrors('employment_start_date');

        $history = StaffEmploymentHistory::query()
            ->where('staff_id', $staff->id)
            ->orderBy('effective_from')
            ->get();
        $this->assertCount(3, $history);
        $this->assertSame('2026-01-01', $history[0]->effective_from->toDateString());
        $this->assertSame('2026-03-31', $history[0]->effective_to->toDateString());
        $this->assertSame('2026-05-01', $history[2]->effective_from->toDateString());
    }

    public function test_deactivation_cannot_rewrite_the_current_employment_interval(): void
    {
        $staff = Staff::query()->create([
            'staff_id' => 'HGL/LA/OPS/901',
            'company' => 'Hogan Guards',
            'full_name' => 'Employment Guard',
            'department' => 'Operations',
            'job_title' => 'Guard',
            'branch' => 'Lagos (HQ)',
            'employment_status' => 'Active',
            'employment_start_date' => '2026-01-01',
        ]);
        StaffEmploymentHistory::query()->create([
            'staff_id' => $staff->id,
            'status' => 'Active',
            'effective_from' => '2026-01-01',
            'reason' => 'Initial employment record',
        ]);

        $this->putJson("/api/staff/{$staff->id}", [
            'staff_id' => $staff->staff_id,
            'company' => $staff->company,
            'full_name' => $staff->full_name,
            'department' => $staff->department,
            'job_title' => $staff->job_title,
            'branch' => $staff->branch,
            'employment_status' => 'Inactive',
            'employment_start_date' => '2025-12-01',
            'employment_end_date' => '2026-01-31',
            'employment_change_reason' => 'Attempted rewrite of the original interval.',
        ])->assertUnprocessable()->assertJsonValidationErrors('employment_start_date');

        $history = StaffEmploymentHistory::query()->where('staff_id', $staff->id)->sole();
        $this->assertSame('Active', $history->status);
        $this->assertSame('2026-01-01', $history->effective_from->toDateString());
        $this->assertNull($history->effective_to);
    }
}
