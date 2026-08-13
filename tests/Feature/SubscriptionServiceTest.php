<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        config()->set('services.paystack.secret_key', 'sk_test_review');
        config()->set('subscription.currency', 'USD');
        config()->set('subscription.plans.monthly', ['amount' => 2500, 'label' => 'Monthly', 'months' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_trial_warning_is_visible_before_expiry(): void
    {
        config()->set('subscription.trial_end', '2027-09-01');
        config()->set('subscription.warning_days', 30);
        Carbon::setTestNow('2027-08-15 12:00:00');

        $service = app(SubscriptionService::class);

        $this->assertTrue($service->isSubscriptionActive());
        $this->assertTrue($service->shouldShowExpiryWarning());
    }

    public function test_expired_paid_setting_does_not_unlock_the_application(): void
    {
        config()->set('subscription.trial_end', '2026-01-01');
        Carbon::setTestNow('2026-08-12 12:00:00');
        Setting::setValue('subscription_status', 'paid');
        Setting::setValue('subscription_expiry', '2026-08-11T12:00:00+00:00');

        $service = app(SubscriptionService::class);

        $this->assertFalse($service->isPaid());
        $this->assertFalse($service->isSubscriptionActive());
    }

    public function test_paystack_initialization_and_verified_fulfilment_are_idempotent(): void
    {
        Carbon::setTestNow('2026-08-12 12:00:00');
        $user = User::query()->create([
            'username' => 'billing.manager',
            'password' => 'ValidPassword!123',
            'role' => 'it_manager',
        ]);

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/transaction/initialize')) {
                return Http::response([
                    'status' => true,
                    'data' => [
                        'authorization_url' => 'https://checkout.paystack.com/access',
                        'access_code' => 'access',
                        'reference' => $request['reference'],
                    ],
                ]);
            }

            $reference = basename($request->url());

            return Http::response([
                'status' => true,
                'data' => [
                    'id' => 123456789,
                    'status' => 'success',
                    'reference' => $reference,
                    'amount' => 2500,
                    'currency' => 'USD',
                    'metadata' => [
                        'plan' => 'monthly',
                        'payment_id' => SubscriptionPayment::query()->where('reference', $reference)->value('id'),
                    ],
                    'paid_at' => '2026-08-12T12:01:00Z',
                    'gateway_response' => 'Successful',
                ],
            ]);
        });

        $service = app(SubscriptionService::class);
        $initialized = $service->initializePayment($user, 'monthly', 'billing@example.com');
        $first = $service->verifyAndActivate($initialized['reference']);
        $expiryAfterFirst = Setting::getValue('subscription_expiry');
        $second = $service->verifyAndActivate($initialized['reference']);

        $this->assertSame('successful', $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($expiryAfterFirst, Setting::getValue('subscription_expiry'));
        $this->assertTrue($service->isPaid());
        $this->assertDatabaseCount('subscription_payments', 1);
    }

    public function test_mismatched_amount_never_activates_a_licence(): void
    {
        config()->set('subscription.trial_end', '2026-01-01');
        $payment = SubscriptionPayment::query()->create([
            'reference' => 'HGATT-TEST123',
            'plan' => 'monthly',
            'amount' => 2500,
            'currency' => 'USD',
            'email' => 'billing@example.com',
            'status' => 'pending',
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            app(SubscriptionService::class)->fulfilWebhook([
                'id' => 44,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 1,
                'currency' => 'USD',
                'metadata' => ['plan' => 'monthly', 'payment_id' => $payment->id],
            ]);
        } finally {
            $this->assertSame('unpaid', Setting::getValue('subscription_status', 'unpaid'));
            $this->assertSame('failed', $payment->fresh()->status);
        }
    }

    public function test_missing_gateway_transaction_id_never_activates_a_licence(): void
    {
        $payment = SubscriptionPayment::query()->create([
            'reference' => 'HGATT-NO-TRANSACTION',
            'plan' => 'monthly',
            'amount' => 2500,
            'currency' => 'USD',
            'email' => 'billing@example.com',
            'status' => 'pending',
        ]);

        try {
            app(SubscriptionService::class)->fulfilWebhook([
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 2500,
                'currency' => 'USD',
                'metadata' => ['plan' => 'monthly', 'payment_id' => $payment->id],
            ]);
            $this->fail('A transaction without a gateway identity must not be fulfilled.');
        } catch (\RuntimeException) {
            $this->assertSame('failed', $payment->fresh()->status);
            $this->assertNull($payment->fresh()->fulfilled_at);
            $this->assertSame('unpaid', Setting::getValue('subscription_status', 'unpaid'));
        }
    }

    public function test_non_terminal_verification_remains_pending_for_reconciliation(): void
    {
        $payment = SubscriptionPayment::query()->create([
            'reference' => 'HGATT-PENDING1',
            'plan' => 'monthly',
            'amount' => 2500,
            'currency' => 'USD',
            'email' => 'billing@example.com',
            'status' => 'pending',
        ]);

        try {
            app(SubscriptionService::class)->fulfilWebhook([
                'status' => 'ongoing',
                'reference' => $payment->reference,
                'gateway_response' => 'Processing',
            ]);
            $this->fail('An incomplete transaction must not be reported as fulfilled.');
        } catch (\RuntimeException) {
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertNull($payment->fresh()->fulfilled_at);
        }
    }

    public function test_expired_subscription_cap_is_atomic_and_never_blocks_clock_outs(): void
    {
        config()->set('subscription.trial_end', '2026-01-01');
        config()->set('subscription.daily_scan_cap', 2);
        Carbon::setTestNow('2026-08-12 12:00:00');
        $service = app(SubscriptionService::class);

        $this->assertTrue($service->consumeClockInAllowance(1));
        $service->recordScan(1); // A clock-out may consume the final counted scan.
        $this->assertFalse($service->consumeClockInAllowance(2));
        $service->recordScan(1); // Closing an open session remains safe past the cap.

        $this->assertSame(0, $service->getDailyScanCapRemaining());
        $this->assertDatabaseHas('subscription_daily_usages', [
            'usage_date' => '2026-08-12',
            'scan_count' => 3,
        ]);
    }

    public function test_webhook_signature_uses_the_raw_request_body(): void
    {
        $body = '{"event":"charge.success"}';
        $signature = hash_hmac('sha512', $body, 'sk_test_review');

        $this->assertTrue(app(SubscriptionService::class)->validWebhookSignature($body, $signature));
        $this->assertFalse(app(SubscriptionService::class)->validWebhookSignature($body.' ', $signature));
    }
}
