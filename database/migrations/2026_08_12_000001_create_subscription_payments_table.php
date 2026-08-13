<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 100)->unique();
            $table->string('paystack_transaction_id', 32)->nullable()->unique();
            $table->string('plan', 32);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('email');
            $table->enum('status', ['initialized', 'pending', 'successful', 'failed'])->default('initialized');
            $table->string('authorization_url', 2048)->nullable();
            $table->string('gateway_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('subscription_daily_usages', function (Blueprint $table) {
            $table->id();
            $table->date('usage_date')->unique();
            $table->unsignedInteger('scan_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_daily_usages');
        Schema::dropIfExists('subscription_payments');
    }
};
