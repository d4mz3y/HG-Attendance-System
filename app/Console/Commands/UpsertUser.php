<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UpsertUser extends Command
{
    protected $signature = 'users:upsert
                            {username : Login username}
                            {role : hr, hr_assistant, or it_manager}
                            {--password= : Omit to enter the password securely}';

    protected $description = 'Create or update an authorized attendance portal user';

    public function handle(): int
    {
        $data = [
            'username' => trim((string) $this->argument('username')),
            'role' => (string) $this->argument('role'),
            'password' => (string) ($this->option('password') ?: $this->secret('Password')),
        ];

        $validator = Validator::make($data, [
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'role' => ['required', 'in:hr,hr_assistant,it_manager'],
            'password' => PasswordPolicy::strong(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['username' => $data['username']],
            [
                'role' => $data['role'],
                'password' => Hash::make($data['password']),
                'is_active' => true,
                // HR and IT accounts do not have a self-service password
                // screen. Their password is set by the authorized operator
                // creating the account, or later through Portal users.
                'must_change_password' => false,
                'password_changed_at' => now(),
            ]
        );
        $user->tokens()->delete();

        $this->info("User {$data['username']} is ready with role {$data['role']}.");

        return self::SUCCESS;
    }
}
