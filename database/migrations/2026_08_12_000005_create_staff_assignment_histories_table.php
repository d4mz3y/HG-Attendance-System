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
        Schema::create('staff_assignment_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('company', 128);
            $table->string('branch', 64);
            $table->string('department', 128);
            $table->string('job_title', 128)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['staff_id', 'effective_from'], 'staff_assignment_effective_unique');
            $table->index(
                ['staff_id', 'effective_from', 'effective_to'],
                'staff_assignment_effective_range'
            );
            $table->index(
                ['company', 'branch', 'department'],
                'staff_assignment_organization'
            );
        });

        DB::table('staff')->orderBy('id')->chunkById(200, function ($staffMembers): void {
            $now = now();

            $rows = $staffMembers->map(function (object $staff) use ($now): array {
                $effectiveFrom = $staff->employment_start_date
                    ?: Carbon::parse($staff->created_at ?? $now)->toDateString();
                $branch = mb_strtoupper(trim((string) $staff->branch)) === 'HQ'
                    ? 'Lagos (HQ)'
                    : (string) ($staff->branch ?: 'Lagos (HQ)');

                if ($branch !== $staff->branch) {
                    DB::table('staff')->where('id', $staff->id)->update(['branch' => $branch]);
                }

                return [
                    'staff_id' => $staff->id,
                    'company' => (string) ($staff->company ?: 'Hogan Guards'),
                    'branch' => $branch,
                    'department' => (string) $staff->department,
                    'job_title' => $staff->job_title,
                    'effective_from' => Carbon::parse($effectiveFrom)->toDateString(),
                    'effective_to' => null,
                    'reason' => 'Backfilled during assignment-history migration',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all();

            if ($rows !== []) {
                DB::table('staff_assignment_histories')->insert($rows);
            }
        }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_assignment_histories');
    }
};
