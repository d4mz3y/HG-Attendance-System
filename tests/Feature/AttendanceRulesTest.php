<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Leave;
use App\Models\PublicHoliday;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Services\AttendanceRulesService;
use App\Services\ScanService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class AttendanceRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        Setting::setValue('scan_debounce_seconds', '0');
        Setting::setValue('scan_cooldown_seconds', '0');
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
            'staff_id' => 'TEST/'.str_pad((string) Staff::count(), 3, '0', STR_PAD_LEFT),
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

        $service = app(AttendanceRulesService::class);
        $service->applyClockInRules($attendance, $staff);

        $this->assertFalse($attendance->is_late, 'Clock-in on a recurring holiday should not be marked late');
    }

    public function test_recurring_holiday_does_not_make_every_date_a_holiday(): void
    {
        $staff = $this->createStaff();
        $this->seedHoliday('2026-01-01', true);

        $service = app(AttendanceRulesService::class);

        $dateJan1 = Carbon::parse('2026-01-01');
        $attendanceJan1 = new Attendance([
            'staff_id' => $staff->id,
            'date' => $dateJan1,
            'clock_in' => Carbon::parse('2026-01-01 10:00'),
        ]);
        $service->applyClockInRules($attendanceJan1, $staff);

        $dateFeb2 = Carbon::parse('2026-02-02');
        $attendanceFeb2 = new Attendance([
            'staff_id' => $staff->id,
            'date' => $dateFeb2,
            'clock_in' => Carbon::parse('2026-02-02 10:00'),
        ]);
        $service->applyClockInRules($attendanceFeb2, $staff);

        $this->assertFalse($attendanceJan1->is_late, 'Jan 1 (recurring holiday) should not be marked late');
        $this->assertTrue($attendanceFeb2->is_late, 'Feb 2 should be marked late — it is NOT a holiday even though Jan 1 is recurring');
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

        Setting::setValue('grace_period_minutes', '60');

        $attendance = new Attendance([
            'staff_id' => $staff->id,
            'date' => $today,
            'clock_in' => Carbon::parse('10:30'),
        ]);

        $service = app(AttendanceRulesService::class);
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

    public function test_approved_leave_does_not_block_clock_out_for_an_open_record(): void
    {
        $staff = $this->createStaff();
        $attendance = Attendance::create([
            'staff_id' => $staff->id,
            'date' => Carbon::today(),
            'clock_in' => Carbon::now()->subHours(2),
        ]);

        Leave::create([
            'staff_id' => $staff->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'type' => 'Annual',
            'status' => 'Approved',
        ]);

        $result = app(ScanService::class)->handleScan($staff->staff_id);

        $this->assertTrue($result['ok'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame('out', $result['action']);
        $this->assertNotNull($attendance->fresh()->clock_out);
    }

    public function test_approved_leave_blocks_clock_in_on_exact_start_date(): void
    {
        $staff = $this->createStaff();
        Leave::query()->create([
            'staff_id' => $staff->id,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-10',
            'type' => 'Annual',
            'status' => 'Approved',
        ]);

        $result = app(ScanService::class)->handleScan($staff->staff_id, Carbon::parse('2026-08-10 08:00:00'));

        $this->assertSame('on_leave', $result['error'] ?? null);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_scan_state_machine_supports_multiple_non_overlapping_sessions(): void
    {
        $staff = $this->createStaff();
        $scanService = app(ScanService::class);

        $r1 = $scanService->handleScan($staff->staff_id, Carbon::parse('2026-08-10 08:00'));
        $this->assertEquals('in', $r1['action'] ?? null, 'First scan should be clock-in');

        $r2 = $scanService->handleScan($staff->staff_id, Carbon::parse('2026-08-10 10:00'));
        $this->assertEquals('out', $r2['action'] ?? null, 'Second scan should close the first session');

        $r3 = $scanService->handleScan($staff->staff_id, Carbon::parse('2026-08-10 11:00'));
        $this->assertEquals('in', $r3['action'] ?? null, 'Third scan should begin a second session: '.json_encode($r3));

        $sessions = Attendance::query()->where('staff_id', $staff->id)->orderBy('session_number')->get();
        $this->assertCount(2, $sessions);
        $this->assertSame([1, 2], $sessions->pluck('session_number')->all());
    }

    public function test_database_allows_multiple_sessions_but_rejects_duplicate_session_numbers(): void
    {
        $staff = $this->createStaff();

        Attendance::create([
            'staff_id' => $staff->id,
            'date' => Carbon::today(),
            'session_number' => 1,
            'clock_in' => Carbon::now()->subHours(4),
            'clock_out' => Carbon::now()->subHours(3),
        ]);

        Attendance::create([
            'staff_id' => $staff->id,
            'date' => Carbon::today(),
            'session_number' => 2,
            'clock_in' => Carbon::now()->subHours(2),
            'clock_out' => Carbon::now()->subHour(),
        ]);

        $this->expectException(QueryException::class);
        Attendance::create([
            'staff_id' => $staff->id,
            'date' => Carbon::today(),
            'session_number' => 2,
            'clock_in' => Carbon::now(),
            'clock_out' => Carbon::now()->addHour(),
        ]);
    }

    public function test_database_rejects_more_than_one_open_session_per_staff(): void
    {
        $staff = $this->createStaff();

        Attendance::create([
            'staff_id' => $staff->id,
            'date' => '2026-08-10',
            'session_number' => 1,
            'clock_in' => '2026-08-10 08:00:00',
        ]);

        $this->expectException(QueryException::class);
        Attendance::create([
            'staff_id' => $staff->id,
            'date' => '2026-08-11',
            'session_number' => 1,
            'clock_in' => '2026-08-11 08:00:00',
        ]);
    }

    public function test_overnight_shift_closes_previous_work_date_and_applies_break(): void
    {
        $staff = $this->createStaff();
        $workDate = Carbon::parse('2026-08-10');
        StaffSchedule::create([
            'staff_id' => $staff->id,
            'day_of_week' => $workDate->format('w'),
            'shift_start' => '20:00',
            'shift_end' => '06:00',
            'break_minutes' => 60,
            'is_day_off' => false,
            'works_on_public_holiday' => false,
        ]);

        $service = app(ScanService::class);
        $clockIn = $service->handleScan($staff->staff_id, Carbon::parse('2026-08-10 20:00'));
        $clockOut = $service->handleScan($staff->staff_id, Carbon::parse('2026-08-11 06:00'));

        $this->assertSame('in', $clockIn['action'] ?? null);
        $this->assertSame('out', $clockOut['action'] ?? null);

        $attendance = Attendance::query()->where('staff_id', $staff->id)->sole();
        $this->assertSame('2026-08-10', $attendance->date->toDateString());
        $this->assertSame('2026-08-11 06:00:00', $attendance->clock_out->format('Y-m-d H:i:s'));
        $this->assertSame('9.00', $attendance->total_hours);
        $this->assertSame(0, $attendance->overtime_minutes);
        $this->assertSame(60, $attendance->break_minutes);
    }

    public function test_overnight_shift_calculates_overtime_against_next_day_boundary(): void
    {
        $staff = $this->createStaff();
        $workDate = Carbon::parse('2026-08-10');
        StaffSchedule::create([
            'staff_id' => $staff->id,
            'day_of_week' => $workDate->format('w'),
            'shift_start' => '20:00',
            'shift_end' => '06:00',
            'break_minutes' => 60,
            'is_day_off' => false,
            'works_on_public_holiday' => false,
        ]);

        $service = app(ScanService::class);
        $service->handleScan($staff->staff_id, Carbon::parse('2026-08-10 20:00'));
        $service->handleScan($staff->staff_id, Carbon::parse('2026-08-11 07:00'));

        $attendance = Attendance::query()->where('staff_id', $staff->id)->sole();
        $this->assertSame(60, $attendance->overtime_minutes);
        $this->assertSame('10.00', $attendance->total_hours);
    }

    public function test_scan_enforces_day_off_and_public_holiday_schedule_flags(): void
    {
        $dayOffStaff = $this->createStaff(['staff_id' => 'DAY/OFF']);
        $holidayStaff = $this->createStaff(['staff_id' => 'HOLIDAY/NO']);
        $workingHolidayStaff = $this->createStaff(['staff_id' => 'HOLIDAY/YES']);
        $date = Carbon::parse('2026-08-10');
        $this->seedHoliday($date->toDateString());

        StaffSchedule::create([
            'staff_id' => $dayOffStaff->id,
            'day_of_week' => $date->format('w'),
            'is_day_off' => true,
            'break_minutes' => 0,
        ]);

        foreach ([[$holidayStaff, false], [$workingHolidayStaff, true]] as [$staff, $worksHoliday]) {
            StaffSchedule::create([
                'staff_id' => $staff->id,
                'day_of_week' => $date->format('w'),
                'shift_start' => '08:00',
                'shift_end' => '17:00',
                'break_minutes' => 60,
                'is_day_off' => false,
                'works_on_public_holiday' => $worksHoliday,
            ]);
        }

        $service = app(ScanService::class);

        $this->assertSame('day_off', $service->handleScan($dayOffStaff->staff_id, $date->copy()->setTime(8, 0))['error'] ?? null);
        $holidayResult = $service->handleScan($holidayStaff->staff_id, $date->copy()->setTime(8, 0));
        $this->assertSame('public_holiday', $holidayResult['error'] ?? null, json_encode($holidayResult));
        $this->assertSame('in', $service->handleScan($workingHolidayStaff->staff_id, $date->copy()->setTime(8, 0))['action'] ?? null);
    }

    public function test_historical_scan_cannot_rewrite_a_newer_attendance_timeline(): void
    {
        $staff = $this->createStaff();
        Attendance::create([
            'staff_id' => $staff->id,
            'date' => '2026-08-11',
            'session_number' => 1,
            'clock_in' => '2026-08-11 08:00:00',
            'clock_out' => '2026-08-11 17:00:00',
        ]);

        $result = app(ScanService::class)->handleScan($staff->staff_id, Carbon::parse('2026-08-10 08:00'));

        $this->assertSame('out_of_order', $result['error'] ?? null);
        $this->assertSame(1, Attendance::query()->where('staff_id', $staff->id)->count());
    }

    public function test_debounce_and_cooldown_use_occurrence_time_not_processing_time(): void
    {
        $staff = $this->createStaff();
        Setting::setValue('scan_debounce_seconds', '120');
        Setting::setValue('scan_cooldown_seconds', '1800');
        $service = app(ScanService::class);

        $this->assertSame('in', $service->handleScan($staff->staff_id, Carbon::parse('2026-08-10 08:00:00'))['action'] ?? null);
        $this->assertSame('debounce', $service->handleScan($staff->staff_id, Carbon::parse('2026-08-10 08:01:00'))['error'] ?? null);
        $this->assertSame('cooldown', $service->handleScan($staff->staff_id, Carbon::parse('2026-08-10 08:10:00'))['error'] ?? null);
        $this->assertSame('out', $service->handleScan($staff->staff_id, Carbon::parse('2026-08-10 08:31:00'))['action'] ?? null);
    }

    public function test_missing_schedule_respects_default_work_days(): void
    {
        $staff = $this->createStaff();
        Setting::setValue('default_work_days', '[1,2,3,4,5]');

        $result = app(ScanService::class)->handleScan($staff->staff_id, Carbon::parse('2026-08-09 08:00:00'));

        $this->assertSame('day_off', $result['error'] ?? null);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_missing_schedule_applies_the_configured_default_break(): void
    {
        Carbon::setTestNow('2026-08-10 07:00:00');
        try {
            $staff = $this->createStaff();
            Setting::setValue('default_work_days', '[1,2,3,4,5]');
            Setting::setValue('default_break_minutes', '30');
            $service = app(ScanService::class);

            $service->handleScan($staff->staff_id, Carbon::parse('2026-08-10 08:00:00'));
            $service->handleScan($staff->staff_id, Carbon::parse('2026-08-10 17:00:00'));

            $attendance = Attendance::query()->where('staff_id', $staff->id)->sole();
            $this->assertSame(30, $attendance->break_minutes);
            $this->assertSame('8.50', $attendance->total_hours);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_scan_rejects_events_outside_employment_dates(): void
    {
        $staff = $this->createStaff([
            'employment_start_date' => '2026-08-10',
            'employment_end_date' => '2026-08-31',
        ]);

        $result = app(ScanService::class)->handleScan($staff->staff_id, Carbon::parse('2026-08-03 08:00:00'));

        $this->assertSame('inactive', $result['error'] ?? null);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_stale_open_session_requires_manual_correction(): void
    {
        $staff = $this->createStaff();
        Attendance::query()->create([
            'staff_id' => $staff->id,
            'date' => '2026-08-03',
            'session_number' => 1,
            'clock_in' => '2026-08-03 08:00:00',
        ]);

        $result = app(ScanService::class)->handleScan($staff->staff_id, Carbon::parse('2026-08-07 08:00:00'));

        $this->assertSame('stale_open_session', $result['error'] ?? null);
        $this->assertNull(Attendance::query()->where('staff_id', $staff->id)->sole()->clock_out);
    }

    public function test_after_midnight_clock_in_uses_previous_overnight_work_date(): void
    {
        $staff = $this->createStaff();
        StaffSchedule::query()->create([
            'staff_id' => $staff->id,
            'day_of_week' => 1,
            'shift_start' => '20:00',
            'shift_end' => '06:00',
            'break_minutes' => 60,
            'is_day_off' => false,
            'works_on_public_holiday' => false,
        ]);

        $result = app(ScanService::class)->handleScan($staff->staff_id, Carbon::parse('2026-08-11 01:00:00'));

        $this->assertSame('in', $result['action'] ?? null);
        $attendance = Attendance::query()->where('staff_id', $staff->id)->sole();
        $this->assertSame('2026-08-10', $attendance->date->toDateString());
        $this->assertTrue($attendance->is_late);
        $this->assertSame(300, $attendance->late_minutes);
    }

    public function test_inactive_staff_can_close_an_existing_open_session_but_cannot_start_one(): void
    {
        $staff = $this->createStaff();
        Attendance::query()->create([
            'staff_id' => $staff->id,
            'date' => '2026-08-10',
            'session_number' => 1,
            'clock_in' => '2026-08-10 08:00:00',
        ]);
        $staff->update(['employment_status' => 'Inactive']);

        $clockOut = app(ScanService::class)->handleScan($staff->staff_id, Carbon::parse('2026-08-10 17:00:00'));
        $newClockIn = app(ScanService::class)->handleScan($staff->staff_id, Carbon::parse('2026-08-11 08:00:00'));

        $this->assertSame('out', $clockOut['action'] ?? null);
        $this->assertSame('inactive', $newClockIn['error'] ?? null);
    }
}
