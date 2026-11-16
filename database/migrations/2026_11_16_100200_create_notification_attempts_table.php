<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('notification_id')->constrained('notifications');
            $table->unsignedTinyInteger('attempt');
            $table->boolean('successful');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at');

            $table->unique(['notification_id', 'attempt']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_attempts');
    }
};
