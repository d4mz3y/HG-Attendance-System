<?php

namespace App\Console\Commands;

use App\Models\AuthEvent;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateSuperAdmin extends Command
{
    protected $signature = 'users:create-super-admin
                            {username : New super administrator login username}';

    protected $description = 'Create the first super administrator from the local server console';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This bootstrap command must be run interactively from the local server console.');

            return self::FAILURE;
        }

        // Fail before requesting a hidden password when the single
        // super-administrator slot is already occupied. The transaction
        // below repeats this check to handle a concurrent local console.
        if (User::query()->where('role', 'super_admin')->exists()) {
            $this->error('A super administrator already exists. Use users:recover-super-admin for password recovery; no changes were made.');

            return self::FAILURE;
        }

        $data = [
            'username' => trim((string) $this->argument('username')),
            'password' => (string) $this->secret('New password (not echoed)'),
            'password_confirmation' => (string) $this->secret('Confirm new password (not echoed)'),
        ];
        $validator = Validator::make($data, [
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => PasswordPolicy::strong(confirmed: true),
            'password_confirmation' => ['required', 'string'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::query()->where('username', $data['username'])->first(['id', 'username', 'role']);
        if ($existing !== null && $existing->role !== 'admin') {
            $this->error('That username already belongs to a non-legacy account. No changes were made.');

            return self::FAILURE;
        }

        $verb = $existing ? 'promote the legacy admin account' : 'create';
        $this->warn("This will {$verb} {$data['username']} as the first super administrator with unrestricted portal access.");
        if (! $this->confirm('Create this super administrator?', false)) {
            $this->info('Creation cancelled. No changes were made.');

            return self::SUCCESS;
        }

        try {
            $user = DB::transaction(function () use ($data): User {
                // Recheck while the user table is locked so two local
                // bootstrap terminals cannot accidentally create two first
                // super-administrator accounts at the same time.
                User::query()->where('role', 'super_admin')->lockForUpdate()->get();
                if (User::query()->where('role', 'super_admin')->exists()) {
                    throw new \RuntimeException('A super administrator was created by another session.');
                }

                $existing = User::query()
                    ->where('username', $data['username'])
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null && $existing->role !== 'admin') {
                    throw new \RuntimeException('That username now belongs to a non-legacy account.');
                }

                $user = $existing ?? new User(['username' => $data['username']]);
                $user->forceFill([
                    'role' => 'super_admin',
                    'password' => $data['password'],
                    'is_active' => true,
                    'must_change_password' => false,
                    'password_changed_at' => now(),
                ])->save();
                $user->tokens()->delete();

                AuthEvent::query()->create([
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'event' => $existing ? 'legacy_admin_promoted_to_super_admin' : 'super_admin_created',
                    'user_agent' => 'artisan users:create-super-admin',
                    'metadata' => ['source' => 'local_console'],
                ]);

                return $user;
            }, 3);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage().' No changes were made.');

            return self::FAILURE;
        }

        $this->info("Super administrator {$user->username} is ready. Sign in with the password you just entered.");

        return self::SUCCESS;
    }
}
