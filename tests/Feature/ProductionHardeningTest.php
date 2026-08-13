<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\AppConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_api_paths_never_fall_through_to_the_spa(): void
    {
        $this->getJson('/api/not-a-real-endpoint')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_organization_names_and_generated_codes_are_consistent_and_audited(): void
    {
        $this->actingAsItManager();

        $this->putJson('/api/lookups/departments', [
            'departments' => ['Alpha Team', 'Another Team'],
        ])->assertOk();

        $codes = json_decode((string) Setting::getValue('department_codes'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['Alpha Team', 'Another Team'], array_keys($codes));
        $this->assertCount(2, array_unique(array_values($codes)));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2,4}$/', $code);
        }

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'LookupController.updateDepartments',
            'method' => 'PUT',
            'status_code' => 200,
        ]);

        $this->putJson('/api/lookups/departments', [
            'departments' => ['Operations', ' operations '],
        ])->assertUnprocessable();

        $this->assertSame(1, ActivityLog::query()
            ->where('action', 'LookupController.updateDepartments')
            ->where('status_code', 422)
            ->count());
    }

    public function test_department_lookup_preserves_legacy_staff_values_when_adding_or_removing_departments(): void
    {
        $this->actingAsItManager();
        Setting::setValue('departments', json_encode(['Operations', 'Unused Team'], JSON_THROW_ON_ERROR));
        Setting::setValue('department_codes', json_encode(['Operations' => 'OPS', 'Unused Team' => 'UT'], JSON_THROW_ON_ERROR));
        Staff::query()->create([
            'staff_id' => 'HGL/LA/LEG/001',
            'full_name' => 'Legacy Department Guard',
            'department' => 'Legacy Team',
            'employment_status' => 'Active',
        ]);

        $departments = $this->getJson('/api/lookups/departments')
            ->assertOk()
            ->json();
        $this->assertSame(['Operations', 'Unused Team', 'Legacy Team'], $departments);

        $this->putJson('/api/lookups/departments', [
            'departments' => [...$departments, 'New Team'],
        ])->assertOk()->assertJsonPath('departments.3', 'New Team');

        $this->putJson('/api/lookups/departments', [
            'departments' => ['Operations', 'Legacy Team', 'New Team'],
        ])->assertOk()->assertJsonMissing(['Unused Team']);

        $this->putJson('/api/lookups/departments', [
            'departments' => ['Operations', 'New Team'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('departments')
            ->assertJsonPath('errors.departments.0', 'Cannot remove values still assigned to staff. Reassign these staff records first: Legacy Team');
    }

    public function test_legacy_department_can_be_saved_and_used_for_the_next_staff_id(): void
    {
        $this->actingAsItManager();
        Storage::fake('local');
        Setting::setValue('departments', json_encode(['Operations'], JSON_THROW_ON_ERROR));
        Setting::setValue('department_codes', json_encode(['Operations' => 'OPS'], JSON_THROW_ON_ERROR));
        $staff = Staff::query()->create([
            'staff_id' => 'HGL/LA/ACC/001',
            'company' => 'Hogan Guards',
            'full_name' => 'Head of Account',
            'department' => 'Account',
            'job_title' => 'Head of Account',
            'branch' => 'Lagos (HQ)',
            'employment_status' => 'Active',
            'employment_start_date' => '2026-01-01',
        ]);

        $this->getJson('/api/staff/next-id?department=Account&branch=Lagos%20(HQ)&company=Hogan%20Guards')
            ->assertOk()
            ->assertJsonPath('staff_id', 'HGL/LA/ACC/002');

        $this->put("/api/staff/{$staff->id}", [
            'staff_id' => $staff->staff_id,
            'company' => $staff->company,
            'full_name' => $staff->full_name,
            'department' => $staff->department,
            'job_title' => $staff->job_title,
            'branch' => $staff->branch,
            'employment_status' => $staff->employment_status,
            'employment_start_date' => $staff->employment_start_date->toDateString(),
            'employment_end_date' => null,
            'photo' => UploadedFile::fake()->image('head-of-account.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('department', 'Account');

        Storage::disk('local')->assertExists((string) $staff->fresh()->photo_path);
    }

    public function test_department_configuration_can_grow_beyond_the_previous_twenty_item_limit(): void
    {
        $this->actingAsItManager();
        $departments = array_map(fn (int $number): string => "Team {$number}", range(1, 21));

        $this->putJson('/api/lookups/departments', [
            'departments' => $departments,
        ])->assertOk()->assertJsonCount(21, 'departments');
    }

    public function test_settings_accept_the_same_cidr_separators_that_enforcement_uses(): void
    {
        $this->actingAsItManager();
        $settings = app(AppConfigService::class)->allSettings();
        $settings['scan_allowed_ips'] = "10.10.0.0/16; 192.168.4.8\nfd00::/64";

        $this->putJson('/api/settings', $settings)
            ->assertOk()
            ->assertJsonPath('scan_allowed_ips', $settings['scan_allowed_ips']);

        $this->assertSame(
            ['10.10.0.0/16', '192.168.4.8', 'fd00::/64'],
            app(AppConfigService::class)->allowedScanCidrs()
        );
    }

    private function actingAsItManager(): User
    {
        $manager = User::query()->create([
            'username' => 'production.manager',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        Sanctum::actingAs($manager, $manager->permissions());

        return $manager;
    }
}
