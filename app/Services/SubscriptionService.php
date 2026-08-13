<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SubscriptionDailyUsage;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SubscriptionService
{
    public function isTrialExpired(?CarbonInterface $at = null): bool
    {
        return ($at ?? now())->greaterThan(Carbon::parse($this->trialEnd())->endOfDay());
    }

    public function isPaid(?CarbonInterface $at = null): bool
    {
        $expiry = Setting::getValue('subscription_expiry');

        if (Setting::getValue('subscription_status', 'unpaid') !== 'paid' || ! $expiry) {
            return false;
        }

        return Carbon::parse($expiry)->greaterThan($at ?? now());
    }

    public function isSubscriptionActive(?CarbonInterface $at = null): bool
    {
        return ! $this->isTrialExpired($at) || $this->isPaid($at);
    }

    public function getDailyScanCapRemaining(?CarbonInterface $at = null): int
    {
        if ($this->isSubscriptionActive($at)) {
            return PHP_INT_MAX;
        }

        $date = $this->usageDate($at);
        $used = (int) SubscriptionDailyUsage::query()
            ->whereDate('usage_date', $date)
            ->value('scan_count');

        return max(0, $this->dailyScanCap() - $used);
    }

    /**
     * Backward-compatible alias for the scan service.
     */
    public function getScanCapRemaining(int $staffId, ?CarbonInterface $at = null): int
    {
        return $this->getDailyScanCapRemaining($at);
    }

    public function recordScan(int $staffId, ?CarbonInterface $at = null): void
    {
        if ($this->isSubscriptionActive($at)) {
            return;
        }

        $date = $this->usageDate($at);

        DB::transaction(function () use ($date): void {
            SubscriptionDailyUsage::query()->insertOrIgnore([
                'usage_date' => $date,
                'scan_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            SubscriptionDailyUsage::query()
                ->where('usage_date', $date)
                ->lockForUpdate()
                ->increment('scan_count');
        });
    }

    /**
     * Atomically reserves one of the expired-licence scan slots. Clock-outs
     * intentionally do not use this gate so a member of staff is never left
     * stranded in an open attendance session.
     */
    public function consumeClockInAllowance(int $staffId, ?CarbonInterface $at = null): bool
    {
        if ($this->isSubscriptionActive($at)) {
            return true;
        }

        $date = $this->usageDate($at);

        return DB::transaction(function () use ($date): bool {
            SubscriptionDailyUsage::query()->insertOrIgnore([
                'usage_date' => $date,
                'scan_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $usage = SubscriptionDailyUsage::query()
                ->where('usage_date', $date)
                ->lockForUpdate()
                ->firstOrFail();

            if ($usage->scan_count >= $this->dailyScanCap()) {
                return false;
            }

            $usage->increment('scan_count');

            return true;
        });
    }

    public function trialStart(): string
    {
        return (string) config('subscription.trial_start');
    }

    public function trialEnd(): string
    {
        return (string) config('subscription.trial_end');
    }

    public function getTrialExpiryDate(): string
    {
        return $this->trialEnd();
    }

    public function subscriptionExpiry(): ?string
    {
        return Setting::getValue('subscription_expiry');
    }

    public function shouldShowExpiryWarning(?CarbonInterface $at = null): bool
    {
        if ($this->isPaid($at)) {
            $expiry = $this->subscriptionExpiry();

            return $expiry !== null
                && ($at ?? now())->greaterThanOrEqualTo(
                    Carbon::parse($expiry)->subDays($this->warningDays())
                );
        }

        $now = $at ?? now();

        return $now->greaterThanOrEqualTo(
            Carbon::parse($this->trialEnd())->subDays($this->warningDays())->startOfDay()
        );
    }

    /**
     * @return array<int, array{plan:string,amount:int,currency:string,label:string,interval:string}>
     */
    public function getPricing(): array
    {
        return collect($this->plans())
            ->map(fn (array $plan, string $key) => [
                'plan' => $key,
                'amount' => (int) $plan['amount'],
                'currency' => $this->currency(),
                'label' => (string) $plan['label'],
                'interval' => $key,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{authorization_url:string,access_code:string,reference:string}
     */
    public function initializePayment(User $user, string $planKey, string $email): array
    {
        $plan = $this->plan($planKey);
        $this->assertConfigured($plan);

        $reference = 'HGATT-'.Str::upper((string) Str::ulid());
        $payment = SubscriptionPayment::query()->create([
            'initiated_by' => $user->id,
            'reference' => $reference,
            'plan' => $planKey,
            'amount' => (int) $plan['amount'],
            'currency' => $this->currency(),
            'email' => mb_strtolower(trim($email)),
            'status' => 'initialized',
        ]);

        try {
            $response = $this->paystack()
                ->post('/transaction/initialize', [
                    'email' => $payment->email,
                    'amount' => (string) $payment->amount,
                    'currency' => $payment->currency,
                    'reference' => $payment->reference,
                    'callback_url' => $this->callbackUrl(),
                    'metadata' => json_encode([
                        'payment_id' => $payment->id,
                        'plan' => $payment->plan,
                    ], JSON_THROW_ON_ERROR),
                ])
                ->throw()
                ->json();

            if (($response['status'] ?? false) !== true
                || empty($response['data']['authorization_url'])
                || ($response['data']['reference'] ?? null) !== $payment->reference) {
                throw new RuntimeException('Paystack returned an invalid initialization response.');
            }

            $payment->update([
                'status' => 'pending',
                'authorization_url' => $response['data']['authorization_url'],
            ]);

            return [
                'authorization_url' => $response['data']['authorization_url'],
                'access_code' => (string) ($response['data']['access_code'] ?? ''),
                'reference' => $payment->reference,
            ];
        } catch (\Throwable $exception) {
            $payment->update([
                'status' => 'failed',
                'gateway_response' => Str::limit($exception->getMessage(), 255, ''),
            ]);

            throw new RuntimeException('Unable to initialize payment with Paystack.', previous: $exception);
        }
    }

    public function verifyAndActivate(string $reference): SubscriptionPayment
    {
        $this->assertReference($reference);

        $response = $this->paystack()
            ->get('/transaction/verify/'.rawurlencode($reference))
            ->throw()
            ->json();

        if (($response['status'] ?? false) !== true || ! isset($response['data'])) {
            throw new RuntimeException('Paystack could not verify this transaction.');
        }

        return $this->fulfil($reference, $response['data']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function fulfilWebhook(array $data): SubscriptionPayment
    {
        $reference = (string) ($data['reference'] ?? '');
        $this->assertReference($reference);

        return $this->fulfil($reference, $data);
    }

    public function validWebhookSignature(string $rawBody, ?string $provided): bool
    {
        $secret = (string) config('services.paystack.secret_key', '');

        if ($secret === '' || ! is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), $provided);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fulfil(string $reference, array $data): SubscriptionPayment
    {
        $this->ensureSubscriptionStateRows();

        /** @var array{payment: SubscriptionPayment, error: ?string} $result */
        $result = DB::transaction(function () use ($reference, $data): array {
            $payment = SubscriptionPayment::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->fulfilled_at !== null) {
                return ['payment' => $payment, 'error' => null];
            }

            $metadata = $data['metadata'] ?? [];
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true) ?: [];
            }

            $gatewayStatus = strtolower((string) ($data['status'] ?? ''));
            if ($gatewayStatus !== 'success') {
                $isTerminal = in_array($gatewayStatus, ['failed', 'abandoned', 'reversed'], true);
                $payment->update([
                    'status' => $isTerminal ? 'failed' : 'pending',
                    'gateway_response' => Str::limit(
                        (string) ($data['gateway_response'] ?? 'Payment has not completed.'),
                        255,
                        ''
                    ),
                ]);

                return [
                    'payment' => $payment->fresh(),
                    'error' => $isTerminal ? 'Payment was not successful.' : 'Payment is still pending.',
                ];
            }

            $valid = ($data['reference'] ?? null) === $payment->reference
                && (int) ($data['amount'] ?? -1) === $payment->amount
                && strtoupper((string) ($data['currency'] ?? '')) === $payment->currency
                && ($metadata['plan'] ?? null) === $payment->plan
                && (int) ($metadata['payment_id'] ?? 0) === $payment->id
                && is_scalar($data['id'] ?? null)
                && trim((string) $data['id']) !== '';

            if (! $valid) {
                $payment->update([
                    'status' => 'failed',
                    'gateway_response' => 'Verification data did not match the initialized payment.',
                ]);

                return [
                    'payment' => $payment->fresh(),
                    'error' => 'Payment verification failed.',
                ];
            }

            $transactionId = (string) $data['id'];
            $paidAt = isset($data['paid_at'])
                ? Carbon::parse($data['paid_at'])->setTimezone(config('app.timezone'))
                : now();

            $state = Setting::query()
                ->whereIn('key', $this->subscriptionStateKeys())
                ->orderBy('key')
                ->lockForUpdate()
                ->get()
                ->keyBy('key');

            $payment->update([
                'paystack_transaction_id' => $transactionId,
                'status' => 'successful',
                'gateway_response' => Str::limit((string) ($data['gateway_response'] ?? 'Successful'), 255, ''),
                'paid_at' => $paidAt,
                'fulfilled_at' => now(),
            ]);

            $currentExpiry = $state->get('subscription_expiry')?->value;
            $startsAt = $currentExpiry && Carbon::parse($currentExpiry)->isFuture()
                ? Carbon::parse($currentExpiry)
                : now();
            $expiresAt = $startsAt->copy()->addMonthsNoOverflow((int) $this->plan($payment->plan)['months']);

            $values = [
                'subscription_status' => 'paid',
                'subscription_plan' => $payment->plan,
                'subscription_expiry' => $expiresAt->toIso8601String(),
                'subscription_last_reference' => $payment->reference,
            ];
            foreach ($values as $key => $value) {
                $setting = $state->get($key);
                $setting->value = $value;
                $setting->save();
            }

            return ['payment' => $payment->fresh(), 'error' => null];
        });

        if ($result['error'] !== null) {
            throw new RuntimeException($result['error']);
        }

        return $result['payment'];
    }

    private function ensureSubscriptionStateRows(): void
    {
        $now = now();
        $defaults = [
            'subscription_status' => 'unpaid',
            'subscription_plan' => '',
            'subscription_expiry' => '',
            'subscription_last_reference' => '',
        ];

        Setting::query()->insertOrIgnore(
            collect($defaults)->map(fn (string $value, string $key): array => [
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all()
        );
    }

    /** @return array<int, string> */
    private function subscriptionStateKeys(): array
    {
        return [
            'subscription_expiry',
            'subscription_last_reference',
            'subscription_plan',
            'subscription_status',
        ];
    }

    private function paystack(): PendingRequest
    {
        return Http::baseUrl('https://api.paystack.co')
            ->withToken((string) config('services.paystack.secret_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->retry(2, 250, throw: false);
    }

    /** @return array<string, array{amount:int,label:string,months:int}> */
    private function plans(): array
    {
        return (array) config('subscription.plans', []);
    }

    /** @return array{amount:int,label:string,months:int} */
    private function plan(string $key): array
    {
        $plan = $this->plans()[$key] ?? null;
        if (! is_array($plan)) {
            throw new RuntimeException('Unknown subscription plan.');
        }

        return $plan;
    }

    private function assertConfigured(array $plan): void
    {
        if ((string) config('services.paystack.secret_key', '') === '') {
            throw new RuntimeException('Paystack is not configured.');
        }

        if ((int) ($plan['amount'] ?? 0) <= 0 || ! preg_match('/^[A-Z]{3}$/', $this->currency())) {
            throw new RuntimeException('Subscription pricing is not configured correctly.');
        }
    }

    private function assertReference(string $reference): void
    {
        if (! preg_match('/^[A-Za-z0-9.=-]{6,100}$/', $reference)) {
            throw new RuntimeException('Invalid payment reference.');
        }
    }

    private function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/subscription/callback';
    }

    private function currency(): string
    {
        return strtoupper((string) config('subscription.currency', 'USD'));
    }

    private function dailyScanCap(): int
    {
        return max(0, (int) config('subscription.daily_scan_cap', 20));
    }

    private function warningDays(): int
    {
        return max(1, (int) config('subscription.warning_days', 30));
    }

    private function usageDate(?CarbonInterface $at): string
    {
        return $at
            ? Carbon::parse($at->toIso8601String())->setTimezone(config('app.timezone'))->toDateString()
            : now()->toDateString();
    }
}
