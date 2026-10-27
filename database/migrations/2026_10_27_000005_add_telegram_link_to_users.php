<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email accounts connect the bot with a one-time /start link (only its
     * hash is stored). Once connected, password resets and every update go
     * to Telegram. `telegram_prompted_at` paces the "connect Telegram" step
     * after login; product announcements can be muted on their own.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_link_token', 64)->nullable()->unique()->after('telegram_username');
            $table->timestamp('telegram_link_expires_at')->nullable()->after('telegram_link_token');
            $table->timestamp('telegram_linked_at')->nullable()->after('telegram_link_expires_at');
            $table->timestamp('telegram_prompted_at')->nullable()->after('telegram_linked_at');
            $table->boolean('notify_product_updates')->default(true)->after('notify_summary_digest');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_link_token']);
            $table->dropColumn(['telegram_link_token', 'telegram_link_expires_at', 'telegram_linked_at', 'telegram_prompted_at', 'notify_product_updates']);
        });
    }
};
