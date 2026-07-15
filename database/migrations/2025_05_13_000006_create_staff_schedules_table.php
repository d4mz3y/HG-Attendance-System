<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->enum('day_of_week', ['0','1','2','3','4','5','6'])->default('1');
            $table->time('shift_start')->nullable();
            $table->time('shift_end')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(60);
            $table->boolean('is_day_off')->default(false);
            $table->timestamps();

            $table->unique(['staff_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_schedules');
    }
};
