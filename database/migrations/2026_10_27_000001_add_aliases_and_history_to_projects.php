<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Projects merged by the resolver keep the names/domains they went by
     * and a one-line history of renames, domain moves and pivots.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('aliases')->nullable()->after('normalized_name');
            $table->string('history', 300)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['aliases', 'history']);
        });
    }
};
