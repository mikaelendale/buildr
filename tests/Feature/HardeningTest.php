<?php

use App\Enums\CreatorStatus;
use App\Jobs\SendTelegramMessage;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\DirectoryEntry;
use App\Models\User;
use App\Services\Admin\AdminActions;
use App\Services\Claims\SlugService;
use App\Services\Directory\DirectoryIndexer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

/**
 * A published creator with one listed channel, indexed.
 *
 * @return array{0: Creator, 1: Channel}
 */
function liveCreator(): array
{
    $user = User::factory()->emailOnly()->create(['email' => 'selam@example.com', 'telegram_id' => 555, 'bot_can_message' => true]);
    $creator = Creator::factory()->for($user)->published()->create(['slug' => 'selam']);
    $channel = Channel::factory()->claimedBy($creator)->create(['username' => 'selambuilds']);
    $creator->update(['primary_channel_id' => $channel->id]);
    app(DirectoryIndexer::class)->refreshCreator($creator);

    return [$creator->fresh(), $channel->fresh()];
}

it('builds reset links from APP_URL, whatever Host the request claims', function () {
    Queue::fake();
    liveCreator();

    $this->withServerVariables(['HTTP_HOST' => 'evil.example', 'HTTP_X_FORWARDED_HOST' => 'evil.example'])
        ->post('/forgot-password', ['email' => 'selam@example.com']);

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_starts_with($job->buttons[0]['url'] ?? '', rtrim((string) config('app.url'), '/').'/reset-password/'));
});

it('stops serving cards once a page is hidden or its owner banned', function () {
    Bus::fake();
    [$creator, $channel] = liveCreator();

    $this->get("/og/creator/{$creator->id}.png")->assertOk();
    $this->get("/og/channel/{$channel->id}.png")->assertOk();

    $creator->update(['status' => CreatorStatus::Hidden]);
    $this->get("/og/creator/{$creator->id}.png")->assertNotFound();

    $creator->update(['status' => CreatorStatus::Published]);
    app(AdminActions::class)->ban($creator->user, User::factory()->admin()->create(), 'Spam');

    $this->get("/og/creator/{$creator->id}.png")->assertNotFound();
    $this->get("/share-card/creator/{$creator->id}.png")->assertNotFound();
    $this->get("/og/channel/{$channel->id}.png")->assertNotFound();
    Storage::disk('public')->assertMissing("og/channel/{$channel->id}.png");
});

it('takes a banned creator off the directory, channels included', function () {
    Bus::fake();
    Queue::fake();
    [$creator, $channel] = liveCreator();

    expect(DirectoryEntry::where('listable_id', $channel->id)->exists())->toBeTrue();

    app(AdminActions::class)->ban($creator->user, User::factory()->admin()->create(), 'Spam');

    expect(DirectoryEntry::where('listable_id', $channel->id)->exists())->toBeFalse();
});

it('never confirms an admin password on the creator login', function () {
    User::factory()->admin()->create(['email' => 'root@example.com', 'password' => 'long-password-1']);

    $this->post('/login', ['email' => 'root@example.com', 'password' => 'long-password-1'])->assertSessionHasErrors('email');
    $right = session('errors')->first('email');

    $this->post('/login', ['email' => 'root@example.com', 'password' => 'wrong-password-1'])->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toBe($right);
    $this->assertGuest();
});

it('limits login attempts per address, whatever the IP', function () {
    User::factory()->emailOnly()->create(['email' => 'selam@example.com']);

    foreach (range(1, 20) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->post('/login', ['email' => 'selam@example.com', 'password' => 'wrong-password']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
        ->post('/login', ['email' => 'selam@example.com', 'password' => 'long-password-1'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('will not turn a creator account into an admin', function () {
    User::factory()->emailOnly()->create(['email' => 'selam@example.com']);

    $this->artisan('buildr:make-admin', ['email' => 'selam@example.com', '--generate' => true])->assertFailed();

    expect(User::where('email', 'selam@example.com')->sole()->isAdmin())->toBeFalse();
});

it('keeps a short link waiting for review out of anyone else’s reach', function () {
    $creator = Creator::factory()->create(['slug' => 'selam', 'pending_changes' => ['slug' => 'selam-builds']]);
    $other = Creator::factory()->create(['slug' => 'dawit']);

    $slugs = app(SlugService::class);

    expect($slugs->problem('selam-builds', ignore: $other))->not->toBeNull()
        ->and($slugs->problem('selam-builds', ignore: $creator))->toBeNull();
});
