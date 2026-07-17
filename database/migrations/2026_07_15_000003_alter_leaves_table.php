<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $columns = DB::getSchemaBuilder()->getColumnListing('leaves');

            if (in_array('date', $columns) && ! in_array('start_date', $columns)) {
                $table->renameColumn('date', 'start_date');
            }

            if (! in_array('end_date', $columns)) {
                $table->date('end_date')->nullable()->after('start_date');
            }
        });

        $rows = DB::table('leaves')->get();
        foreach ($rows as $row) {
            $start = $row->start_date;
            $end = $row->end_date;

            $badStart = ! $start || $start === '0000-00-00';
            $badEnd = ! $end || $end === '0000-00-00';

            if ($badStart || $badEnd) {
                $safeStart = $badStart ? '2026-01-01' : $start;
                $safeEnd = $badEnd ? $safeStart : $end;

                DB::table('leaves')->where('id', $row->id)->update([
                    'start_date' => $safeStart,
                    'end_date' => $safeEnd,
                ]);
            }
        }

        Schema::table('leaves', function (Blueprint $table) {
            $table->date('end_date')->nullable(false)->after('start_date')->change();
        });
    }

    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            if (Schema::hasColumn('leaves', 'end_date')) {
                $table->dropColumn('end_date');
            }
            if (Schema::hasColumn('leaves', 'start_date')) {
                $table->renameColumn('start_date', 'date');
            }
        });
    }
};
