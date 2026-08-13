<?php

namespace App\Http\Controllers\Api;

use App\Auth\Permission;
use App\Http\Controllers\Controller;
use App\Models\AuthEvent;
use App\Models\User;
use App\Services\AuthEventService;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private readonly AuthEventService $events) {}

    public function index(Request $request)
    {
        $users = User::query()
            ->select(['id', 'username', 'role', 'is_active', 'must_change_password', 'password_changed_at', 'last_login_at', 'created_at'])
            ->orderBy('username')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $roles = $this->assignableRoles($request->user());
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
            'role' => ['required', Rule::in($roles)],
            'password' => PasswordPolicy::strong(confirmed: true),
        ]);

        $user = DB::transaction(function () use ($request, $data): User {
            $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->assertActiveUserManager($actor);
            abort_unless(in_array($data['role'], $this->assignableRoles($actor), true), 403);

            return User::query()->create([
                'username' => $data['username'],
                'role' => $data['role'],
                'password' => $data['password'],
                'is_active' => true,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ]);
        }, 3);
        $this->events->record($request, 'user_created', $request->user(), metadata: [
            'target_user_id' => $user->id,
            'target_username' => $user->username,
            'role' => $user->role,
        ]);

        return response()->json([
            'user' => $user->only(['id', 'username', 'role', 'is_active', 'must_change_password']),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, User $user)
    {
        $this->assertCanManageTarget($request->user(), $user);
        $data = $request->validate([
            'username' => ['sometimes', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'role' => ['sometimes', Rule::in($this->assignableRoles($request->user()))],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($request->user()->is($user) && (array_key_exists('role', $data) || ($data['is_active'] ?? true) === false)) {
            throw ValidationException::withMessages(['user' => ['You cannot change your own role or disable your own account.']]);
        }

        [$user, $before] = DB::transaction(function () use ($request, $user, $data): array {
            User::query()
                ->where('is_active', true)
                ->whereIn('role', ['it_manager', 'super_admin'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $actor = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->assertActiveUserManager($actor);
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertCanManageTarget($actor, $locked);
            abort_if(
                array_key_exists('role', $data) && ! in_array($data['role'], $this->assignableRoles($actor), true),
                403
            );
            if ($actor->is($locked) && (array_key_exists('role', $data) || ($data['is_active'] ?? true) === false)) {
                throw ValidationException::withMessages(['user' => ['You cannot change your own role or disable your own account.']]);
            }

            $before = $locked->only(['username', 'role', 'is_active']);
            $this->ensureSystemManagerRemains($locked, $data);
            $locked->update($data);
            if (array_key_exists('role', $data) || ($data['is_active'] ?? true) === false) {
                $locked->tokens()->delete();
            }

            return [$locked, $before];
        }, 3);

        $this->events->record($request, 'user_updated', $request->user(), metadata: [
            'target_user_id' => $user->id,
            'before' => $before,
            'after' => $user->fresh()->only(['username', 'role', 'is_active']),
        ]);

        return response()->json(['user' => $user->fresh()]);
    }

    public function resetPassword(Request $request, User $user)
    {
        $this->assertCanManageTarget($request->user(), $user);
        abort_if(
            $request->user()->is($user),
            422,
            'Use the Change password page to change your own password.'
        );
        $data = $request->validate([
            'password' => PasswordPolicy::strong(confirmed: true),
        ]);

        $user = DB::transaction(function () use ($request, $user, $data): User {
            $lockedUsers = User::query()
                ->whereIn('id', [$request->user()->id, $user->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $actor = $lockedUsers->get($request->user()->id);
            $locked = $lockedUsers->get($user->id);
            abort_unless($actor instanceof User && $locked instanceof User, 404);
            $this->assertActiveUserManager($actor);
            $this->assertCanManageTarget($actor, $locked);
            $locked->forceFill([
                'password' => $data['password'],
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();
            $locked->tokens()->delete();

            return $locked;
        }, 3);

        $this->events->record($request, 'user_password_reset', $request->user(), metadata: [
            'target_user_id' => $user->id,
            'target_username' => $user->username,
        ]);

        return response()->json(['ok' => true])
            ->header('Cache-Control', 'no-store');
    }

    public function revokeSessions(Request $request, User $user)
    {
        $this->assertCanManageTarget($request->user(), $user);
        $user = DB::transaction(function () use ($request, $user): User {
            $lockedUsers = User::query()
                ->whereIn('id', [$request->user()->id, $user->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $actor = $lockedUsers->get($request->user()->id);
            $locked = $lockedUsers->get($user->id);
            abort_unless($actor instanceof User && $locked instanceof User, 404);
            $this->assertActiveUserManager($actor);
            $this->assertCanManageTarget($actor, $locked);
            $locked->tokens()->delete();

            return $locked;
        }, 3);
        $this->events->record($request, 'user_sessions_revoked', $request->user(), metadata: [
            'target_user_id' => $user->id,
            'target_username' => $user->username,
        ]);

        return response()->json(['ok' => true]);
    }

    public function authEvents(Request $request)
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'username' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $events = AuthEvent::query()
            ->with('user:id,username')
            ->when(! empty($filters['event']), fn ($query) => $query->where('event', $filters['event']))
            ->when(! empty($filters['username']), function ($query) use ($filters): void {
                $escaped = addcslashes($filters['username'], '%_\\');
                $query->where('username', 'like', "%{$escaped}%");
            })
            ->latest()
            ->paginate($filters['per_page'] ?? 50);

        return response()->json($events);
    }

    /** @return list<string> */
    private function assignableRoles(User $actor): array
    {
        return $actor->role === 'super_admin'
            ? Permission::roles()
            : ['hr_assistant', 'hr', 'it_manager'];
    }

    /** @param array<string, mixed> $changes */
    private function ensureSystemManagerRemains(User $user, array $changes): void
    {
        $currentlyManager = $user->is_active && in_array($user->role, ['it_manager', 'super_admin'], true);
        $willBeManager = ($changes['is_active'] ?? $user->is_active)
            && in_array($changes['role'] ?? $user->role, ['it_manager', 'super_admin'], true);

        if ($currentlyManager && ! $willBeManager) {
            $otherManagers = User::query()
                ->whereKeyNot($user->id)
                ->where('is_active', true)
                ->whereIn('role', ['it_manager', 'super_admin'])
                ->exists();

            if (! $otherManagers) {
                throw ValidationException::withMessages(['user' => ['At least one active IT manager must remain.']]);
            }
        }
    }

    private function assertCanManageTarget(User $actor, User $target): void
    {
        abort_if(
            $target->role === 'super_admin' && $actor->role !== 'super_admin',
            403,
            'Only a super administrator can manage another super administrator.'
        );
    }

    private function assertActiveUserManager(User $actor): void
    {
        abort_unless(
            $actor->is_active
                && $actor->hasPermission(Permission::USERS_MANAGE),
            403,
            'Your account can no longer manage users.'
        );
    }
}
