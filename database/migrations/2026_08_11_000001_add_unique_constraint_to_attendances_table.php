<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('attendances')
            ->select('staff_id', 'date', DB::raw('COUNT(*) as duplicate_count'))
            ->groupBy('staff_id', 'date')
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $examples = $duplicates
                ->map(fn ($row) => "staff {$row->staff_id} on {$row->date} ({$row->duplicate_count} rows)")
                ->implode(', ');

            throw new RuntimeException(
                'Cannot add the attendance uniqueness constraint until duplicate records are resolved. Examples: '.$examples
            );
        }

        Schema::table('attendances', function (Blueprint $table) {
            $table->unique(['staff_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique(['staff_id', 'date']);
        });
    }
};
