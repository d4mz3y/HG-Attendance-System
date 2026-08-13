<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    public function status(Request $request, SubscriptionService $subscription): JsonResponse
    {
        return response()->json([
            'active' => $subscription->isSubscriptionActive(),
            'paid' => $subscription->isPaid(),
            'trial_start' => $subscription->trialStart(),
            'trial_expiry' => $subscription->getTrialExpiryDate(),
            'subscription_expiry' => $subscription->subscriptionExpiry(),
            'show_warning' => $subscription->shouldShowExpiryWarning(),
            'pricing' => $subscription->getPricing(),
            'can_manage_billing' => $request->user()?->canManageSystem() ?? false,
            'billing_email' => $request->user()?->canManageSystem()
                ? config('subscription.billing_email')
                : null,
        ]);
    }

    public function initialize(Request $request, SubscriptionService $subscription): JsonResponse
    {
        abort_unless($request->user()->canManageSystem(), 403, 'Only IT managers can manage billing.');

        $data = $request->validate([
            'plan' => ['required', 'string', 'in:monthly,yearly'],
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        return response()->json(
            $subscription->initializePayment($request->user(), $data['plan'], $data['email'])
        );
    }

    public function verify(Request $request, SubscriptionService $subscription): JsonResponse
    {
        abort_unless($request->user()->canManageSystem(), 403, 'Only IT managers can manage billing.');
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9.=-]+$/'],
        ]);

        $payment = $subscription->verifyAndActivate($data['reference']);

        return response()->json([
            'ok' => true,
            'reference' => $payment->reference,
            'subscription_expiry' => $subscription->subscriptionExpiry(),
        ]);
    }

    public function callback(Request $request, SubscriptionService $subscription): RedirectResponse
    {
        $reference = (string) $request->query('reference', '');

        try {
            $subscription->verifyAndActivate($reference);

            return redirect('/dashboard?payment=success');
        } catch (\Throwable) {
            return redirect('/dashboard?payment=failed');
        }
    }

    public function webhook(Request $request, SubscriptionService $subscription): JsonResponse
    {
        $rawBody = $request->getContent();
        if (! $subscription->validWebhookSignature($rawBody, $request->header('X-Paystack-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event)) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        if (($event['event'] ?? null) === 'charge.success' && is_array($event['data'] ?? null)) {
            try {
                $subscription->fulfilWebhook($event['data']);
            } catch (\Throwable $exception) {
                // Unknown or mismatched payments are acknowledged without granting a licence.
                Log::notice('Rejected Paystack webhook fulfilment.', [
                    'exception' => $exception::class,
                    'reference' => (string) ($event['data']['reference'] ?? ''),
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
