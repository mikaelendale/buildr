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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('normalized_name')->index();
            $table->string('url', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('unknown');
            $table->foreignId('first_seen_post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->foreignId('last_seen_post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('mention_count')->default(0);
            $table->string('source')->default('ai');
            $table->boolean('hidden')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['channel_id', 'slug']);
        });

        Schema::create('channel_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('stats');
            $table->timestamp('computed_at');
            $table->timestamps();
        });

        Schema::create('summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('mode');
            $table->json('content')->nullable();
            $table->json('citations')->nullable();
            $table->json('verification')->nullable();
            $table->json('month_digests')->nullable();
            $table->string('status')->default('generating');
            $table->string('model')->nullable();
            $table->unsignedInteger('prompt_version');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('triggered_by')->default('system');
            $table->text('error')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'version']);
            $table->index(['channel_id', 'status']);
        });

        Schema::create('summary_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('section_key');
            $table->json('value')->nullable();
            $table->boolean('hidden')->default(false);
            $table->string('source')->default('creator');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('based_on_summary_id')->nullable()->constrained('summaries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['channel_id', 'section_key']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_curated')->default(false)->index();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->morphs('taggable');
            $table->string('source')->default('ai');
            $table->timestamps();

            $table->unique(['tag_id', 'taggable_type', 'taggable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('summary_overrides');
        Schema::dropIfExists('summaries');
        Schema::dropIfExists('channel_stats');
        Schema::dropIfExists('projects');
    }
};
