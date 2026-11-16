<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->nullable()->constrained('notification_batches');
            $table->string('idempotency_key', 128)->nullable()->unique();

            $table->string('channel', 16);
            $table->string('priority', 16);
            $table->string('recipient');
            $table->text('content');

            $table->string('status', 16);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('available_at');

            $table->string('provider_message_id')->nullable();
            $table->string('last_error')->nullable();
            $table->string('correlation_id', 64)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['channel', 'created_at']);
            $table->index(['status', 'available_at']);
            $table->index(['status', 'updated_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
