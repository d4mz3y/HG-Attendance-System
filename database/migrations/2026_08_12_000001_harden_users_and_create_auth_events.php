<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role')->index();
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('password_changed_at')->nullable()->after('password');
            $table->timestamp('last_login_at')->nullable()->after('password_changed_at');
        });

        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username', 64)->nullable()->index();
            $table->string('event', 64)->index();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });

        // Existing installations may still contain the two accounts that were
        // previously seeded with published default passwords. Never carry
        // those credentials (or old bearer tokens) into the hardened release.
        DB::table('users')->update(['must_change_password' => true]);

        DB::table('users')
            ->whereIn('username', ['admin', 'superadmin'])
            ->orderBy('id')
            ->get(['id', 'username', 'password'])
            ->each(function (object $user): void {
                $knownPassword = $user->username === 'admin' ? 'admin123' : 'super123';

                if (Hash::check($knownPassword, $user->password)) {
                    DB::table('users')->where('id', $user->id)->update([
                        'is_active' => false,
                        'must_change_password' => true,
                    ]);
                }
            });

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->delete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'is_active',
                'must_change_password',
                'password_changed_at',
                'last_login_at',
            ]);
        });
    }
};
