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
     * Sponsorship requests: a creator asks to feature a claimed channel on
     * the homepage; an admin approves or rejects. One row per request,
     * scoped to a channel. The partial unique index keeps a channel from
     * holding more than one pending or approved sponsorship at a time, while
     * still letting a creator re-request after a rejection or cancellation.
     */
    public function up(): void
    {
        Schema::create('sponsorships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending')->index();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable()->index();
            $table->timestamps();
        });

        // CREATE UNIQUE INDEX ... WHERE is supported by both Postgres and
        // SQLite, so the constraint is created for both (and exercisable in
        // the SQLite test suite) rather than guarded behind a driver check.
        DB::statement("CREATE UNIQUE INDEX sponsorships_channel_active_unique ON sponsorships (channel_id) WHERE status IN ('pending', 'approved')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sponsorships');
    }
};
