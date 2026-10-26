<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('msg_id');
            $table->timestamp('posted_at')->nullable();
            $table->boolean('edited')->default(false);
            $table->text('text')->nullable();
            $table->text('text_html')->nullable();
            $table->string('lang', 8)->nullable();
            $table->unsignedInteger('views')->nullable();
            $table->boolean('has_media')->default(false);
            $table->json('media_types')->nullable();
            $table->boolean('is_forward')->default(false);
            $table->string('forwarded_from')->nullable();
            $table->unsignedBigInteger('reply_to_msg_id')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('reactions')->nullable();
            $table->boolean('is_service')->default(false);
            $table->boolean('is_ad')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('scraped_at')->nullable();
            $table->timestamp('views_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'msg_id']);
            $table->index(['channel_id', 'posted_at']);
        });

        Schema::create('post_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1000);
            $table->string('domain')->index();
            $table->string('kind');
            $table->timestamps();
        });

        Schema::create('post_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('topics')->nullable();
            $table->json('projects')->nullable();
            $table->json('tools')->nullable();
            $table->string('milestone')->nullable();
            $table->string('tone')->nullable();
            $table->boolean('is_personal_sensitive')->default(false);
            $table->string('model');
            $table->unsignedInteger('prompt_version');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_extractions');
        Schema::dropIfExists('post_links');
        Schema::dropIfExists('posts');
    }
};
