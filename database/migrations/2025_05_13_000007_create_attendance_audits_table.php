<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('attendances')->cascadeOnDelete();
            $table->json('changed_fields');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index('attendance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_audits');
    }
};
