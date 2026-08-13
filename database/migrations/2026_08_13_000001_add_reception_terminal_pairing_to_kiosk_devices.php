<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_devices', function (Blueprint $table) {
            // A nullable unique value permits legacy/device-integration rows
            // while guaranteeing that there can be only one reception browser.
            $table->string('terminal_role', 32)->nullable()->unique()->after('type');
            $table->timestamp('paired_at')->nullable()->after('last_event_at_raw');
        });

        // Preserve a legacy browser only when its source had already been
        // restricted (or was observed before this upgrade). A token with no
        // device IP rule must be deliberately configured by IT instead of
        // silently becoming the reception terminal.
        $existingReception = DB::table('kiosk_devices')
            ->where('type', 'kiosk')
            ->orderBy('id')
            ->first(['id', 'allowed_ips', 'last_ip', 'token_hash']);

        if ($existingReception !== null
            && $existingReception->token_hash !== str_repeat('0', 64)
            && (trim((string) $existingReception->allowed_ips) !== '' || $existingReception->last_ip !== null)) {
            DB::table('kiosk_devices')
                ->where('id', $existingReception->id)
                ->update([
                    'terminal_role' => 'reception',
                    'paired_at' => now(),
                    'allowed_ips' => trim((string) $existingReception->allowed_ips) !== ''
                        ? $existingReception->allowed_ips
                        : $existingReception->last_ip,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('kiosk_devices', function (Blueprint $table) {
            $table->dropUnique(['terminal_role']);
            $table->dropColumn(['terminal_role', 'paired_at']);
        });
    }
};
