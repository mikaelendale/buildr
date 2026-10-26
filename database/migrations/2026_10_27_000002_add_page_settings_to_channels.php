<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every claimed channel gets its own public page. The owner can give it a
     * short link (shares the /{slug} namespace with creator profiles), hide
     * it, or list it without their name. Directory rows are per channel now,
     * so they remember the owner for tag filters and "by …" lines.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('slug', 30)->nullable()->unique()->after('username');
            $table->boolean('page_visible')->default(true)->after('visibility');
            $table->boolean('show_owner')->default(true)->after('page_visible');
        });

        Schema::table('directory_entries', function (Blueprint $table) {
            $table->foreignId('creator_id')->nullable()->after('listable_id')->index();
            $table->json('owner')->nullable()->after('real_name_am');
        });
    }

    public function down(): void
    {
        Schema::table('directory_entries', function (Blueprint $table) {
            $table->dropIndex(['creator_id']);
            $table->dropColumn(['creator_id', 'owner']);
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'page_visible', 'show_owner']);
        });
    }
};
