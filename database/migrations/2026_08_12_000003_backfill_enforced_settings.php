<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = collect(config('hg.settings_defaults', []))
            ->map(fn (mixed $value, string $key): array => [
                'key' => $key,
                'value' => (string) $value,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            DB::table('settings')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Defaults are real application configuration after installation.
        // Rolling this migration back must not delete administrator changes.
    }
};
