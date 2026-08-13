<?php

namespace Tests\Feature;

use App\Mail\ScheduledReport;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ScheduledReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_schedule_is_registered(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('reports:send', Artisan::output());
    }

    public function test_report_command_sends_a_renderable_message_for_a_fixed_period(): void
    {
        Mail::fake();
        Setting::setValue('report_email', 'hr@example.test');

        $this->artisan('reports:send', [
            '--frequency' => 'daily',
            '--to' => '2026-08-11',
        ])->assertSuccessful();

        Mail::assertSent(ScheduledReport::class, function (ScheduledReport $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'daily report')
                && str_contains($html, '2026-08-11 through 2026-08-11');
        });
    }

    public function test_automatic_report_does_not_resend_the_same_completed_period(): void
    {
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 8, 12)->setTime(6, 0));
        Setting::setValue('report_email', 'hr@example.test');
        Setting::setValue('report_frequency', 'daily');

        $this->artisan('reports:send')->assertSuccessful();
        $this->artisan('reports:send')->assertSuccessful();

        Mail::assertSent(ScheduledReport::class, 1);
        $this->assertSame(
            'daily:2026-08-11:2026-08-11',
            Setting::getValue('scheduled_report_last_period')
        );
    }
}
