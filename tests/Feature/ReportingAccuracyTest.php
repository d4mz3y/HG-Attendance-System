<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\PublicHoliday;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\StaffAssignmentHistory;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Services\ComplianceService;
use App\Services\ReportRowsService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportingAccuracyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        Setting::setValue('default_work_days', '[1,2,3,4,5]');
    }

    private function staff(): Staff
    {
        return Staff::query()->create([
            'staff_id' => 'HGL/LA/OPS/900',
            'company' => 'Hogan Guards',
            'full_name' => 'Report Test',
            'department' => 'Operations',
            'branch' => 'Lagos (HQ)',
            'employment_status' => 'Active',
            'employment_start_date' => '2025-01-01',
        ]);
    }

    public function test_absence_and_leave_reports_use_only_approved_leave(): void
    {
        $staff = $this->staff();
        Leave::query()->create([
            'staff_id' => $staff->id,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-10',
            'type' => 'Annual',
            'status' => 'Pending',
        ]);

        $service = app(ReportRowsService::class);
        $filters = ['date_from' => '2026-08-10', 'date_to' => '2026-08-10', 'status' => 'absent'];

        $this->assertSame('Absent', $service->build($filters)->sole()['status']);
        $this->assertCount(0, $service->build([...$filters, 'status' => 'on_leave']));

        Leave::query()->update(['status' => 'Approved']);

        $this->assertCount(0, $service->build($filters));
        $this->assertSame('On Leave', $service->build([...$filters, 'status' => 'on_leave'])->sole()['status']);
    }

    public function test_recurring_holiday_schedule_affects_compliance_but_is_not_reported_as_actual_work(): void
    {
        $staff = $this->staff();
        PublicHoliday::query()->create([
            'name' => 'Recurring Day',
            'date' => '2025-08-10',
            'is_recurring' => true,
        ]);
        StaffSchedule::query()->create([
            'staff_id' => $staff->id,
            'day_of_week' => '1',
            'shift_start' => '08:00',
            'shift_end' => '17:00',
            'break_minutes' => 60,
            'is_day_off' => false,
            'works_on_public_holiday' => true,
        ]);

        $rows = app(ReportRowsService::class)->build([
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-10',
            'status' => 'public_holiday_work',
        ]);

        $this->assertCount(0, $rows);

        $score = app(ComplianceService::class)->rangeScore(
            $staff,
            Carbon::parse('2026-08-10'),
            Carbon::parse('2026-08-10')
        );
        $this->assertSame(1, $score['required_days']);
        $this->assertSame(0, $score['attended_days']);
    }

    public function test_compliance_counts_attended_dates_not_multiple_sessions(): void
    {
        $staff = $this->staff();
        foreach ([1 => ['08:00', '12:00'], 2 => ['13:00', '17:00']] as $session => [$in, $out]) {
            Attendance::query()->create([
                'staff_id' => $staff->id,
                'date' => '2026-08-10',
                'session_number' => $session,
                'clock_in' => "2026-08-10 {$in}",
                'clock_out' => "2026-08-10 {$out}",
                'total_hours' => 4,
            ]);
        }

        $score = app(ComplianceService::class)->rangeScore(
            $staff,
            Carbon::parse('2026-08-10'),
            Carbon::parse('2026-08-10')
        );

        $this->assertSame(1, $score['required_days']);
        $this->assertSame(1, $score['attended_days']);
        $this->assertSame(100.0, $score['score']);
    }

    public function test_company_and_branch_filters_are_applied(): void
    {
        $staff = $this->staff();
        Attendance::query()->create([
            'staff_id' => $staff->id,
            'date' => '2026-08-10',
            'session_number' => 1,
            'clock_in' => '2026-08-10 08:00',
            'clock_out' => '2026-08-10 17:00',
        ]);
        $service = app(ReportRowsService::class);
        $base = ['date_from' => '2026-08-10', 'date_to' => '2026-08-10'];

        $this->assertCount(1, $service->build([...$base, 'company' => 'Hogan Guards', 'branch' => 'Lagos (HQ)']));
        $this->assertCount(0, $service->build([...$base, 'company' => 'Different Company']));
    }

    public function test_transfer_history_preserves_past_report_ownership_and_same_day_corrections(): void
    {
        $manager = User::query()->create([
            'username' => 'assignment.manager',
            'password' => 'StrongPassword!234',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($manager, $manager->permissions());

        $staff = $this->postJson('/api/staff', [
            'staff_id' => 'HGL/LA/OPS/901',
            'company' => 'Hogan Guards',
            'full_name' => 'Transferred Employee',
            'department' => 'Operations',
            'job_title' => 'Operations Officer',
            'branch' => 'Lagos (HQ)',
            'employment_status' => 'Active',
            'employment_start_date' => '2026-01-01',
        ])->assertCreated()->json();

        foreach ([
            ['date' => '2026-08-04', 'is_late' => true, 'overtime_minutes' => 0],
            ['date' => '2026-08-06', 'is_late' => false, 'overtime_minutes' => 30],
        ] as $event) {
            Attendance::query()->create([
                'staff_id' => $staff['id'],
                'date' => $event['date'],
                'session_number' => 1,
                'clock_in' => $event['date'].' 08:00',
                'clock_out' => $event['date'].' 17:00',
                'is_late' => $event['is_late'],
                'overtime_minutes' => $event['overtime_minutes'],
            ]);
        }

        $transfer = [
            'staff_id' => $staff['staff_id'],
            'company' => 'Hogan Security',
            'full_name' => $staff['full_name'],
            'department' => 'Security',
            'job_title' => 'Security Supervisor',
            'branch' => 'Abuja',
            'employment_status' => 'Active',
            'employment_start_date' => '2026-01-01',
            'employment_end_date' => null,
            'assignment_effective_date' => '2026-08-05',
            'assignment_change_reason' => 'Transferred to Abuja security operations',
        ];
        $this->putJson('/api/staff/'.$staff['id'], $transfer)->assertOk();

        $this->putJson('/api/staff/'.$staff['id'], [
            ...$transfer,
            'job_title' => 'Senior Security Supervisor',
            'assignment_change_reason' => 'Corrected title on transfer record',
        ])->assertOk();

        $history = StaffAssignmentHistory::query()
            ->where('staff_id', $staff['id'])
            ->orderBy('effective_from')
            ->get();
        $this->assertCount(2, $history);
        $this->assertSame('2026-08-04', $history[0]->effective_to->toDateString());
        $this->assertSame('2026-08-05', $history[1]->effective_from->toDateString());
        $this->assertSame('Senior Security Supervisor', $history[1]->job_title);
        $this->assertSame($manager->id, $history[1]->changed_by);
        $this->assertSame('Corrected title on transfer record', $history[1]->reason);

        $service = app(ReportRowsService::class);
        $oldReport = $service->build([
            'date_from' => '2026-08-04',
            'date_to' => '2026-08-04',
            'company' => 'Hogan Guards',
            'branch' => 'Lagos (HQ)',
            'department' => 'Operations',
        ]);
        $newReport = $service->build([
            'date_from' => '2026-08-06',
            'date_to' => '2026-08-06',
            'company' => 'Hogan Security',
            'branch' => 'Abuja',
            'department' => 'Security',
        ]);

        $this->assertSame('Operations', $oldReport->sole()['department']);
        $this->assertSame('Hogan Guards', $oldReport->sole()['company']);
        $this->assertSame('Security', $newReport->sole()['department']);
        $this->assertSame('Hogan Security', $newReport->sole()['company']);
        $this->assertCount(0, $service->build([
            'date_from' => '2026-08-04',
            'date_to' => '2026-08-04',
            'department' => 'Security',
        ]));
        $this->assertCount(0, $service->build([
            'date_from' => '2026-08-06',
            'date_to' => '2026-08-06',
            'department' => 'Operations',
        ]));

        $comparisons = collect(app(ComplianceService::class)->departmentComparison('2026-08-04', '2026-08-06'))
            ->keyBy('department');
        $this->assertSame(1, $comparisons['Operations']['late_count']);
        $this->assertSame(0, $comparisons['Operations']['overtime_count']);
        $this->assertSame(0, $comparisons['Security']['late_count']);
        $this->assertSame(1, $comparisons['Security']['overtime_count']);
    }

    public function test_csv_import_records_an_effective_dated_assignment_change(): void
    {
        $manager = User::query()->create([
            'username' => 'assignment.importer',
            'password' => 'StrongPassword!234',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($manager, $manager->permissions());

        $staff = $this->postJson('/api/staff', [
            'staff_id' => 'HGL/LA/OPS/902',
            'company' => 'Hogan Guards',
            'full_name' => 'Imported Transfer',
            'department' => 'Operations',
            'job_title' => 'Officer',
            'branch' => 'Lagos (HQ)',
            'employment_status' => 'Active',
            'employment_start_date' => '2026-01-01',
        ])->assertCreated()->json();

        $csv = implode("\n", [
            'Staff ID,Full Name,Company,Department,Job Title,Branch,Status,Employment Start,Employment End,Assignment Effective Date,Assignment Change Reason',
            'HGL/LA/OPS/902,Imported Transfer,Hogan Security,Security,Supervisor,Abuja,Active,2026-01-01,,2026-08-07,CSV transfer',
        ]);

        $this->post('/api/staff/import', [
            'file' => UploadedFile::fake()->createWithContent('staff.csv', $csv),
        ])->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('skipped', 0);

        $history = StaffAssignmentHistory::query()
            ->where('staff_id', $staff['id'])
            ->orderBy('effective_from')
            ->get();
        $this->assertCount(2, $history);
        $this->assertSame('2026-08-06', $history[0]->effective_to->toDateString());
        $this->assertSame('2026-08-07', $history[1]->effective_from->toDateString());
        $this->assertSame('Security', $history[1]->department);
        $this->assertSame('CSV transfer', $history[1]->reason);
        $this->assertSame($manager->id, $history[1]->changed_by);
    }
}
