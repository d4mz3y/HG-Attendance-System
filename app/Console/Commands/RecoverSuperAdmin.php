<?php

namespace App\Console\Commands;

use App\Models\AuthEvent;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class RecoverSuperAdmin extends Command
{
    protected $signature = 'users:recover-super-admin
                            {username? : Existing super administrator username (optional when exactly one exists)}';

    protected $description = 'Interactively set a forgotten super administrator password from the local server console';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This recovery command must be run interactively from the local server console.');

            return self::FAILURE;
        }

        $username = trim((string) $this->argument('username'));
        if ($username === '') {
            $user = $this->singleSuperAdministrator();
            if (! $user) {
                return self::FAILURE;
            }
        } else {
            $user = User::query()->where('username', $username)->first();
        }
        if (! $user || ! $user->isSuperAdmin()) {
            $this->error('No super administrator was found with that username. No changes were made.');

            return self::FAILURE;
        }

        $this->warn("This will set a new password for {$user->username} (user #{$user->id}), reactivate the account if needed, and sign it out everywhere.");
        if (! $this->confirm('Continue with this super administrator recovery?', false)) {
            $this->info('Recovery cancelled. No changes were made.');

            return self::SUCCESS;
        }

        $data = [
            'password' => (string) $this->secret('New password (not echoed)'),
            'password_confirmation' => (string) $this->secret('Confirm new password (not echoed)'),
        ];
        $validator = Validator::make($data, [
            'password' => PasswordPolicy::strong(confirmed: true),
            'password_confirmation' => ['required', 'string'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $data): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isSuperAdmin()) {
                throw new RuntimeException('The selected account is no longer a super administrator.');
            }

            $locked->forceFill([
                'password' => $data['password'],
                'is_active' => true,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();
            $locked->tokens()->delete();

            AuthEvent::query()->create([
                'user_id' => $locked->id,
                'username' => $locked->username,
                'event' => 'super_admin_password_recovered',
                'user_agent' => 'artisan users:recover-super-admin',
                'metadata' => ['source' => 'local_console'],
            ]);
        }, 3);

        $this->info('Super administrator password updated. Sign in with the new password.');

        return self::SUCCESS;
    }

    private function singleSuperAdministrator(): ?User
    {
        $users = User::query()
            ->where('role', 'super_admin')
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($users->count() === 1) {
            return $users->first();
        }

        $this->error('More than one (or no) super administrator account exists. Provide the username explicitly. No changes were made.');

        return null;
    }
}
