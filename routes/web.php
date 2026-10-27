<?php

use App\Http\Controllers\Public\ChannelPageController;
use App\Http\Controllers\Public\ChannelSuggestionController;
use App\Http\Controllers\Public\CreatorProfileController;
use App\Http\Controllers\Public\DiscoverController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\MediaController;
use App\Http\Controllers\Public\ReportController;
use App\Http\Controllers\Public\SearchController;
use App\Http\Controllers\Public\SeoController;
use App\Http\Controllers\Public\ShareCardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/', HomeController::class)->name('home');
Route::get('search', [SearchController::class, 'index'])->middleware('throttle:search')->name('search');
Route::get('discover', [DiscoverController::class, 'index'])->name('discover');
Route::get('tags/{tag:slug}', [DiscoverController::class, 'tag'])->name('tags.show');
Route::get('c/{username}', [ChannelPageController::class, 'show'])->name('channels.show');

Route::inertia('about', 'public/about')->name('about');
Route::inertia('terms', 'public/terms')->name('terms');
Route::inertia('privacy', 'public/privacy')->name('privacy');
Route::inertia('takedown-policy', 'public/takedown')->name('takedown');

Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('og/{type}/{id}.png', [SeoController::class, 'og'])->whereNumber('id')->middleware('throttle:120,1')->name('og');
Route::get('share-card/{type}/{id}.png', ShareCardController::class)->whereNumber('id')->middleware('throttle:60,1')->name('share-card');
Route::get('media/{path}', MediaController::class)->where('path', 'avatars/[a-z]+/\d+/[a-f0-9]{10}-\d+\.jpg')->name('media');

Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:reports')->name('reports.store');
Route::post('channel-suggestions', [ChannelSuggestionController::class, 'store'])->middleware('throttle:suggest-channel')->name('channel-suggestions.store');

Route::prefix('api')->name('api.')->group(function () {
    Route::get('search/suggest', [SearchController::class, 'suggest'])->middleware('throttle:suggest')->name('search.suggest');
});

/*
|--------------------------------------------------------------------------
| Creators (Telegram login, claims, onboarding, dashboard)
|--------------------------------------------------------------------------
*/

require __DIR__.'/creator.php';

/*
|--------------------------------------------------------------------------
| Admin (Fortify handles /admin/login + 2FA)
|--------------------------------------------------------------------------
*/

require __DIR__.'/admin.php';

/*
|--------------------------------------------------------------------------
| Creator profile catch-all: must stay LAST (plan §4)
|--------------------------------------------------------------------------
*/

Route::get('{slug}', [CreatorProfileController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]{1,28}[a-z0-9]')
    ->name('profiles.show');
