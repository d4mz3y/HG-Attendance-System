<?php

use App\Models\PublicHoliday;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_occurrence_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique();
            $table->foreignId('public_holiday_id')->nullable()->constrained('public_holidays')->nullOnDelete();
            $table->date('source_date')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        if (PublicHoliday::query()->exists()) {
            PublicHoliday::freezeHistoryThrough(now()->subDay()->startOfDay());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_occurrence_snapshots');
        DB::table('settings')->where('key', 'holiday_history_frozen_through')->delete();
    }
};
