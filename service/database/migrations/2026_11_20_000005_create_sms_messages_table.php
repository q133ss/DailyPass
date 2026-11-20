<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 2);
            $table->string('kind', 20)->nullable();
            $table->string('address', 191);
            $table->text('message');
            $table->string('status', 20)->index();
            $table->string('status_code', 20)->nullable();
            $table->json('response_payload')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
