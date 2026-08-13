<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $multipleOpenSessions = DB::table('attendances')
            ->select('staff_id', DB::raw('COUNT(*) as open_count'))
            ->whereNull('clock_out')
            ->groupBy('staff_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->get();

        if ($multipleOpenSessions->isNotEmpty()) {
            $examples = $multipleOpenSessions
                ->map(fn ($row) => "staff {$row->staff_id} ({$row->open_count} open rows)")
                ->implode(', ');

            throw new RuntimeException(
                'Cannot enable multiple attendance sessions until staff with multiple open rows are resolved. Examples: '.$examples
            );
        }

        Schema::table('attendances', function (Blueprint $table): void {
            $table->unsignedSmallInteger('session_number')->default(1)->after('date');
            $table->boolean('open_session')->nullable()->after('session_number');
        });

        DB::table('attendances')
            ->whereNull('clock_out')
            ->update(['open_session' => true]);

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropUnique(['staff_id', 'date']);
            $table->unique(['staff_id', 'date', 'session_number'], 'attendance_session_unique');
            $table->unique(['staff_id', 'open_session'], 'attendance_one_open_session_unique');
        });
    }

    public function down(): void
    {
        $multipleSessions = DB::table('attendances')
            ->select('staff_id', 'date', DB::raw('COUNT(*) as session_count'))
            ->groupBy('staff_id', 'date')
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->get();

        if ($multipleSessions->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot roll back multiple attendance sessions while more than one session exists for a staff/date.'
            );
        }

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropUnique('attendance_one_open_session_unique');
            $table->dropUnique('attendance_session_unique');
            $table->unique(['staff_id', 'date']);
            $table->dropColumn(['session_number', 'open_session']);
        });
    }
};
