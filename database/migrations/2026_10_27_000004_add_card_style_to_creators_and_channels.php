<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The owner's share card look: a light, dark or solid-color card, and
     * the color it prints in (also the tone of their page cover). Null keeps
     * the defaults: a light card in the color seeded from the handle.
     */
    public function up(): void
    {
        foreach (['creators', 'channels'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('card_theme', 12)->nullable();
                $table->string('card_color', 12)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['creators', 'channels'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['card_theme', 'card_color']);
            });
        }
    }
};
