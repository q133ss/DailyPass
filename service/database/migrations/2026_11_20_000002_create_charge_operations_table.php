<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->restrictOnDelete();
            $table->date('billing_day');
            $table->string('external_trx_id', 64)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8);
            $table->string('status', 30)->index();
            $table->unsignedInteger('attempts_count')->default(0);
            $table->timestampTz('next_retry_at')->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->string('internal_trx_id', 64)->nullable();
            $table->string('last_status_code', 20)->nullable();
            $table->text('last_status_detail')->nullable();
            $table->timestampTz('receipt_sent_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            // The database, not application code, is the final guard against
            // two charges for the same subscriber and billing day.
            $table->unique(['subscriber_id', 'billing_day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_operations');
    }
};
