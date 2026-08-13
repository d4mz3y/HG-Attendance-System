<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->date('employment_start_date')->nullable()->after('employment_status');
            $table->date('employment_end_date')->nullable()->after('employment_start_date');
            $table->index(['employment_start_date', 'employment_end_date'], 'staff_employment_dates');
        });

        Schema::create('staff_employment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->enum('status', ['Active', 'Inactive']);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'effective_from', 'effective_to'], 'staff_history_effective');
        });

        DB::table('staff')->orderBy('id')->chunkById(200, function ($staffMembers): void {
            $now = now();
            $attendanceRanges = DB::table('attendances')
                ->whereIn('staff_id', $staffMembers->pluck('id'))
                ->select('staff_id', DB::raw('MIN(date) as first_date'), DB::raw('MAX(date) as last_date'))
                ->groupBy('staff_id')
                ->get()
                ->keyBy('staff_id');
            $historyRows = [];

            foreach ($staffMembers as $staff) {
                $attendanceRange = $attendanceRanges->get($staff->id);
                $start = $staff->employment_start_date
                    ?: Carbon::parse($staff->created_at ?? $now)->toDateString();
                if ($attendanceRange?->first_date) {
                    $start = min($start, Carbon::parse($attendanceRange->first_date)->toDateString());
                }

                $end = null;
                if ($staff->employment_status === 'Inactive') {
                    $end = $staff->employment_end_date
                        ?: ($attendanceRange?->last_date
                            ? Carbon::parse($attendanceRange->last_date)->toDateString()
                            : $start);
                    $end = max($start, $end);
                }

                DB::table('staff')->where('id', $staff->id)->update([
                    'employment_start_date' => $start,
                    'employment_end_date' => $end,
                ]);
                $historyRows[] = [
                    'staff_id' => $staff->id,
                    'status' => 'Active',
                    'effective_from' => $start,
                    'effective_to' => $end,
                    'reason' => 'Backfilled during employment-history migration',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($end !== null) {
                    $historyRows[] = [
                        'staff_id' => $staff->id,
                        'status' => 'Inactive',
                        'effective_from' => Carbon::parse($end)->addDay()->toDateString(),
                        'effective_to' => null,
                        'reason' => 'Backfilled during employment-history migration',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($historyRows !== []) {
                DB::table('staff_employment_histories')->insert($historyRows);
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_employment_histories');

        Schema::table('staff', function (Blueprint $table) {
            $table->dropIndex('staff_employment_dates');
            $table->dropColumn(['employment_start_date', 'employment_end_date']);
        });
    }
};
