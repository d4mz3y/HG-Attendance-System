<?php

namespace Tests\Feature;

use App\Models\Setting;
use Carbon\Carbon;
use Tests\TestCase;

class SettingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_set_value_atomically_updates_an_existing_setting_and_preserves_nullable_values(): void
    {
        $existing = Setting::query()->where('key', 'report_email')->sole();
        $createdAt = $existing->created_at->toISOString();
        $updatedAt = $existing->updated_at->copy()->addMinute();

        Carbon::setTestNow($updatedAt);

        try {
            Setting::setValue('report_email', null);
            Setting::setValue('report_email', null);

            $updated = Setting::query()->where('key', 'report_email')->sole();

            $this->assertDatabaseCount('settings', count(config('hg.settings_defaults')));
            $this->assertNull($updated->value);
            $this->assertSame($createdAt, $updated->created_at->toISOString());
            $this->assertTrue($updated->updated_at->equalTo($updatedAt));
        } finally {
            Carbon::setTestNow();
        }
    }
}
