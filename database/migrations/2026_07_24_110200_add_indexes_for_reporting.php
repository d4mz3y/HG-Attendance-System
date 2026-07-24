<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_schedules', function (Blueprint $table) {
            $table->index(['staff_id', 'is_day_off', 'works_on_public_holiday'], 'ss_st_aff_wo_ph');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['date', 'staff_id', 'clock_out'], 'att_date_st_co');
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->index(['staff_id', 'start_date', 'end_date', 'status'], 'lv_st_sd_ed_st');
        });
    }

    public function down(): void
    {
        Schema::table('staff_schedules', function (Blueprint $table) {
            $table->dropIndex('ss_st_aff_wo_ph');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('att_date_st_co');
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->dropIndex('lv_st_sd_ed_st');
        });
    }
};