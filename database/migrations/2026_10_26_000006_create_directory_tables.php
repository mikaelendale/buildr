<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * directory_entries is a denormalized row per public listing (a published
     * creator, or a published unclaimed channel). Search, discover, tags and
     * the landing page all read from it.
     */
    public function up(): void
    {
        Schema::create('directory_entries', function (Blueprint $table) {
            $table->id();
            $table->morphs('listable');
            $table->string('url_path');
            $table->string('name');
            $table->string('real_name')->nullable();
            $table->string('real_name_am')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('hook', 500)->nullable();
            $table->json('vibe_tags')->nullable();
            $table->json('channels')->nullable();
            $table->unsignedInteger('subscribers')->default(0)->index();
            $table->boolean('is_claimed')->default(false)->index();
            $table->string('lang', 8)->nullable()->index();
            $table->timestamp('last_post_at')->nullable()->index();
            $table->decimal('posts_per_week', 8, 2)->default(0);
            $table->decimal('trending_score', 12, 4)->default(0)->index();
            $table->boolean('is_available')->default(true);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['listable_type', 'listable_id']);
        });

        Schema::create('search_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('directory_entry_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->text('value');
            $table->decimal('weight', 5, 2)->default(1);
            $table->timestamps();

            $table->index('field');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE search_documents ADD COLUMN tsv tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(value, ''))) STORED");
            DB::statement('CREATE INDEX search_documents_tsv_index ON search_documents USING GIN (tsv)');
            DB::statement('CREATE INDEX search_documents_value_trgm_index ON search_documents USING GIN (value gin_trgm_ops)');
        }

        Schema::create('featured_items', function (Blueprint $table) {
            $table->id();
            $table->morphs('featurable');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['featurable_type', 'featurable_id']);
        });

        Schema::create('profile_views', function (Blueprint $table) {
            $table->id();
            $table->morphs('viewable');
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('unique_views')->default(0);
            $table->timestamps();

            $table->unique(['viewable_type', 'viewable_id', 'date']);
            $table->index('date');
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job')->index();
            $table->nullableMorphs('subject');
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd_est', 12, 6)->default(0);
            $table->string('status');
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('profile_views');
        Schema::dropIfExists('featured_items');
        Schema::dropIfExists('search_documents');
        Schema::dropIfExists('directory_entries');
    }
};
