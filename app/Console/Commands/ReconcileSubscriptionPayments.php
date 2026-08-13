<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPayment;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileSubscriptionPayments extends Command
{
    protected $signature = 'subscriptions:reconcile {--limit=50}';

    protected $description = 'Verify pending Paystack payments when a webhook cannot reach the private server';

    public function handle(SubscriptionService $subscriptions): int
    {
        $limit = min(200, max(1, (int) $this->option('limit')));
        $payments = SubscriptionPayment::query()
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subDays(7))
            ->oldest()
            ->limit($limit)
            ->get();
        $activated = 0;

        foreach ($payments as $payment) {
            try {
                $verified = $subscriptions->verifyAndActivate($payment->reference);
                if ($verified->fulfilled_at) {
                    $activated++;
                }
            } catch (\Throwable $exception) {
                Log::notice('Pending Paystack payment is not yet verified.', [
                    'payment_id' => $payment->id,
                    'reference' => $payment->reference,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Checked {$payments->count()} payment(s); {$activated} active.");

        return self::SUCCESS;
    }
}
