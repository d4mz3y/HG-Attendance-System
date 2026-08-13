<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('identifier')->unique();
            $table->string('name', 120);
            $table->enum('type', ['kiosk', 'biometric'])->default('kiosk')->index();
            $table->string('token_hash', 64);
            $table->string('token_last_four', 4);
            $table->json('abilities');
            $table->text('allowed_ips')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamp('last_event_at')->nullable();
            $table->string('last_event_at_raw', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->ipAddress('last_ip')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('kiosk_scan_queue', function (Blueprint $table) {
            $table->uuid('event_uuid')->nullable()->unique()->after('id');
            $table->foreignId('kiosk_device_id')->nullable()->after('event_uuid')->constrained('kiosk_devices')->nullOnDelete();
            $table->unsignedBigInteger('sequence')->nullable()->after('kiosk_device_id');
            $table->timestamp('occurred_at')->nullable()->after('staff_id_code');
            $table->string('occurred_at_raw', 64)->nullable()->after('occurred_at');
            $table->string('signature', 64)->nullable()->after('occurred_at_raw');
            $table->string('payload_hash', 64)->nullable()->after('signature');
            $table->string('error_code', 64)->nullable()->after('status');
            $table->text('error_message')->nullable()->after('error_code');
            $table->json('result')->nullable()->after('error_message');
            $table->timestamp('processed_at')->nullable()->after('synced_at');

            $table->unique(['kiosk_device_id', 'sequence'], 'kiosk_device_sequence_unique');
            $table->index(['kiosk_device_id', 'status'], 'kiosk_device_status_index');
            $table->index('occurred_at');
        });

        Schema::table('kiosk_scan_queue', function (Blueprint $table) {
            $table->dropColumn(['action', 'device_id', 'payload']);
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_scan_queue', function (Blueprint $table) {
            $table->string('action')->nullable();
            $table->string('device_id')->nullable();
            $table->text('payload')->nullable();
        });

        // MySQL may choose either composite kiosk-device index to support the
        // foreign key. Drop the constraint first so both indexes can then be
        // removed deterministically.
        Schema::table('kiosk_scan_queue', function (Blueprint $table) {
            $table->dropForeign(['kiosk_device_id']);
        });

        Schema::table('kiosk_scan_queue', function (Blueprint $table) {
            $table->dropUnique('kiosk_device_sequence_unique');
            $table->dropUnique(['event_uuid']);
            $table->dropIndex('kiosk_device_status_index');
            $table->dropIndex(['occurred_at']);
            $table->dropColumn([
                'event_uuid',
                'kiosk_device_id',
                'sequence',
                'occurred_at',
                'occurred_at_raw',
                'signature',
                'payload_hash',
                'error_code',
                'error_message',
                'result',
                'processed_at',
            ]);
        });

        Schema::dropIfExists('kiosk_devices');
    }
};
