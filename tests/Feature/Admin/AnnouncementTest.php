<?php

use App\Jobs\SendTelegramMessage;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Queue::fake());

function announcer(): User
{
    return User::factory()->admin()->withTwoFactor()->create();
}

/**
 * Two who get it, one muted, one paused, one never connected.
 */
function audience(): void
{
    User::factory()->create(['telegram_id' => 501, 'telegram_username' => 'selam_t']);
    User::factory()->create(['telegram_id' => 502]);
    User::factory()->create(['telegram_id' => 503, 'notify_product_updates' => false]);
    User::factory()->create(['telegram_id' => 504, 'bot_can_message' => false]);
    User::factory()->emailOnly()->create();
}

it('shows who an announcement will reach', function () {
    audience();

    $this->actingAs(announcer())->get('/admin/announcements')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/announcements')
        ->where('counts.reach', 2)
        ->where('counts.connected', 4)
        ->where('counts.muted', 1)
        ->where('counts.paused', 1)
        ->where('counts.creators', 5));
});

it('sends plain text with a button to everyone who kept news on, and logs it', function () {
    audience();
    $admin = announcer();

    $this->actingAs($admin)->from('/admin/announcements')->post('/admin/announcements', [
        'body' => "Share cards have colors <now> & more.\nTry it.",
        'button_label' => 'Open my dashboard',
        'button_url' => 'https://buildr.mikaelen.site/dashboard',
    ])->assertRedirect('/admin/announcements')->assertSessionHasNoErrors();

    Queue::assertPushed(SendTelegramMessage::class, 2);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => $job->chatId === 501
        && str_contains($job->html, 'colors &lt;now&gt; &amp; more.'."\n".'Try it.')
        && $job->buttons === [['text' => 'Open my dashboard', 'url' => 'https://buildr.mikaelen.site/dashboard']]);

    $announcement = Announcement::sole();
    expect($announcement)->recipients->toBe(2)->user_id->toBe($admin->id)
        ->and(AuditLog::where('action', 'announcement.sent')->exists())->toBeTrue();

    $this->actingAs($admin)->get('/admin/announcements')->assertInertia(fn (Assert $page) => $page
        ->where('history.0.body', "Share cards have colors <now> & more.\nTry it.")
        ->where('history.0.recipients', 2)
        ->where('history.0.by', $admin->name));
});

it('sends a test copy to one connected account only', function () {
    audience();

    $this->actingAs(announcer())->post('/admin/announcements/test', ['body' => 'Hello', 'to' => '@Selam_T'])->assertSessionHasNoErrors();
    Queue::assertPushed(SendTelegramMessage::class, 1);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => $job->chatId === 501);

    $this->actingAs(announcer())->post('/admin/announcements/test', ['body' => 'Hello', 'to' => '@nobody'])->assertSessionHasErrors('to');
    expect(Announcement::count())->toBe(0);
});

it('needs a message, a full https link with a label, and an admin', function () {
    $this->actingAs(announcer())->post('/admin/announcements', ['body' => '', 'button_label' => 'Go', 'button_url' => 'javascript:alert(1)'])
        ->assertSessionHasErrors(['body', 'button_url']);
    $this->actingAs(announcer())->post('/admin/announcements', ['body' => 'Hi', 'button_url' => 'https://example.com'])
        ->assertSessionHasErrors('button_label');

    $this->actingAs(User::factory()->create())->post('/admin/announcements', ['body' => 'Hi'])->assertForbidden();

    Queue::assertNothingPushed();
});
