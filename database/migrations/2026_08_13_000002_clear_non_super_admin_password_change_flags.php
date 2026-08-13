<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * HR and IT users have their passwords set by an authorized administrator
     * in Portal users. They do not have a self-service password screen, so a
     * legacy forced-change flag would be misleading and could become a future
     * lockout if another guard ever consults the raw column.
     */
    public function up(): void
    {
        DB::table('users')
            ->where('role', '!=', 'super_admin')
            ->where('must_change_password', true)
            ->update(['must_change_password' => false]);
    }

    public function down(): void
    {
        // The previous values cannot be reconstructed safely. This migration
        // intentionally leaves already-cleared flags unchanged on rollback.
    }
};
