<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily visit counts for site pages (the home page for now), next to
     * profile_views. Same privacy rules: counts only, no IPs.
     */
    public function up(): void
    {
        Schema::create('page_visits', function (Blueprint $table) {
            $table->id();
            $table->string('path', 191);
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('unique_views')->default(0);
            $table->timestamps();

            $table->unique(['path', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_visits');
    }
};
