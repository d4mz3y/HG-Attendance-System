<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        // HR and IT accounts are reset by an authorized administrator in the
        // Portal users screen. Only a super administrator has a self-service
        // password screen, so a legacy force-change flag must never lock an
        // HR account out of its operational work.
        if ($request->user()?->must_change_password && $request->user()?->canChangeOwnPassword()) {
            return response()->json([
                'message' => 'You must change your temporary password before continuing.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
