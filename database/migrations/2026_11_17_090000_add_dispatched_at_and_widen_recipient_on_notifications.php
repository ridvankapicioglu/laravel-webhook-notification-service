<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Push tokens are validated up to 512 characters, the column was the default 255.
            $table->string('recipient', 512)->change();

            // When the job was last pushed to the queue. NULL means it never reached the queue.
            $table->timestamp('dispatched_at')->nullable()->after('available_at');
            $table->index(['status', 'dispatched_at']);

            // Metrics count results by the time they happened, not by updated_at.
            $table->index(['status', 'sent_at']);
            $table->index(['status', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['status', 'dispatched_at']);
            $table->dropIndex(['status', 'sent_at']);
            $table->dropIndex(['status', 'failed_at']);
            $table->dropColumn('dispatched_at');
            $table->string('recipient', 255)->change();
        });
    }
};
