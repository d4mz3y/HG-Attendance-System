<?php

namespace App\Http\Controllers\Api;

use App\Auth\Permission;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthEventService;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuthEventService $events) {}

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => PasswordPolicy::login(),
            'remember' => ['nullable', 'boolean'],
        ]);

        $user = User::query()->where('username', $data['username'])->first();

        $passwordMatches = Hash::check(
            $data['password'],
            $user?->password ?? '$2y$12$cYLr3KUj4FLA62zqX.JpSuOFqbZ.3soBvvMvcnzomlvQBitGU8Ddq'
        );

        if (! $user || ! $user->is_active || ! $passwordMatches) {
            $this->events->record($request, 'login_failed', $user, $data['username']);
            throw ValidationException::withMessages([
                'username' => ['Invalid username or password.'],
            ]);
        }

        $expiresAt = $request->boolean('remember')
            ? now()->addDays(max(1, (int) config('hg.auth_remember_expiration_days', 30)))
            : now()->addMinutes(max(15, (int) config('hg.auth_token_expiration_minutes', 480)));
        $token = $user->createToken(
            'portal-'.substr(hash('sha256', (string) $request->userAgent()), 0, 12),
            $user->permissions(),
            $expiresAt
        )->plainTextToken;
        $user->forceFill(['last_login_at' => now()])->save();
        $this->events->record($request, 'login_success', $user);

        return response()->json([
            'token' => $token,
            'token_expires_at' => $expiresAt->toIso8601String(),
            'user' => $this->userPayload($user),
        ])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request)
    {
        $this->events->record($request, 'logout', $request->user());
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function user(Request $request)
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
            'token_expires_at' => $request->user()->currentAccessToken()?->expires_at?->toIso8601String(),
        ]);
    }

    public function changePassword(Request $request)
    {
        abort_unless(
            $request->user()?->canChangeOwnPassword()
                && $request->user()?->tokenCan(Permission::PASSWORD_CHANGE_SELF),
            403,
            'Only the super administrator can change their own password.'
        );

        $data = $request->validate([
            'current_password' => PasswordPolicy::login(),
            'password' => [
                ...PasswordPolicy::strong(confirmed: true),
                'different:current_password',
            ],
        ]);

        $currentTokenId = $request->user()->currentAccessToken()?->id;
        try {
            $user = DB::transaction(function () use ($request, $data, $currentTokenId): User {
                $locked = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                if (! $locked->is_active || ! Hash::check($data['current_password'], $locked->password)) {
                    throw ValidationException::withMessages([
                        'current_password' => ['The current password is incorrect.'],
                    ]);
                }

                $locked->forceFill([
                    'password' => $data['password'],
                    'must_change_password' => false,
                    'password_changed_at' => now(),
                ])->save();

                $locked->tokens()
                    ->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))
                    ->delete();

                return $locked;
            }, 3);
        } catch (ValidationException $exception) {
            $this->events->record($request, 'password_change_failed', $request->user());

            throw $exception;
        }
        // Keep the authenticated instance used by this request (and by
        // long-lived test/worker guards) aligned with the locked row.
        $request->user()->setRawAttributes($user->getAttributes(), true);
        $this->events->record($request, 'password_changed', $user);

        return response()->json(['user' => $this->userPayload($user->fresh())]);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
            'permissions' => $user->permissions(),
            // Only the super administrator has a self-service password
            // workflow. Legacy flags on HR/IT accounts must not send the
            // frontend into an impossible password-change screen.
            'must_change_password' => $user->canChangeOwnPassword() && $user->must_change_password,
            'dark_mode_default' => Setting::getValue('dark_mode_default', '0') === '1',
        ];
    }
}
