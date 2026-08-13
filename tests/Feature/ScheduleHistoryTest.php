<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Staff;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_schedule_changes_do_not_rewrite_prior_dates(): void
    {
        Carbon::setTestNow('2026-01-15 12:00:00');
        $staff = $this->staff();

        Setting::setValue('shift_start', '09:00');
        Setting::setValue('shift_end', '18:00');
        Setting::setValue('default_break_minutes', '30');

        $service = app(ScheduleService::class);
        $before = $service->effectiveShift($staff, Carbon::parse('2026-01-14'));
        $after = $service->effectiveShift($staff, Carbon::parse('2026-01-15'));

        $this->assertSame('08:00', $before['shift_start']);
        $this->assertSame('17:00', $before['shift_end']);
        $this->assertSame(60, $before['break_minutes']);
        $this->assertSame('09:00', $after['shift_start']);
        $this->assertSame('18:00', $after['shift_end']);
        $this->assertSame(30, $after['break_minutes']);
    }

    public function test_staff_schedule_changes_are_effective_dated(): void
    {
        Carbon::setTestNow('2026-01-10 12:00:00');
        $staff = $this->staff();
        $service = app(ScheduleService::class);

        $service->upsert($staff->id, [[
            'day_of_week' => 1,
            'shift_start' => '07:00',
            'shift_end' => '15:00',
            'break_minutes' => 20,
            'is_day_off' => false,
            'works_on_public_holiday' => true,
        ]]);

        $before = $service->effectiveShift($staff, Carbon::parse('2026-01-05'));
        $after = $service->effectiveShift($staff, Carbon::parse('2026-01-12'));

        $this->assertSame('08:00', $before['shift_start']);
        $this->assertSame(60, $before['break_minutes']);
        $this->assertSame('07:00', $after['shift_start']);
        $this->assertSame(20, $after['break_minutes']);
        $this->assertTrue($after['works_on_public_holiday']);
    }

    private function staff(): Staff
    {
        return Staff::query()->create([
            'staff_id' => 'HGL/LA/OPS/777',
            'company' => 'Hogan Guards',
            'full_name' => 'Historical Schedule',
            'department' => 'Operations',
            'branch' => 'Lagos (HQ)',
            'employment_status' => 'Active',
            'employment_start_date' => '2026-01-01',
        ]);
    }
}
