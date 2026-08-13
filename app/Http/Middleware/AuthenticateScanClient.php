<?php

namespace App\Http\Middleware;

use App\Services\DeviceTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateScanClient
{
    public function __construct(private readonly DeviceTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tokens->globalIpIsAllowed($request->ip())) {
            return response()->json(['message' => 'Scanning is not allowed from this network.'], 403);
        }

        $authenticated = $this->tokens->authenticate($request, 'kiosk');
        if ($authenticated === null) {
            return response()->json(['message' => 'Unauthenticated reception scanner.'], 401);
        }

        if (! $authenticated['device']->isReceptionTerminal()) {
            return response()->json(['message' => 'This scan endpoint is reserved for the reception scanner.'], 403);
        }

        $request->attributes->set('kiosk_device', $authenticated['device']);
        $request->attributes->set('device_token_secret', $authenticated['secret']);

        return $next($request);
    }
}
