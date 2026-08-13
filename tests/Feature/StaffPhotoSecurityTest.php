<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StaffPhotoSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_photo_requires_a_valid_temporary_signature_and_is_not_cacheable(): void
    {
        $staff = $this->staffWithPhoto();
        Storage::disk('local')->put($staff->photo_path, 'private-photo-bytes');

        $this->get(route('staff.photo', ['staffId' => $staff]))->assertForbidden();
        $this->get(route('staff.photo', ['staffId' => 999999]))->assertForbidden();

        $url = URL::temporarySignedRoute('staff.photo', now()->addMinute(), ['staffId' => $staff]);
        $response = $this->get($url)->assertOk();

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_legacy_public_photos_are_moved_and_public_copy_is_removed(): void
    {
        $staff = $this->staffWithPhoto();
        Storage::disk('public')->put($staff->photo_path, 'legacy-photo-bytes');

        $this->artisan('staff:secure-photos')->assertSuccessful();

        Storage::disk('local')->assertExists($staff->photo_path);
        Storage::disk('public')->assertMissing($staff->photo_path);
        $this->assertSame('legacy-photo-bytes', Storage::disk('local')->get($staff->photo_path));
    }

    private function staffWithPhoto(): Staff
    {
        return Staff::query()->create([
            'staff_id' => 'HGL/LA/OPS/001',
            'company' => 'Hogan Guards',
            'full_name' => 'Private Photo',
            'department' => 'Operations',
            'branch' => 'Lagos (HQ)',
            'photo_path' => 'staff-photos/private-photo.jpg',
            'employment_status' => 'Active',
            'employment_start_date' => '2026-01-01',
        ]);
    }
}
