<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_hash', 64)->unique();
            $table->string('subscriber_id', 191)->index();
            $table->string('status', 20);
            $table->timestampTz('event_timestamp')->nullable();
            $table->json('payload');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_events');
    }
};
