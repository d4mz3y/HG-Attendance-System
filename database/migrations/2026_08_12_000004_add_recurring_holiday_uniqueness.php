<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $recurring = DB::table('public_holidays')
            ->where('is_recurring', true)
            ->get(['id', 'date']);
        $duplicates = $recurring
            ->groupBy(fn ($holiday) => substr((string) $holiday->date, 5, 5))
            ->filter(fn ($holidays) => $holidays->count() > 1);

        if ($duplicates->isNotEmpty()) {
            $examples = $duplicates
                ->map(fn ($holidays, string $monthDay) => $monthDay.' (IDs '.$holidays->pluck('id')->implode(', ').')')
                ->implode('; ');

            throw new RuntimeException(
                'Duplicate recurring public holidays must be resolved before enforcing annual uniqueness: '.$examples
            );
        }

        Schema::table('public_holidays', function (Blueprint $table): void {
            $table->char('recurring_month_day', 5)->nullable()->after('is_recurring');
        });

        foreach ($recurring as $holiday) {
            DB::table('public_holidays')
                ->where('id', $holiday->id)
                ->update(['recurring_month_day' => substr((string) $holiday->date, 5, 5)]);
        }

        Schema::table('public_holidays', function (Blueprint $table): void {
            $table->unique('recurring_month_day', 'public_holidays_recurring_month_day_unique');
        });
    }

    public function down(): void
    {
        Schema::table('public_holidays', function (Blueprint $table): void {
            $table->dropUnique('public_holidays_recurring_month_day_unique');
            $table->dropColumn('recurring_month_day');
        });
    }
};
