<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_returns_late_arrivals_as_a_compact_alert_list_and_uses_am_pm_session_times(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 10:00:00', config('app.timezone')));
        Setting::setValue('enable_alerts', '1');

        $viewer = User::query()->create([
            'username' => 'dashboard.hr',
            'password' => 'StrongPassword!234',
            'role' => 'hr',
            'is_active' => true,
        ]);
        $lateStaff = $this->createStaff('HGL/LA/OPS/701', 'Late Arrival');
        $onTimeStaff = $this->createStaff('HGL/LA/OPS/702', 'On Time Arrival');

        Attendance::query()->create([
            'staff_id' => $lateStaff->id,
            'date' => today()->toDateString(),
            'clock_in' => today()->setTime(8, 17),
            'clock_out' => today()->setTime(17, 0),
            'is_late' => true,
            'late_minutes' => 17,
        ]);
        Attendance::query()->create([
            'staff_id' => $onTimeStaff->id,
            'date' => today()->toDateString(),
            'clock_in' => today()->setTime(8, 0),
            'clock_out' => today()->setTime(17, 0),
            'is_late' => false,
            'late_minutes' => 0,
        ]);

        Sanctum::actingAs($viewer, $viewer->permissions());

        $this->getJson('/api/dashboard/today')
            ->assertOk()
            ->assertJsonCount(1, 'late_clock_in_alerts')
            ->assertJsonPath('late_clock_in_alerts.0.staff_name', 'Late Arrival')
            ->assertJsonPath('late_clock_in_alerts.0.late_minutes', 17);

        $this->getJson('/api/dashboard/sessions/late')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.full_name', 'Late Arrival')
            ->assertJsonPath('0.clock_in', '8:17 AM')
            ->assertJsonPath('0.clock_out', '5:00 PM');
    }

    private function createStaff(string $staffId, string $name): Staff
    {
        return Staff::query()->create([
            'staff_id' => $staffId,
            'full_name' => $name,
            'department' => 'Operations',
            'employment_status' => 'Active',
        ]);
    }
}
