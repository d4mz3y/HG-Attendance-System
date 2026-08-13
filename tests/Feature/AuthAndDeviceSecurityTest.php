<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuthEvent;
use App\Models\KioskDevice;
use App\Models\KioskScanQueue;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\DeviceTokenService;
use App\Services\ReceptionTerminalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthAndDeviceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_login_issues_an_expiring_scoped_token_and_records_audit_event(): void
    {
        $user = User::query()->create([
            'username' => 'hr.user',
            'password' => 'StrongPassword!234',
            'role' => 'hr',
            'is_active' => true,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.4'])->postJson('/api/login', [
            'username' => 'hr.user',
            'password' => 'StrongPassword!234',
        ])->assertOk()
            ->assertJsonPath('user.role', 'hr')
            ->assertJsonPath('user.permissions.0', 'dashboard.view');

        $plainToken = $response->json('token');
        $this->assertNotEmpty($plainToken);
        $this->assertNotNull($user->fresh()->tokens()->first()->expires_at);

        $this->withToken($plainToken)->getJson('/api/settings')->assertOk();
        $this->withToken($plainToken)->putJson('/api/settings', [])->assertForbidden();
        $this->assertDatabaseHas('auth_events', [
            'user_id' => $user->id,
            'event' => 'login_success',
            'ip_address' => '10.10.10.4',
        ]);
    }

    public function test_remember_me_issues_a_longer_lived_portal_token_only_when_requested(): void
    {
        Carbon::setTestNow('2026-08-13 09:30:00');
        config()->set('hg.auth_token_expiration_minutes', 480);
        config()->set('hg.auth_remember_expiration_days', 30);

        $user = User::query()->create([
            'username' => 'remembered.user',
            'password' => 'StrongPassword!234',
            'role' => 'hr',
            'is_active' => true,
        ]);

        $ordinaryResponse = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'StrongPassword!234',
        ])->assertOk();

        $ordinaryToken = $user->fresh()->tokens()->latest('id')->firstOrFail();
        $this->assertTrue($ordinaryToken->expires_at->equalTo(now()->addMinutes(480)));

        $rememberedResponse = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'StrongPassword!234',
            'remember' => true,
        ])->assertOk();

        $rememberedToken = $user->fresh()->tokens()->latest('id')->firstOrFail();
        $this->assertTrue($rememberedToken->expires_at->equalTo(now()->addDays(30)));
        $this->assertTrue($rememberedToken->expires_at->isAfter($ordinaryToken->expires_at));

        Carbon::setTestNow(now()->addHours(9));
        $this->withToken($ordinaryResponse->json('token'))->getJson('/api/user')->assertUnauthorized();
        $this->withToken($rememberedResponse->json('token'))->getJson('/api/user')->assertOk();
    }

    public function test_disabled_accounts_cannot_login_and_failed_attempt_is_audited(): void
    {
        $user = User::query()->create([
            'username' => 'disabled.user',
            'password' => 'StrongPassword!234',
            'role' => 'hr_assistant',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'username' => 'disabled.user',
            'password' => 'StrongPassword!234',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');

        $this->assertDatabaseHas('auth_events', [
            'user_id' => $user->id,
            'event' => 'login_failed',
        ]);

        $this->postJson('/api/login', [
            'username' => str_repeat('a', 65),
            'password' => str_repeat('b', 1025),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_hr_accounts_are_not_blocked_by_a_legacy_force_change_flag_and_cannot_self_change_passwords(): void
    {
        User::query()->create([
            'username' => 'forced.user',
            'password' => 'TemporaryPass!234',
            'role' => 'hr_assistant',
            'is_active' => true,
            'must_change_password' => true,
        ]);

        $token = $this->postJson('/api/login', [
            'username' => 'forced.user',
            'password' => 'TemporaryPass!234',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/dashboard/today')->assertOk();

        $this->withToken($token)->postJson('/api/change-password', [
            'current_password' => 'TemporaryPass!234',
            'password' => 'PermanentPassword!567',
            'password_confirmation' => 'PermanentPassword!567',
        ])->assertForbidden();
    }

    public function test_password_change_refetches_locked_user_after_an_administrator_reset(): void
    {
        $user = User::query()->create([
            'username' => 'stale.session',
            'password' => 'OriginalPassword!234',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, $user->permissions());

        User::query()->whereKey($user->id)->update([
            'password' => Hash::make('AdministratorReset!567'),
            'must_change_password' => true,
        ]);

        $this->postJson('/api/change-password', [
            'current_password' => 'OriginalPassword!234',
            'password' => 'AttackerOverwrite!890',
            'password_confirmation' => 'AttackerOverwrite!890',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('AdministratorReset!567', $fresh->password));
        $this->assertTrue($fresh->must_change_password);
        $this->assertDatabaseHas('auth_events', ['user_id' => $user->id, 'event' => 'password_change_failed']);
    }

    public function test_passwords_exceeding_bcrypts_byte_limit_are_rejected_consistently(): void
    {
        // This stays below the 64-character UI limit but is 74 UTF-8 bytes.
        // Bcrypt would otherwise silently ignore the last two bytes.
        $tooLongPassword = str_repeat('é', 35).'Aa1!';
        $this->assertSame(74, strlen($tooLongPassword));

        $user = User::query()->create([
            'username' => 'byte.limit.user',
            'password' => 'OriginalPassword!234',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => $tooLongPassword,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        Sanctum::actingAs($user, $user->permissions());
        $this->postJson('/api/change-password', [
            'current_password' => 'OriginalPassword!234',
            'password' => $tooLongPassword,
            'password_confirmation' => $tooLongPassword,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $manager = User::query()->create([
            'username' => 'byte.limit.manager',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        Sanctum::actingAs($manager, $manager->permissions());

        $this->postJson('/api/users', [
            'username' => 'too.long.password',
            'role' => 'hr_assistant',
            'password' => $tooLongPassword,
            'password_confirmation' => $tooLongPassword,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $target = User::query()->create([
            'username' => 'byte.limit.target',
            'password' => 'StrongPassword!234',
            'role' => 'hr_assistant',
            'is_active' => true,
        ]);
        $this->postJson("/api/users/{$target->id}/reset-password", [
            'password' => $tooLongPassword,
            'password_confirmation' => $tooLongPassword,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_it_manager_can_create_reset_disable_and_revoke_user_access(): void
    {
        $manager = User::query()->create([
            'username' => 'it.manager',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        Sanctum::actingAs($manager, $manager->permissions());

        $response = $this->postJson('/api/users', [
            'username' => 'assistant.one',
            'role' => 'hr_assistant',
            'password' => 'AssignedPassword!234',
            'password_confirmation' => 'AssignedPassword!234',
        ])->assertCreated();
        $response->assertJsonMissingPath('temporary_password');

        $created = User::query()->where('username', 'assistant.one')->firstOrFail();
        $this->assertFalse($created->must_change_password);
        $this->assertTrue(Hash::check('AssignedPassword!234', $created->password));

        $this->postJson("/api/users/{$created->id}/reset-password", [
            'password' => 'ReplacementPassword!567',
            'password_confirmation' => 'ReplacementPassword!567',
        ])->assertOk()->assertJsonPath('ok', true);
        $this->assertTrue(Hash::check('ReplacementPassword!567', $created->fresh()->password));
        $this->assertFalse($created->fresh()->must_change_password);
        $this->postJson("/api/users/{$manager->id}/reset-password", [
            'password' => 'NeverUsedPassword!789',
            'password_confirmation' => 'NeverUsedPassword!789',
        ])->assertStatus(422);
        $this->putJson("/api/users/{$created->id}", ['is_active' => false])->assertOk();
        $this->assertFalse($created->fresh()->is_active);
        $this->assertGreaterThanOrEqual(3, AuthEvent::query()->where('user_id', $manager->id)->count());
    }

    public function test_only_the_super_administrator_can_change_their_own_password(): void
    {
        $hr = User::query()->create([
            'username' => 'password.hr',
            'password' => 'OriginalPassword!234',
            'role' => 'hr',
            'is_active' => true,
        ]);
        $super = User::query()->create([
            'username' => 'password.super',
            'password' => 'OriginalPassword!234',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($hr, $hr->permissions());
        $this->postJson('/api/change-password', [
            'current_password' => 'OriginalPassword!234',
            'password' => 'NewHrPassword!567',
            'password_confirmation' => 'NewHrPassword!567',
        ])->assertForbidden();
        $this->assertTrue(Hash::check('OriginalPassword!234', $hr->fresh()->password));

        Sanctum::actingAs($super, $super->permissions());
        $this->postJson('/api/change-password', [
            'current_password' => 'OriginalPassword!234',
            'password' => 'NewSuperPassword!567',
            'password_confirmation' => 'NewSuperPassword!567',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->assertTrue(Hash::check('NewSuperPassword!567', $super->fresh()->password));
    }

    public function test_hr_roles_can_manage_organization_and_view_settings_but_not_technical_activity_or_portal_administration(): void
    {
        $assistant = User::query()->create([
            'username' => 'audit.assistant',
            'password' => 'StrongPassword!234',
            'role' => 'hr_assistant',
            'is_active' => true,
        ]);
        $hr = User::query()->create([
            'username' => 'audit.hr',
            'password' => 'StrongPassword!234',
            'role' => 'hr',
            'is_active' => true,
        ]);
        $manager = User::query()->create([
            'username' => 'access.it',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        $super = User::query()->create([
            'username' => 'access.super',
            'password' => 'StrongPassword!234',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        foreach ([$assistant, $hr] as $user) {
            Sanctum::actingAs($user, $user->permissions());
            $this->getJson('/api/audits')->assertOk();
            $this->getJson('/api/activity-logs')->assertForbidden();
            $this->getJson('/api/lookups/departments')->assertOk();
            $this->putJson('/api/lookups/departments', ['departments' => ['Security']])->assertOk();
            $this->getJson('/api/users')->assertForbidden();
            $this->getJson('/api/devices')->assertForbidden();
            $this->getJson('/api/settings')->assertOk();
            $this->putJson('/api/settings', [])->assertForbidden();
        }

        Sanctum::actingAs($manager, $manager->permissions());
        $this->getJson('/api/activity-logs')->assertOk();
        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/devices')->assertOk();

        Sanctum::actingAs($super, $super->permissions());
        $this->getJson('/api/activity-logs')->assertOk();
        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/devices')->assertOk();
        $this->getJson('/api/settings')->assertOk();
    }

    public function test_super_admin_recovery_command_requires_confirmation_before_changing_anything(): void
    {
        $super = User::query()->create([
            'username' => 'recover.super',
            'password' => 'OriginalPassword!234',
            'role' => 'super_admin',
            'is_active' => false,
        ]);
        $originalHash = $super->password;

        $this->artisan('users:recover-super-admin', ['username' => $super->username])
            ->expectsConfirmation('Continue with this super administrator recovery?', false)
            ->expectsOutput('Recovery cancelled. No changes were made.')
            ->assertSuccessful();

        $super->refresh();
        $this->assertSame($originalHash, $super->password);
        $this->assertFalse($super->is_active);
        $this->assertDatabaseMissing('auth_events', [
            'user_id' => $super->id,
            'event' => 'super_admin_password_recovered',
        ]);
    }

    public function test_first_super_admin_can_be_bootstrapped_once_from_the_local_console(): void
    {
        $this->artisan('users:create-super-admin', ['username' => 'first.owner'])
            ->expectsQuestion('New password (not echoed)', 'FirstOwnerPassword!567')
            ->expectsQuestion('Confirm new password (not echoed)', 'FirstOwnerPassword!567')
            ->expectsConfirmation('Create this super administrator?', 'yes')
            ->assertSuccessful();

        $super = User::query()->where('username', 'first.owner')->sole();
        $this->assertSame('super_admin', $super->role);
        $this->assertTrue($super->is_active);
        $this->assertTrue(Hash::check('FirstOwnerPassword!567', $super->password));
        $this->assertDatabaseHas('auth_events', [
            'user_id' => $super->id,
            'event' => 'super_admin_created',
        ]);

        $this->artisan('users:create-super-admin', ['username' => 'second.owner'])
            ->expectsOutput('A super administrator already exists. Use users:recover-super-admin for password recovery; no changes were made.')
            ->assertFailed();
        $this->assertDatabaseMissing('users', ['username' => 'second.owner']);
    }

    public function test_first_super_admin_bootstrap_can_safely_promote_only_a_legacy_admin_account(): void
    {
        $legacy = User::query()->create([
            'username' => 'legacy.admin',
            'password' => 'OldPassword!234',
            'role' => 'admin',
            'is_active' => false,
        ]);
        $legacy->createToken('old-session', ['dashboard.view']);

        $this->artisan('users:create-super-admin', ['username' => $legacy->username])
            ->expectsQuestion('New password (not echoed)', 'PromotedOwnerPassword!567')
            ->expectsQuestion('Confirm new password (not echoed)', 'PromotedOwnerPassword!567')
            ->expectsConfirmation('Create this super administrator?', 'yes')
            ->assertSuccessful();

        $legacy->refresh();
        $this->assertSame('super_admin', $legacy->role);
        $this->assertTrue($legacy->is_active);
        $this->assertTrue(Hash::check('PromotedOwnerPassword!567', $legacy->password));
        $this->assertSame(0, $legacy->tokens()->count());
        $this->assertDatabaseHas('auth_events', [
            'user_id' => $legacy->id,
            'event' => 'legacy_admin_promoted_to_super_admin',
        ]);
    }

    public function test_super_admin_recovery_command_resets_only_the_selected_super_admin_after_confirmation(): void
    {
        $super = User::query()->create([
            'username' => 'recover.confirmed',
            'password' => 'OriginalPassword!234',
            'role' => 'super_admin',
            'is_active' => false,
        ]);
        $super->createToken('old-session', ['*']);

        $this->artisan('users:recover-super-admin')
            ->expectsConfirmation('Continue with this super administrator recovery?', 'yes')
            ->expectsQuestion('New password (not echoed)', 'RecoveredPassword!567')
            ->expectsQuestion('Confirm new password (not echoed)', 'RecoveredPassword!567')
            ->assertSuccessful();

        $super->refresh();
        $this->assertTrue($super->is_active);
        $this->assertFalse($super->must_change_password);
        $this->assertTrue(Hash::check('RecoveredPassword!567', $super->password));
        $this->assertSame(0, $super->tokens()->count());
        $this->assertDatabaseHas('auth_events', [
            'user_id' => $super->id,
            'event' => 'super_admin_password_recovered',
        ]);
    }

    public function test_login_is_rate_limited_per_account_and_ip(): void
    {
        User::query()->create([
            'username' => 'limited.user',
            'password' => 'StrongPassword!234',
            'role' => 'hr',
            'is_active' => true,
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->postJson('/api/login', [
                'username' => 'limited.user',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->postJson('/api/login', [
            'username' => 'limited.user',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_signed_device_event_is_idempotent_and_uses_original_timestamp(): void
    {
        Carbon::setTestNow('2026-08-12 08:00:00');
        [$device, $token, $secret] = $this->issueDevice();
        Carbon::setTestNow('2026-08-12 12:00:00');
        $staff = Staff::query()->create([
            'staff_id' => 'HGL/LA/SEC/900',
            'full_name' => 'Offline Guard',
            'department' => 'Security',
            'employment_status' => 'Active',
        ]);
        $event = $this->signedEvent($secret, 1, '2026-08-12T08:15:00.000Z', $staff->staff_id);

        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan', $event)
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('status', 'synced')
            ->assertJsonPath('result.action', 'in');

        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan', $event)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        Carbon::setTestNow('2026-08-16 12:00:00');
        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan', $event)
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('status', 'synced');
        Carbon::setTestNow('2026-08-12 12:00:00');

        $attendance = Attendance::query()->sole();
        $this->assertTrue($attendance->clock_in->utc()->equalTo(Carbon::parse($event['occurred_at'])->utc()));
        $queued = KioskScanQueue::query()->sole();
        $this->assertTrue($queued->occurred_at->utc()->equalTo(Carbon::parse($event['occurred_at'])->utc()));
        $this->assertSame($event['occurred_at'], $queued->occurred_at_raw);
        $this->assertTrue($device->fresh()->last_event_at->utc()->equalTo(Carbon::parse($event['occurred_at'])->utc()));
        $this->assertSame($event['occurred_at'], $device->fresh()->last_event_at_raw);
        $this->assertSame(1, $device->fresh()->last_sequence);

        $outOfOrder = $this->signedEvent($secret, 2, '2026-08-12T08:14:59.000Z', $staff->staff_id);
        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan', $outOfOrder)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'out_of_order');
        $this->assertSame(1, $device->fresh()->last_sequence);
    }

    public function test_device_replay_tampering_sequence_gaps_revocation_and_ip_rules_are_rejected(): void
    {
        Carbon::setTestNow('2026-08-12 08:00:00');
        [$device, $token, $secret] = $this->issueDevice();
        Carbon::setTestNow('2026-08-12 12:00:00');
        $first = $this->signedEvent($secret, 1, '2026-08-12T08:15:00.900Z', 'UNKNOWN/001');

        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan', $first)
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('status', 'failed');

        $tampered = $this->signedEvent($secret, 1, $first['occurred_at'], 'UNKNOWN/CHANGED', $first['event_id']);
        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan', $tampered)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'event_tampered');

        $sameSecondEarlier = $this->signedEvent($secret, 2, '2026-08-12T08:15:00.100Z', 'UNKNOWN/002');
        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan', $sameSecondEarlier)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'out_of_order');
        $this->assertSame(1, $device->fresh()->last_sequence);

        $gap = $this->signedEvent($secret, 3, '2026-08-12T08:20:00.000Z', 'UNKNOWN/003');
        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan', $gap)
            ->assertStatus(409)
            ->assertJsonPath('error', 'sequence_gap')
            ->assertJsonPath('expected_sequence', 2);

        Setting::setValue('scan_allowed_ips', '10.50.0.0/24');
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.10'])
            ->withHeader('X-Device-Token', $token)
            ->getJson('/api/scan/config')
            ->assertForbidden();

        Setting::setValue('scan_allowed_ips', '');
        $device->update(['allowed_ips' => '10.60.0.25']);
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.10'])
            ->withHeader('X-Device-Token', $token)
            ->getJson('/api/scan/config')
            ->assertUnauthorized();

        $this->withServerVariables(['REMOTE_ADDR' => '10.60.0.25'])
            ->withHeader('X-Device-Token', $token)
            ->getJson('/api/scan/config')
            ->assertOk();

        $device->update(['is_active' => false, 'revoked_at' => now()]);
        $this->withHeader('X-Device-Token', $token)->getJson('/api/scan/config')->assertUnauthorized();
    }

    public function test_reception_scanner_is_configured_by_it_then_pairs_without_a_staff_passcode(): void
    {
        $secret = str_repeat('a', 64);
        $this->withServerVariables(['REMOTE_ADDR' => '10.70.0.25'])
            ->postJson('/api/scan/reception/pair', ['secret' => $secret])
            ->assertStatus(409);

        $manager = User::query()->create([
            'username' => 'reception.manager',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        Sanctum::actingAs($manager, $manager->permissions());
        $device = $this->getJson('/api/devices')
            ->assertOk()
            ->assertJsonPath('data.0.paired', false)
            ->assertJsonPath('data.0.allowed_ips', null)
            ->json('data.0');

        $this->putJson("/api/devices/{$device['id']}", [
            'name' => 'Reception scanner',
            'allowed_ips' => '10.70.0.25',
        ])->assertOk()->assertJsonPath('device.allowed_ips', '10.70.0.25');

        $this->putJson("/api/devices/{$device['id']}", [
            'allowed_ips' => '10.70.0.0/24',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('allowed_ips');

        $paired = $this->withServerVariables(['REMOTE_ADDR' => '10.70.0.25'])
            ->postJson('/api/scan/reception/pair', ['secret' => $secret])
            ->assertOk()
            ->assertJsonPath('device.name', 'Reception scanner')
            ->assertJsonMissingPath('secret')
            ->json('device');

        $token = $paired['id'].'.'.$secret;
        $this->withServerVariables(['REMOTE_ADDR' => '10.70.0.25'])
            ->withHeader('X-Device-Token', $token)
            ->getJson('/api/scan/config')
            ->assertOk()
            ->assertJsonPath('device.name', 'Reception scanner')
            ->assertJsonPath('offline_enabled', true);

        $this->withServerVariables(['REMOTE_ADDR' => '10.70.0.26'])
            ->withHeader('X-Device-Token', $token)
            ->getJson('/api/scan/config')
            ->assertUnauthorized();

        $this->withServerVariables(['REMOTE_ADDR' => '10.70.0.25'])
            ->withoutHeader('X-Device-Token')
            ->getJson('/api/scan/config')
            ->assertUnauthorized();
    }

    public function test_reception_setup_reports_a_pending_database_update_instead_of_a_server_error(): void
    {
        $manager = User::query()->create([
            'username' => 'schema.manager',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        Sanctum::actingAs($manager, $manager->permissions());

        Schema::shouldReceive('hasColumns')
            ->once()
            ->with('kiosk_devices', ['terminal_role', 'paired_at'])
            ->andReturnFalse();

        $this->getJson('/api/devices')
            ->assertStatus(503)
            ->assertJsonPath('message', 'The reception scanner database update is still pending. IT must update the office server, then try again.');
    }

    public function test_it_manager_can_view_only_safe_device_event_history(): void
    {
        $manager = User::query()->create([
            'username' => 'event.viewer',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        [$device] = $this->issueDevice();
        KioskScanQueue::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'kiosk_device_id' => $device->id,
            'sequence' => 1,
            'staff_id_code' => 'HGL/LA/SEC/999',
            'occurred_at' => now(),
            'signature' => str_repeat('a', 64),
            'payload_hash' => str_repeat('b', 64),
            'status' => 'failed',
            'error_code' => 'not_found',
            'error_message' => 'Staff not found',
            'processed_at' => now(),
        ]);
        Sanctum::actingAs($manager, $manager->permissions());

        $this->getJson("/api/devices/{$device->id}/events")
            ->assertOk()
            ->assertJsonPath('data.0.error_code', 'not_found')
            ->assertJsonMissingPath('data.0.signature')
            ->assertJsonMissingPath('data.0.payload_hash');

        $this->getJson('/api/auth-events?event[]=invalid')->assertUnprocessable();
    }

    public function test_it_can_disable_enable_and_explicitly_repair_the_one_reception_scanner(): void
    {
        $manager = User::query()->create([
            'username' => 'device.manager',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        [$device, $token] = $this->issueDevice();
        Sanctum::actingAs($manager, $manager->permissions());

        $this->postJson("/api/devices/{$device->id}/disable")
            ->assertOk()
            ->assertJsonPath('device.is_active', false);
        $this->withHeader('X-Device-Token', $token)->getJson('/api/scan/config')->assertUnauthorized();

        // Re-pairing is not an implicit enable operation. A deliberately
        // disabled reception terminal must stay disabled until IT enables it.
        $this->postJson("/api/devices/{$device->id}/re-pair", ['confirm_queue_resolved' => true])
            ->assertStatus(409);

        $this->postJson("/api/devices/{$device->id}/enable")
            ->assertOk()
            ->assertJsonPath('device.is_active', true);
        $this->postJson("/api/devices/{$device->id}/re-pair", ['confirm_queue_resolved' => true])
            ->assertOk()
            ->assertJsonPath('device.paired', false);

        $device->refresh();
        $this->assertSame(str_repeat('0', 64), $device->token_hash);
        $this->assertNull($device->paired_at);
    }

    public function test_blocked_offline_queue_requires_exact_audited_it_recovery(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        [$device, $token, $secret] = $this->issueDevice();
        $blocked = $this->signedEvent($secret, 1, '2026-08-01T08:00:00.000Z', 'HGL/LA/SEC/404');

        $rejection = $this->withHeader('X-Device-Token', $token)->postJson('/api/scan', $blocked)
            ->assertUnprocessable()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('error', 'event_too_old')
            ->json();
        $this->assertSame(0, KioskScanQueue::query()->count());

        $payload = ['blocked_events' => [[
            'event_id' => $blocked['event_id'],
            'sequence' => $blocked['sequence'],
            'code' => $blocked['code'],
            'occurred_at' => $blocked['occurred_at'],
            'signature' => $blocked['signature'],
            'error' => $rejection['error'],
            'message' => $rejection['message'],
        ]]];
        $requestId = $this->withHeader('X-Device-Token', $token)->postJson('/api/scan/recover', $payload)
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending')
            ->json('request_id');

        $manager = User::query()->create([
            'username' => 'recovery.manager',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        Sanctum::actingAs($manager, $manager->permissions());
        $recovery = $device->recoveryRequests()->where('request_uuid', $requestId)->sole();
        $this->getJson("/api/devices/{$device->id}/recoveries")
            ->assertOk()
            ->assertJsonPath('data.0.requested_events.0.error', 'event_too_old');
        $this->postJson("/api/devices/{$device->id}/recoveries/{$recovery->id}/approve", [
            'reason' => 'Attendance was manually checked against the signed duty register.',
        ])->assertOk()->assertJsonPath('recovery.status', 'approved');

        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan/recover', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('acknowledged_event_ids.0', $blocked['event_id'])
            ->assertJsonPath('next_sequence', 1);
        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan/recover', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('kiosk_recovery_requests', [
            'request_uuid' => $requestId,
            'status' => 'consumed',
            'reviewed_by' => $manager->id,
        ]);
        $this->assertDatabaseHas('auth_events', [
            'user_id' => $manager->id,
            'event' => 'device_recovery_approved',
        ]);
    }

    public function test_it_can_recover_a_stale_sequence_conflict_after_manual_review(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        [$device, $token, $secret] = $this->issueDevice();
        $device->forceFill(['created_at' => now()->subHours(4)])->save();
        $accepted = $this->signedEvent($secret, 1, '2026-08-12T08:00:00.000Z', 'UNKNOWN/REMOTE');
        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan', $accepted)
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('status', 'failed');

        $blocked = $this->signedEvent($secret, 1, '2026-08-12T08:01:00.000Z', 'UNKNOWN/LOCAL');
        $rejection = $this->withHeader('X-Device-Token', $token)->postJson('/api/scan', $blocked)
            ->assertUnprocessable()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('error', 'sequence_conflict')
            ->json();

        $payload = ['blocked_events' => [[
            'event_id' => $blocked['event_id'],
            'sequence' => $blocked['sequence'],
            'code' => $blocked['code'],
            'occurred_at' => $blocked['occurred_at'],
            'signature' => $blocked['signature'],
            'error' => $rejection['error'],
            'message' => $rejection['message'],
        ]]];
        $requestId = $this->withHeader('X-Device-Token', $token)->postJson('/api/scan/recover', $payload)
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending')
            ->json('request_id');

        $manager = User::query()->create([
            'username' => 'conflict.reviewer',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        Sanctum::actingAs($manager, $manager->permissions());
        $recovery = $device->recoveryRequests()->where('request_uuid', $requestId)->sole();
        $this->postJson("/api/devices/{$device->id}/recoveries/{$recovery->id}/approve", [
            'reason' => 'The duplicate terminal event was checked and no manual attendance is required.',
        ])->assertOk();

        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan/recover', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('next_sequence', 2);
    }

    public function test_recovery_requires_the_original_device_signature_and_an_approval_is_immutable(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        [$device, $token, $secret] = $this->issueDevice();
        $blocked = $this->signedEvent($secret, 1, '2026-08-01T08:00:00.000Z', 'HGL/LA/SEC/404');
        $rejection = $this->withHeader('X-Device-Token', $token)->postJson('/api/scan', $blocked)
            ->assertUnprocessable()
            ->json();
        $payload = ['blocked_events' => [[
            'event_id' => $blocked['event_id'],
            'sequence' => $blocked['sequence'],
            'code' => $blocked['code'],
            'occurred_at' => $blocked['occurred_at'],
            'signature' => $blocked['signature'],
            'error' => $rejection['error'],
            'message' => $rejection['message'],
        ]]];
        $tampered = $payload;
        $tampered['blocked_events'][0]['signature'] = str_repeat('0', 64);
        $this->withHeader('X-Device-Token', $token)->postJson('/api/scan/recover', $tampered)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('blocked_events');

        $requestId = $this->withHeader('X-Device-Token', $token)->postJson('/api/scan/recover', $payload)
            ->assertStatus(202)
            ->json('request_id');
        $manager = User::query()->create([
            'username' => 'immutable.reviewer',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        Sanctum::actingAs($manager, $manager->permissions());
        $recovery = $device->recoveryRequests()->where('request_uuid', $requestId)->sole();
        $this->postJson("/api/devices/{$device->id}/recoveries/{$recovery->id}/approve", [
            'reason' => 'The duty register was checked before this event was cleared.',
        ])->assertOk();
        $this->postJson("/api/devices/{$device->id}/recoveries/{$recovery->id}/approve", [
            'reason' => 'A second reviewer must not overwrite the first approval.',
        ])->assertStatus(409);
    }

    public function test_it_manager_cannot_administer_a_super_administrator(): void
    {
        $manager = User::query()->create([
            'username' => 'ordinary.it',
            'password' => 'StrongPassword!234',
            'role' => 'it_manager',
            'is_active' => true,
        ]);
        $super = User::query()->create([
            'username' => 'protected.super',
            'password' => 'ProtectedPassword!234',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $originalPassword = $super->password;
        $super->createToken('existing-session', ['*']);
        Sanctum::actingAs($manager, $manager->permissions());

        $this->putJson("/api/users/{$super->id}", ['is_active' => false])->assertForbidden();
        $this->postJson("/api/users/{$super->id}/reset-password")->assertForbidden();
        $this->postJson("/api/users/{$super->id}/revoke-sessions")->assertForbidden();

        $super->refresh();
        $this->assertTrue($super->is_active);
        $this->assertSame('super_admin', $super->role);
        $this->assertSame($originalPassword, $super->password);
        $this->assertSame(1, $super->tokens()->count());
    }

    /** @return array{KioskDevice, string, string} */
    private function issueDevice(): array
    {
        $device = KioskDevice::query()->create([
            'identifier' => (string) Str::uuid(),
            'name' => 'Test kiosk',
            'type' => 'kiosk',
            'terminal_role' => ReceptionTerminalService::ROLE,
            'token_hash' => str_repeat('0', 64),
            'token_last_four' => '----',
            'abilities' => ['scan'],
            'allowed_ips' => '127.0.0.1',
            'is_active' => true,
        ]);
        $issued = app(DeviceTokenService::class)->issue($device);
        [, $secret] = explode('.', $issued['token'], 2);

        return [$issued['device'], $issued['token'], $secret];
    }

    /** @return array{event_id: string, code: string, occurred_at: string, sequence: int, signature: string} */
    private function signedEvent(string $secret, int $sequence, string $occurredAt, string $code, ?string $eventId = null): array
    {
        $eventId ??= (string) Str::uuid();
        $signature = hash_hmac('sha256', "{$eventId}\n{$code}\n{$occurredAt}\n{$sequence}", $secret);

        return [
            'event_id' => $eventId,
            'code' => $code,
            'occurred_at' => $occurredAt,
            'sequence' => $sequence,
            'signature' => $signature,
        ];
    }
}
