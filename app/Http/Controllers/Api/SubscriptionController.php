<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionService;

class SubscriptionController extends Controller
{
    public function status(SubscriptionService $subscription)
    {
        return response()->json([
            'active' => $subscription->isSubscriptionActive(),
            'trial_expiry' => $subscription->getTrialExpiryDate(),
            'show_warning' => $subscription->shouldShowExpiryWarning(),
            'pricing' => $subscription->getPricing(),
        ]);
    }
}