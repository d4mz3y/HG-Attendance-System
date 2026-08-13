<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('default_schedule_versions', function (Blueprint $table): void {
            $table->id();
            $table->date('effective_from')->unique();
            $table->time('shift_start');
            $table->time('shift_end');
            $table->json('default_work_days');
            $table->unsignedSmallInteger('default_break_minutes')->default(0);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('staff_schedule_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('effective_from');
            $table->json('schedule');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['staff_id', 'effective_from']);
            $table->index(['effective_from', 'staff_id']);
        });

        $now = now();
        DB::table('default_schedule_versions')->insert([
            'effective_from' => '1970-01-01',
            'shift_start' => (string) DB::table('settings')->where('key', 'shift_start')->value('value') ?: '08:00',
            'shift_end' => (string) DB::table('settings')->where('key', 'shift_end')->value('value') ?: '17:00',
            'default_work_days' => (string) DB::table('settings')->where('key', 'default_work_days')->value('value') ?: '[1,2,3,4,5]',
            'default_break_minutes' => (int) (DB::table('settings')->where('key', 'default_break_minutes')->value('value') ?? 60),
            'changed_by' => null,
            'reason' => 'Initial schedule history backfill',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('staff')->orderBy('id')->chunkById(200, function ($staffMembers) use ($now): void {
            foreach ($staffMembers as $staff) {
                $rows = DB::table('staff_schedules')
                    ->where('staff_id', $staff->id)
                    ->orderBy('day_of_week')
                    ->get(['day_of_week', 'shift_start', 'shift_end', 'break_minutes', 'is_day_off', 'works_on_public_holiday'])
                    ->map(fn ($row): array => [
                        'day_of_week' => (int) $row->day_of_week,
                        'shift_start' => $row->shift_start ? substr((string) $row->shift_start, 0, 5) : null,
                        'shift_end' => $row->shift_end ? substr((string) $row->shift_end, 0, 5) : null,
                        'break_minutes' => (int) $row->break_minutes,
                        'is_day_off' => (bool) $row->is_day_off,
                        'works_on_public_holiday' => (bool) $row->works_on_public_holiday,
                    ])
                    ->values()
                    ->all();

                if ($rows === []) {
                    continue;
                }

                $effectiveFrom = $staff->employment_start_date
                    ?: ($staff->created_at ? substr((string) $staff->created_at, 0, 10) : now()->toDateString());
                DB::table('staff_schedule_versions')->insert([
                    'staff_id' => $staff->id,
                    'effective_from' => $effectiveFrom,
                    'schedule' => json_encode($rows, JSON_THROW_ON_ERROR),
                    'changed_by' => null,
                    'reason' => 'Initial staff schedule history backfill',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_schedule_versions');
        Schema::dropIfExists('default_schedule_versions');
    }
};
