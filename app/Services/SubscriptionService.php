<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class SubscriptionService
{
    public const TRIAL_START = '2026-09-01';
    public const TRIAL_END = '2027-09-01';
    public const SCAN_CAP_PER_DAY = 20;

    public function isTrialExpired(): bool
    {
        return Carbon::parse(self::TRIAL_END)->isPast();
    }

    public function isPaid(): bool
    {
        return \App\Models\Setting::getValue('subscription_status', 'unpaid') === 'paid';
    }

    public function isSubscriptionActive(): bool
    {
        if (! $this->isTrialExpired()) {
            return true;
        }

        return $this->isPaid();
    }

    public function getScanCapRemaining(int $staffId): int
    {
        if ($this->isSubscriptionActive()) {
            return PHP_INT_MAX;
        }

        $today = Carbon::today()->toDateString();
        $key = 'scan_cap_' . $today;

        $scansToday = Cache::get($key, 0);

        return max(0, self::SCAN_CAP_PER_DAY - $scansToday);
    }

    public function recordScan(int $staffId): void
    {
        if ($this->isSubscriptionActive()) {
            return;
        }

        $today = Carbon::today()->toDateString();
        $key = 'scan_cap_' . $today;

        Cache::increment($key);
        Cache::put($key, Cache::get($key, 1), Carbon::tomorrow()->startOfDay()->diffInSeconds(Carbon::today()->startOfDay()));
    }

    public function getTrialExpiryDate(): string
    {
        return self::TRIAL_END;
    }

    public function shouldShowExpiryWarning(): bool
    {
        if ($this->isPaid() || ! $this->isTrialExpired()) {
            return false;
        }

        $expiry = Carbon::parse(self::TRIAL_END);
        $oneMonthBefore = $expiry->copy()->subMonth();

        return Carbon::now()->gte($oneMonthBefore);
    }

    public function getPricing(): array
    {
        return [
            'monthly' => [
                'price' => 2500,
                'description' => '$25/month',
                'interval' => 'monthly',
            ],
            'yearly' => [
                'price' => 28000,
                'description' => '$280/year',
                'interval' => 'yearly',
            ],
        ];
    }

    public function isPaystackPaymentInProgress(): bool
    {
        return Cache::get('paystack_payment_in_progress', false);
    }

    public function confirmPayment(string $reference): bool
    {
        $verification = $this->verifyPaystackPayment($reference);

        if ($verification['status'] === 'success') {
            \App\Models\Setting::setValue('subscription_status', 'paid');
            \App\Models\Setting::setValue('subscription_plan', $verification['plan'] ?? 'monthly');
            \App\Models\Setting::setValue('subscription_expiry', Carbon::now()->addMonth()->toDateString());

            return true;
        }

        return false;
    }

    protected function verifyPaystackPayment(string $reference): array
    {
        $secretKey = config('services.paystack.secret_key');

        if (! $secretKey) {
            return ['status' => 'error', 'message' => 'Paystack not configured'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/{$reference}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['status' => 'error', 'message' => 'Paystack verification failed'];
        }

        $data = json_decode($response, true);

        if (($data['data']['status'] ?? null) === 'success') {
            return [
                'status' => 'success',
                'plan' => $data['data']['metadata']['plan'] ?? 'monthly',
                'amount' => $data['data']['amount'] ?? 0,
            ];
        }

        return ['status' => 'error', 'message' => 'Payment not confirmed'];
    }
}