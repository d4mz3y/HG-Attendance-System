<?php

namespace Tests\Feature;

use App\Models\PublicHoliday;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_recurring_holiday_edits_do_not_rewrite_frozen_occurrences(): void
    {
        $holiday = PublicHoliday::query()->create([
            'date' => '2025-01-01',
            'name' => 'Original New Year',
            'is_recurring' => true,
        ]);
        Setting::setValue('holiday_history_frozen_through', '2026-12-31');
        Carbon::setTestNow('2027-01-02 12:00:00');

        PublicHoliday::freezeHistoryThrough(Carbon::parse('2027-01-01'));
        $holiday->update(['name' => 'Renamed New Year']);

        $past = PublicHoliday::occurrencesBetween(Carbon::parse('2027-01-01'), Carbon::parse('2027-01-01'))->first();
        $future = PublicHoliday::occurrencesBetween(Carbon::parse('2028-01-01'), Carbon::parse('2028-01-01'))->first();

        $this->assertSame('Original New Year', $past->name);
        $this->assertTrue((bool) $past->historical);
        $this->assertSame('Renamed New Year', $future->name);
    }

    public function test_recurring_holidays_never_project_before_their_source_year(): void
    {
        PublicHoliday::query()->create([
            'date' => '2028-03-10',
            'name' => 'Future Annual Holiday',
            'is_recurring' => true,
        ]);
        Setting::setValue('holiday_history_frozen_through', '2026-12-31');

        $this->assertTrue(PublicHoliday::occurrencesBetween(
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-12-31')
        )->isEmpty());
        $this->assertSame('Future Annual Holiday', PublicHoliday::occurrencesBetween(
            Carbon::parse('2028-01-01'),
            Carbon::parse('2028-12-31')
        )->first()->name);
    }

    public function test_freezing_an_earlier_range_never_regresses_the_history_cutoff(): void
    {
        PublicHoliday::query()->create([
            'date' => '2025-01-01',
            'name' => 'New Year',
            'is_recurring' => true,
        ]);

        PublicHoliday::freezeHistoryThrough(Carbon::parse('2026-12-31'));
        PublicHoliday::freezeHistoryThrough(Carbon::parse('2026-01-01'));

        $this->assertSame('2026-12-31', Setting::getValue('holiday_history_frozen_through'));
    }
}
