<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $subscription = app(\App\Services\SubscriptionService::class);

        if (! $subscription->isSubscriptionActive()) {
            return response()->json([
                'ok' => false,
                'error' => 'subscription_required',
                'message' => 'This feature requires a paid subscription. Upgrade to continue.',
            ], 403);
        }

        return $next($request);
    }
}