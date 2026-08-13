<?php

namespace Tests\Feature;

use App\Exports\AttendanceReportExport;
use App\Models\Attendance;
use App\Models\PublicHoliday;
use App\Models\Staff;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Services\ReportRowsService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicHolidayIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2019-01-01 12:00:00');
        $this->artisan('migrate:fresh');

        $user = User::query()->create([
            'username' => 'holiday-manager',
            'password' => 'a-secure-test-password',
            'role' => 'hr',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        Sanctum::actingAs($user, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_api_prevents_duplicate_annual_dates_and_one_off_overrides(): void
    {
        $first = $this->postJson('/api/public-holidays', [
            'date' => '2025-12-25',
            'name' => 'Christmas Day',
            'is_recurring' => true,
        ])->assertCreated();

        $this->postJson('/api/public-holidays', [
            'date' => '2026-12-25',
            'name' => 'Duplicate Christmas',
            'is_recurring' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->postJson('/api/public-holidays', [
            'date' => '2027-12-25',
            'name' => 'One-off Christmas',
            'is_recurring' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->putJson('/api/public-holidays/'.$first->json('id'), [
            'date' => '2020-12-25',
        ])->assertOk();

        $this->assertDatabaseCount('public_holidays', 1);
    }

    public function test_two_non_recurring_holidays_may_share_month_and_day_in_different_years(): void
    {
        foreach ([2026, 2027] as $year) {
            $this->postJson('/api/public-holidays', [
                'date' => "{$year}-05-01",
                'name' => "Special closure {$year}",
                'is_recurring' => false,
            ])->assertCreated();
        }

        $this->assertDatabaseCount('public_holidays', 2);
    }

    public function test_database_constraint_prevents_duplicate_recurring_month_day(): void
    {
        PublicHoliday::query()->create([
            'date' => '2025-01-01',
            'name' => 'New Year',
            'is_recurring' => true,
        ]);

        $this->expectException(QueryException::class);
        PublicHoliday::query()->create([
            'date' => '2026-01-01',
            'name' => 'Duplicate New Year',
            'is_recurring' => true,
        ]);
    }

    public function test_explicit_one_off_definition_wins_when_legacy_data_overlaps_recurring_date(): void
    {
        PublicHoliday::query()->create([
            'date' => '2020-06-12',
            'name' => 'Annual Democracy Day',
            'is_recurring' => true,
        ]);
        $oneOff = PublicHoliday::query()->create([
            'date' => '2026-06-12',
            'name' => 'Renamed Democracy Celebration',
            'is_recurring' => false,
        ]);

        $occurrence = PublicHoliday::occurrencesBetween(
            Carbon::parse('2026-06-12'),
            Carbon::parse('2026-06-12')
        )->sole();

        $this->assertSame($oneOff->id, $occurrence->id);
        $this->assertSame('Renamed Democracy Celebration', $occurrence->name);
    }

    public function test_holiday_work_report_contains_only_actual_sessions_with_holiday_context(): void
    {
        $holiday = PublicHoliday::query()->create([
            'date' => '2026-08-10',
            'name' => 'Company Foundation Day',
            'is_recurring' => false,
        ]);
        $staff = $this->staff();
        StaffSchedule::query()->create([
            'staff_id' => $staff->id,
            'day_of_week' => '1',
            'shift_start' => '08:00',
            'shift_end' => '17:00',
            'break_minutes' => 60,
            'is_day_off' => false,
            'works_on_public_holiday' => true,
        ]);
        $filters = [
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-10',
            'status' => 'public_holiday_work',
        ];

        $this->assertCount(0, app(ReportRowsService::class)->build($filters));

        Attendance::query()->create([
            'staff_id' => $staff->id,
            'date' => '2026-08-10',
            'session_number' => 1,
            'clock_in' => '2026-08-10 08:00:00',
            'clock_out' => '2026-08-10 17:00:00',
            'break_minutes' => 60,
            'total_hours' => 8,
        ]);
        $row = app(ReportRowsService::class)->build($filters)->sole();

        $this->assertSame($holiday->name, $row['holiday_name']);
        $this->assertSame('Public Holiday Work', $row['status']);
        $this->assertSame(1, $row['session_number']);

        $exported = (new AttendanceReportExport(collect([$row])))->collection()->sole();
        $this->assertSame($holiday->name, $exported[6]);
        $this->assertSame('Public Holiday Work', $exported[15]);
    }

    public function test_incomplete_holiday_session_is_explicitly_identified(): void
    {
        PublicHoliday::query()->create([
            'date' => '2026-08-10',
            'name' => 'Company Foundation Day',
            'is_recurring' => false,
        ]);
        $staff = $this->staff();
        Attendance::query()->create([
            'staff_id' => $staff->id,
            'date' => '2026-08-10',
            'session_number' => 1,
            'clock_in' => '2026-08-10 08:00:00',
        ]);

        $row = app(ReportRowsService::class)->build([
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-10',
            'status' => 'public_holiday_work',
        ])->sole();

        $this->assertSame('Public Holiday Work (Incomplete)', $row['status']);
    }

    private function staff(): Staff
    {
        return Staff::query()->create([
            'staff_id' => 'HOLIDAY/001',
            'company' => 'Hogan Guards',
            'full_name' => 'Holiday Worker',
            'department' => 'Operations',
            'branch' => 'Headquarters',
            'job_title' => 'Guard',
            'employment_status' => 'Active',
            'employment_start_date' => '2025-01-01',
        ]);
    }
}
