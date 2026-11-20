<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('msisdn', 32)->unique();
            $table->string('subscriber_id', 191)->unique();
            $table->string('status', 20)->index();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('stopped_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('opt_in_requested_at')->nullable();
            $table->string('opt_out_status', 20)->default('NONE');
            $table->unsignedInteger('opt_out_attempts')->default(0);
            $table->timestampTz('opt_out_confirmed_at')->nullable();
            $table->timestampTz('last_webhook_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
