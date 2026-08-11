<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\PublicHoliday;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Services\ScanService;
use Carbon\Carbon;
use Tests\TestCase;

class AttendanceRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    private function seedHoliday(string $date, bool $recurring = false): PublicHoliday
    {
        return PublicHoliday::create([
            'name' => 'Test Holiday',
            'date' => $date,
            'is_recurring' => $recurring,
        ]);
    }

    private function createStaff(array $overrides = []): Staff
    {
        return Staff::create(array_merge([
            'staff_id' => 'TEST/' . str_pad((string) Staff::count(), 3, '0', STR_PAD_LEFT),
            'full_name' => 'Test Staff',
            'department' => 'Test',
            'job_title' => 'Tester',
            'employment_status' => 'Active',
            'photo_url' => null,
        ], $overrides));
    }

    public function test_recurring_holiday_detection_matches_same_month_day(): void
    {
        $staff = $this->createStaff();
        $this->seedHoliday('2026-12-25', true);

        $attendance = new Attendance([
            'staff_id' => $staff->id,
            'date' => Carbon::parse('2026-12-25'),
            'clock_in' => Carbon::parse('2026-12-25 10:00'),
        ]);

        $service = app(\App\Services\AttendanceRulesService::class);
        $service->applyClockInRules($attendance, $staff);

        $this->assertFalse($attendance->is_late, 'Clock-in on a recurring holiday should not be marked late');
    }

    public function test_recurring_holiday_does_not_make_every_date_a_holiday(): void
    {
        $staff = $this->createStaff();
        $this->seedHoliday('2026-01-01', true);

        $service = app(\App\Services\AttendanceRulesService::class);

        $dateJan1 = Carbon::parse('2026-01-01');
        $attendanceJan1 = new Attendance([
            'staff_id' => $staff->id,
            'date' => $dateJan1,
            'clock_in' => Carbon::parse('2026-01-01 10:00'),
        ]);
        $service->applyClockInRules($attendanceJan1, $staff);

        $dateFeb1 = Carbon::parse('2026-02-01');
        $attendanceFeb1 = new Attendance([
            'staff_id' => $staff->id,
            'date' => $dateFeb1,
            'clock_in' => Carbon::parse('2026-02-01 10:00'),
        ]);
        $service->applyClockInRules($attendanceFeb1, $staff);

        $this->assertFalse($attendanceJan1->is_late, 'Jan 1 (recurring holiday) should not be marked late');
        $this->assertTrue($attendanceFeb1->is_late, 'Feb 1 should be marked late — it is NOT a holiday even though Jan 1 is recurring');
    }

    public function test_clock_in_uses_staff_schedule_not_global_settings(): void
    {
        $staff = $this->createStaff();
        $today = Carbon::today();
        StaffSchedule::create([
            'staff_id' => $staff->id,
            'day_of_week' => $today->format('w'),
            'shift_start' => '10:00',
            'shift_end' => '18:00',
            'break_minutes' => 60,
            'is_day_off' => false,
            'works_on_public_holiday' => false,
        ]);

        \App\Models\Setting::create(['key' => 'grace_period_minutes', 'value' => '60']);

        $attendance = new Attendance([
            'staff_id' => $staff->id,
            'date' => $today,
            'clock_in' => Carbon::parse('10:30'),
        ]);

        $service = app(\App\Services\AttendanceRulesService::class);
        $service->applyClockInRules($attendance, $staff);

        $this->assertFalse($attendance->is_late, '10:30 should not be late when shift starts at 10:00 and grace is 60 minutes');
    }

    public function test_leave_check_only_blocks_approved_leave_for_clock_in(): void
    {
        $staff = $this->createStaff();

        Leave::create([
            'staff_id' => $staff->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'type' => 'Annual',
            'reason' => 'Test',
            'status' => 'Pending',
        ]);

        $scanService = app(ScanService::class);
        $result = $scanService->handleScan($staff->staff_id);

        $this->assertTrue($result['ok'], 'Pending leave should not block clock-in');
    }

    public function test_scan_state_machine_rejects_third_scan_after_clock_out(): void
    {
        $staff = $this->createStaff();

        $scanService = app(ScanService::class);

        $r1 = $scanService->handleScan($staff->staff_id);
        $this->assertEquals('in', $r1['action'] ?? null, 'First scan should be clock-in');

        $attendance = Attendance::where('staff_id', $staff->id)->whereDate('date', Carbon::today())->first();
        $attendance->clock_out = Carbon::now()->addHours(2);
        $attendance->save();

        $r2 = $scanService->handleScan($staff->staff_id);
        $this->assertEquals('already_signed_out', $r2['error'] ?? null, 'Third scan should be rejected after clock-out');
    }

    public function test_concurrent_scans_create_only_one_record(): void
    {
        $staff = $this->createStaff();

        $scanService = app(ScanService::class);

        $r1 = $scanService->handleScan($staff->staff_id);
        $this->assertEquals('in', $r1['action'] ?? null);

        $count = Attendance::where('staff_id', $staff->id)->whereDate('date', Carbon::today())->count();
        $this->assertEquals(1, $count, 'Only one attendance record should exist after first scan');
    }
}