<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $invalidRows = DB::table('leaves')
            ->whereNull('start_date')
            ->orWhereNull('end_date')
            ->orWhere('start_date', '0000-00-00')
            ->orWhere('end_date', '0000-00-00')
            ->limit(10)
            ->pluck('id');

        if ($invalidRows->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot make leave dates mandatory until invalid records are repaired. Leave IDs: '.$invalidRows->implode(', ')
            );
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
