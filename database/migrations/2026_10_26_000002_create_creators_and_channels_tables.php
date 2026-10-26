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
        Schema::create('creators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('display_name');
            $table->string('real_name')->nullable();
            $table->string('real_name_am')->nullable();
            $table->text('bio')->nullable();
            $table->string('location')->nullable();
            $table->string('avatar_path')->nullable();
            $table->unsignedBigInteger('primary_channel_id')->nullable()->index();
            $table->string('status')->default('draft')->index();
            $table->text('review_note')->nullable();
            $table->json('pending_changes')->nullable();
            $table->unsignedTinyInteger('onboarding_step')->default(1);
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('slug_changed_at')->nullable();
            $table->string('timezone')->nullable();
            $table->timestamps();
        });

        Schema::create('creator_socials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('url', 500);
            $table->string('handle')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('avatar_path')->nullable();
            $table->unsignedInteger('subscribers')->nullable();
            $table->foreignId('creator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('claim_status')->default('unclaimed')->index();
            $table->string('visibility')->default('hidden')->index();
            $table->string('review_status')->nullable()->index();
            $table->string('added_by')->default('self');
            $table->string('availability')->default('ok');
            $table->string('page_type')->nullable();
            $table->string('scrape_status')->default('idle');
            $table->unsignedBigInteger('newest_msg_id')->nullable();
            $table->unsignedBigInteger('oldest_msg_id')->nullable();
            $table->boolean('backfill_complete')->default(false);
            $table->timestamp('last_scraped_at')->nullable();
            $table->timestamp('last_processed_at')->nullable();
            $table->timestamp('last_post_at')->nullable();
            $table->timestamp('unavailable_since')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('timezone')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();
        });

        Schema::create('channel_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('subscribers');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['channel_id', 'captured_at']);
        });

        Schema::create('channel_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('username')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('open')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_suggestions');
        Schema::dropIfExists('channel_snapshots');
        Schema::dropIfExists('channels');
        Schema::dropIfExists('creator_socials');
        Schema::dropIfExists('creators');
    }
};
