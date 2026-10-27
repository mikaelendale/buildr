<?php

use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\ChannelController;
use App\Http\Controllers\Admin\ClaimController;
use App\Http\Controllers\Admin\CreatorController;
use App\Http\Controllers\Admin\CurationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SummarySectionController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin (plan §5.2, §10)
|--------------------------------------------------------------------------
|
| Fortify serves /admin/login, /admin/two-factor-challenge and the 2FA
| endpoints. Everything here needs: auth, role:admin, idle timeout, and
| (except the security page itself) a confirmed TOTP setup.
|
*/

Route::prefix('admin')->name('admin.')->middleware(['auth', 'auth.session', 'role:admin', 'admin.idle'])->group(function () {
    Route::get('security', [SecurityController::class, 'edit'])->middleware(RequirePassword::class)->name('security');
    Route::put('security/password', [SecurityController::class, 'update'])->middleware('throttle:6,1')->name('security.password');

    Route::middleware('2fa.confirmed')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('review', [ReviewController::class, 'index'])->name('review');
        Route::post('review/bulk', [ReviewController::class, 'bulk'])->name('review.bulk');
        Route::get('review/channel/{channel}', [ReviewController::class, 'showChannel'])->name('review.channel');
        Route::get('review/{creator}', [ReviewController::class, 'showCreator'])->name('review.creator');
        Route::post('review/{creator}/approve', [ReviewController::class, 'approve'])->name('review.approve');
        Route::post('review/{creator}/changes', [ReviewController::class, 'requestChanges'])->name('review.changes');
        Route::post('review/{creator}/reject', [ReviewController::class, 'reject'])->name('review.reject');
        Route::post('review/{creator}/fields/approve', [ReviewController::class, 'approveFields'])->name('review.fields.approve');
        Route::post('review/{creator}/fields/reject', [ReviewController::class, 'rejectFields'])->name('review.fields.reject');
        Route::post('review/channel/{channel}/publish', [ReviewController::class, 'publishChannel'])->name('review.channel.publish');
        Route::post('review/channel/{channel}/reject', [ReviewController::class, 'rejectChannel'])->name('review.channel.reject');
        Route::post('review/channel/{channel}/regenerate', [ReviewController::class, 'regenerate'])->name('review.channel.regenerate');
        Route::post('summaries/{summary}/approve', [ReviewController::class, 'approveSummary'])->name('summaries.approve');

        Route::put('sections/{channel}/{section}', [SummarySectionController::class, 'update'])->name('sections.update');
        Route::post('sections/{channel}/{section}/hide', [SummarySectionController::class, 'hide'])->name('sections.hide');
        Route::post('sections/{channel}/{section}/reset', [SummarySectionController::class, 'reset'])->name('sections.reset');
        Route::post('sections/{channel}/{section}/keep', [SummarySectionController::class, 'keep'])->name('sections.keep');
        Route::post('sections/{channel}/{section}/regenerate', [SummarySectionController::class, 'regenerate'])->name('sections.regenerate');

        Route::get('channels', [ChannelController::class, 'index'])->name('channels');
        Route::get('channels/new', [ChannelController::class, 'create'])->name('channels.create');
        Route::post('channels', [ChannelController::class, 'store'])->name('channels.store');
        Route::get('channels/{channel}', [ChannelController::class, 'show'])->name('channels.show');
        Route::put('channels/{channel}', [ChannelController::class, 'update'])->name('channels.update');
        Route::post('channels/{channel}/rescrape', [ChannelController::class, 'rescrape'])->name('channels.rescrape');
        Route::post('channels/{channel}/reprocess', [ChannelController::class, 'reprocess'])->name('channels.reprocess');
        Route::post('channels/{channel}/release', [ChannelController::class, 'release'])->name('channels.release');
        Route::delete('channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');
        Route::post('suggestions/{suggestion}/dismiss', [ChannelController::class, 'dismissSuggestion'])->name('suggestions.dismiss');

        Route::get('creators', [CreatorController::class, 'index'])->name('creators');
        Route::get('creators/{creator}', [CreatorController::class, 'show'])->name('creators.show');
        Route::put('creators/{creator}', [CreatorController::class, 'update'])->name('creators.update');
        Route::post('creators/{creator}/unpublish', [CreatorController::class, 'unpublish'])->name('creators.unpublish');
        Route::post('creators/{creator}/feature', [CreatorController::class, 'feature'])->name('creators.feature');
        Route::post('creators/{creator}/ban', [CreatorController::class, 'ban'])->name('creators.ban');
        Route::post('creators/{creator}/merge', [CreatorController::class, 'merge'])->name('creators.merge');

        Route::get('claims', [ClaimController::class, 'index'])->name('claims');
        Route::post('claims/{claim}/resolve', [ClaimController::class, 'startResolution'])->name('claims.resolve');
        Route::post('claims/{claim}/cancel', [ClaimController::class, 'cancel'])->name('claims.cancel');
        Route::post('claims/{claim}/check', [ClaimController::class, 'check'])->name('claims.check');

        Route::get('reports', [ReportController::class, 'index'])->name('reports');
        Route::post('reports/{report}/resolve', [ReportController::class, 'resolve'])->name('reports.resolve');

        Route::get('jobs', [OperationsController::class, 'jobs'])->name('jobs');
        Route::post('jobs/{uuid}/retry', [OperationsController::class, 'retry'])->name('jobs.retry');
        Route::delete('jobs/{uuid}', [OperationsController::class, 'forget'])->name('jobs.forget');
        Route::post('jobs/scraping/toggle', [OperationsController::class, 'toggleScraping'])->name('jobs.scraping');

        Route::get('ai-costs', [OperationsController::class, 'aiCosts'])->name('ai-costs');
        Route::put('ai-costs/budget', [OperationsController::class, 'updateBudget'])->name('ai-costs.budget');
        Route::post('ai-costs/toggle', [OperationsController::class, 'toggleAi'])->name('ai-costs.toggle');
        Route::post('ai-costs/resume', [OperationsController::class, 'resumeQuota'])->name('ai-costs.resume');

        Route::get('featured', [CurationController::class, 'featured'])->name('featured');
        Route::post('featured', [CurationController::class, 'addFeatured'])->name('featured.store');
        Route::post('featured/reorder', [CurationController::class, 'reorderFeatured'])->name('featured.reorder');
        Route::delete('featured/{item}', [CurationController::class, 'removeFeatured'])->name('featured.destroy');

        Route::get('tags', [CurationController::class, 'tags'])->name('tags');
        Route::post('tags', [CurationController::class, 'storeTag'])->name('tags.store');
        Route::put('tags/{tag}', [CurationController::class, 'updateTag'])->name('tags.update');
        Route::post('tags/{tag}/merge', [CurationController::class, 'mergeTag'])->name('tags.merge');

        Route::get('blocklist', [CurationController::class, 'blocklist'])->name('blocklist');
        Route::post('blocklist', [CurationController::class, 'block'])->name('blocklist.store');
        Route::delete('blocklist/{entry}', [CurationController::class, 'unblock'])->name('blocklist.destroy');

        Route::get('audit', [CurationController::class, 'audit'])->name('audit');

        Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements');
        Route::post('announcements', [AnnouncementController::class, 'store'])->middleware('throttle:5,1')->name('announcements.store');
        Route::post('announcements/test', [AnnouncementController::class, 'test'])->middleware('throttle:10,1')->name('announcements.test');
    });
});
