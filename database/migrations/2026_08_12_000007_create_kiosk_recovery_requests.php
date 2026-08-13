<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk_recovery_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('kiosk_device_id')->constrained('kiosk_devices')->cascadeOnDelete();
            $table->string('event_set_hash', 64);
            $table->unsignedBigInteger('server_sequence');
            $table->json('requested_events');
            $table->enum('status', ['pending', 'approved', 'consumed', 'expired'])->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_reason', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_until')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique(['kiosk_device_id', 'event_set_hash'], 'kiosk_recovery_event_set_unique');
            $table->index(['kiosk_device_id', 'created_at'], 'kiosk_recovery_device_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_recovery_requests');
    }
};
