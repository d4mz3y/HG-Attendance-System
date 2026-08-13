<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_active) {
            $request->user()?->currentAccessToken()?->delete();

            return response()->json(['message' => 'This account is disabled.'], 401);
        }

        return $next($request);
    }
}
