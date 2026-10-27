<?php

use App\Http\Controllers\Auth\CreatorLoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Creator\CardStyleController;
use App\Http\Controllers\Creator\ChannelController;
use App\Http\Controllers\Creator\ClaimController;
use App\Http\Controllers\Creator\DashboardController;
use App\Http\Controllers\Creator\OnboardingController;
use App\Http\Controllers\Creator\ProfileController;
use App\Http\Controllers\Creator\ProjectController;
use App\Http\Controllers\Creator\SettingsController;
use App\Http\Controllers\Creator\SummaryController;
use App\Http\Controllers\Creator\TelegramController;
use App\Http\Controllers\Public\ClaimPageController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Creator login (email + password, web only) + bot webhook (§5.3)
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [CreatorLoginController::class, 'create'])->name('creator.login');
    Route::post('login', [CreatorLoginController::class, 'login'])->middleware('throttle:20,1')->name('creator.login.store');
    Route::post('register', [CreatorLoginController::class, 'register'])->middleware('throttle:6,1')->name('creator.register');
    Route::post('auth/dev-login', [CreatorLoginController::class, 'devLogin'])->name('creator.dev-login');
    Route::get('forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
});

// Reset links go to the account's Telegram (the bot), never by email. They
// work signed in too (from a "Change password" in settings, say).
Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');

Route::post('logout', [CreatorLoginController::class, 'destroy'])->middleware('auth')->name('creator.logout');
Route::post('telegram/webhook', TelegramWebhookController::class)->name('telegram.webhook');

Route::get('claim', [ClaimPageController::class, 'claim'])->name('claim');
Route::get('remove', [ClaimPageController::class, 'remove'])->name('remove');

/*
|--------------------------------------------------------------------------
| Logged-in creators
|--------------------------------------------------------------------------
*/

// auth.session: changing the password signs out every other session.
Route::middleware(['auth', 'auth.session', 'role:creator'])->group(function () {
    Route::post('remove', [ClaimPageController::class, 'startRemoval'])->middleware('throttle:10,1')->name('remove.start');

    Route::get('connect-telegram', [TelegramController::class, 'prompt'])->name('telegram.prompt');
    Route::post('connect-telegram/skip', [TelegramController::class, 'skip'])->name('telegram.skip');
    // Linking a Telegram account hands it password resets: needs the password
    // within the last few hours (signing in counts), not just a session.
    Route::get('telegram/connect', [TelegramController::class, 'connect'])->middleware(['password.confirm', 'throttle:20,1'])->name('telegram.connect');
    Route::delete('telegram/connect', [TelegramController::class, 'disconnect'])->name('telegram.disconnect');

    Route::get('onboarding', [OnboardingController::class, 'resume'])->name('onboarding');
    Route::post('onboarding/welcome', [OnboardingController::class, 'start'])->name('onboarding.start');
    Route::post('onboarding/channel', [OnboardingController::class, 'addChannel'])->middleware('throttle:10,1')->name('onboarding.channel');
    Route::post('onboarding/basics', [OnboardingController::class, 'saveBasics'])->name('onboarding.basics');
    Route::post('onboarding/building', [OnboardingController::class, 'continueToReview'])->name('onboarding.building');
    Route::post('onboarding/submit', [OnboardingController::class, 'submit'])->name('onboarding.submit');
    Route::get('onboarding/{step}', [OnboardingController::class, 'show'])->name('onboarding.show');

    Route::post('claims', [ClaimController::class, 'store'])->middleware('throttle:10,1')->name('claims.store');
    Route::post('claims/dispute', [ClaimController::class, 'dispute'])->middleware('throttle:5,1')->name('claims.dispute');
    Route::get('claims/{claim}', [ClaimController::class, 'show'])->name('claims.show');

    Route::prefix('api')->name('api.')->group(function () {
        Route::post('claims/{claim}/check', [ClaimController::class, 'check'])->middleware('throttle:claim-check')->name('claims.check');
        Route::get('onboarding/progress', [OnboardingController::class, 'progress'])->name('onboarding.progress');
        Route::get('channels/progress', [OnboardingController::class, 'channelsProgress'])->name('channels.progress');
        Route::get('telegram/status', [TelegramController::class, 'status'])->name('telegram.status');
        Route::get('slug-check', [ClaimController::class, 'slug'])->middleware('throttle:60,1')->name('slug-check');
    });

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::get('profile', [ProfileController::class, 'edit'])->name('profile');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');

        Route::get('summary', [SummaryController::class, 'index'])->name('summary');
        Route::put('summary/{channel}/{section}', [SummaryController::class, 'update'])->name('summary.update');
        Route::post('summary/{channel}/{section}/hide', [SummaryController::class, 'hide'])->name('summary.hide');
        Route::post('summary/{channel}/{section}/reset', [SummaryController::class, 'reset'])->name('summary.reset');
        Route::post('summary/{channel}/{section}/keep', [SummaryController::class, 'keep'])->name('summary.keep');
        Route::post('summary/{channel}/{section}/regenerate', [SummaryController::class, 'regenerate'])->middleware('throttle:10,1')->name('summary.regenerate');

        Route::get('projects', [ProjectController::class, 'index'])->name('projects');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::post('projects/reorder', [ProjectController::class, 'reorder'])->name('projects.reorder');
        Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::post('projects/{project}/toggle', [ProjectController::class, 'toggle'])->name('projects.toggle');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::get('channels', [ChannelController::class, 'index'])->name('channels');
        Route::post('channels/{channel}/primary', [ChannelController::class, 'primary'])->name('channels.primary');
        Route::post('channels/{channel}/rename', [ChannelController::class, 'rename'])->middleware('throttle:10,1')->name('channels.rename');
        Route::put('channels/{channel}/page', [ChannelController::class, 'page'])->name('channels.page');
        Route::delete('channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');

        Route::put('share-card/{type}/{id}', CardStyleController::class)->whereNumber('id')->middleware('throttle:30,1')->name('share-card.style');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/password-link', [SettingsController::class, 'passwordLink'])->middleware('throttle:3,1')->name('settings.password-link');
        Route::delete('settings/account', [SettingsController::class, 'destroy'])->name('settings.destroy');
    });
});
