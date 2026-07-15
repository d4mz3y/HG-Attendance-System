<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosk_scan_queue', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id_code');
            $table->string('action')->nullable();
            $table->string('device_id')->nullable();
            $table->text('payload')->nullable();
            $table->enum('status', ['pending', 'synced', 'failed'])->default('pending');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_scan_queue');
    }
};
