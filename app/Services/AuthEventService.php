<?php

namespace App\Services;

use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthEventService
{
    /** @param array<string, mixed>|null $metadata */
    public function record(Request $request, string $event, ?User $user = null, ?string $username = null, ?array $metadata = null): AuthEvent
    {
        return AuthEvent::query()->create([
            'user_id' => $user?->id,
            'username' => $username ?? $user?->username,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'metadata' => $metadata,
        ]);
    }
}
