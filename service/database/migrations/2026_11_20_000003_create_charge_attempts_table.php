<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_operation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('external_trx_id', 64);
            $table->string('status', 30)->index();
            $table->string('status_code', 20)->nullable();
            $table->text('status_detail')->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->string('internal_trx_id', 64)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->unique(['charge_operation_id', 'attempt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_attempts');
    }
};
