<?php

use App\Http\Controllers\Creator\TelegramController;
use App\Jobs\SendTelegramMessage;
use App\Models\Creator;
use App\Models\User;
use App\Services\Telegram\TelegramLinker;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'buildr.telegram.bot_token' => '123456:TEST-TOKEN',
        'buildr.telegram.bot_username' => 'BuildrTestBot',
        'buildr.telegram.webhook_secret' => 'shh',
    ]);
    Queue::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
});

/**
 * @param  array<string, mixed>  $from
 */
function botSays(string $text, array $from = ['id' => 555, 'username' => 'selam_t']): void
{
    test()->postJson('/telegram/webhook', [
        'message' => ['text' => $text, 'chat' => ['id' => $from['id'], 'type' => 'private'], 'from' => $from],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'shh'])->assertOk();
}

function emailCreator(array $attributes = []): User
{
    $user = User::factory()->emailOnly()->create(['email' => 'selam@example.com'] + $attributes);
    Creator::factory()->for($user)->create();

    return $user->fresh();
}

/**
 * The /start token inside the deep link the Connect button opens.
 */
function connectToken(User $user): string
{
    $location = (string) test()->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get('/telegram/connect')->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://t.me/BuildrTestBot?start='.TelegramLinker::PREFIX);

    return substr($location, strlen('https://t.me/BuildrTestBot?start='));
}

function sentTo(int $chatId, string $needle): bool
{
    return Queue::pushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => $job->chatId === $chatId
        && (str_contains($job->html, $needle) || str_contains(json_encode($job->buttons, JSON_UNESCAPED_SLASHES) ?: '', $needle)))->isNotEmpty();
}

it('connects an email account when its owner presses Start on the one-time link', function () {
    $user = emailCreator();

    $start = connectToken($user);
    expect($user->fresh()->telegram_link_token)->not->toBeNull()->not->toContain(substr($start, 5));

    $this->actingAs($user)->getJson('/api/telegram/status')->assertJson(['connected' => false]);

    botSays("/start {$start}");

    expect($user->fresh())
        ->telegram_id->toBe(555)
        ->telegram_username->toBe('selam_t')
        ->bot_can_message->toBeTrue()
        ->telegram_link_token->toBeNull();

    $this->actingAs($user)->getJson('/api/telegram/status')->assertJson(['connected' => true, 'username' => 'selam_t']);
    Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Telegram connected'));
});

it('ignores expired or reused links', function () {
    $user = emailCreator();
    $start = connectToken($user);

    $this->travel(TelegramLinker::TTL_MINUTES + 1)->minutes();
    botSays("/start {$start}");
    expect($user->fresh()->telegram_id)->toBeNull();

    $this->travelBack();
    botSays('/start '.TelegramLinker::PREFIX.'not-a-real-token');
    expect($user->fresh()->telegram_id)->toBeNull();
});

it('never moves a Telegram account off another Buildr account', function () {
    // Someone sends the victim their own link: one tap must not hand over the victim's resets.
    $victim = User::factory()->emailOnly()->create(['email' => 'victim@example.com', 'telegram_id' => 555, 'bot_can_message' => true]);
    $attacker = emailCreator();

    botSays('/start '.connectToken($attacker));

    expect($attacker->fresh()->telegram_id)->toBeNull()
        ->and($victim->fresh()->telegram_id)->toBe(555);
    Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'already connected to another Buildr account'));
});

it('asks for the password before linking when the sign-in is old', function () {
    $user = emailCreator();

    $this->actingAs($user)->get('/telegram/connect')->assertRedirect('/admin/user/confirm-password');
    expect($user->fresh()->telegram_link_token)->toBeNull();
});

it('warns the old chat when an account switches to another Telegram', function () {
    $user = emailCreator(['telegram_id' => 444, 'bot_can_message' => true]);

    botSays('/start '.connectToken($user));

    expect($user->fresh()->telegram_id)->toBe(555)
        ->and(sentTo(444, 'connected to a different Telegram account'))->toBeTrue();
});

it('caps reset links per address', function () {
    emailCreator(['telegram_id' => 555, 'bot_can_message' => true]);

    foreach (range(1, 5) as $i) {
        $this->travel(2)->minutes();
        $this->post('/forgot-password', ['email' => 'selam@example.com'])->assertSessionHas('reset_sent', true);
    }

    Queue::assertPushed(SendTelegramMessage::class, 3);
});

it('offers the bot right after signing up, then carries on', function () {
    $this->post('/register', ['name' => 'Selam', 'email' => 'new@example.com', 'password' => 'long-password-1'])
        ->assertRedirect('/connect-telegram');

    $this->get('/connect-telegram')->assertOk()->assertInertia(fn ($page) => $page
        ->component('auth/connect-telegram')
        ->where('connected', false)
        ->where('next', url('/onboarding')));

    $this->post('/connect-telegram/skip')->assertRedirect(url('/onboarding'));
});

it('asks again at login only every two weeks, and never once connected', function () {
    $user = emailCreator();

    $this->post('/login', ['email' => 'selam@example.com', 'password' => 'long-password-1'])->assertRedirect('/connect-telegram');
    $this->get('/connect-telegram')->assertOk();
    auth()->logout();

    $this->post('/login', ['email' => 'selam@example.com', 'password' => 'long-password-1'])->assertRedirect('/dashboard');
    auth()->logout();

    $this->travel(TelegramController::PROMPT_EVERY_DAYS + 1)->days();
    $this->post('/login', ['email' => 'selam@example.com', 'password' => 'long-password-1'])->assertRedirect('/connect-telegram');
    auth()->logout();

    $user->forceFill(['telegram_id' => 555])->save();
    $this->post('/login', ['email' => 'selam@example.com', 'password' => 'long-password-1'])->assertRedirect('/dashboard');
});

it('sends the reset link to the bot, and says the same thing whoever asks', function () {
    emailCreator(['telegram_id' => 555, 'bot_can_message' => false]);
    User::factory()->emailOnly()->create(['email' => 'nobot@example.com']);
    User::factory()->admin()->create(['email' => 'root@example.com', 'telegram_id' => 999]);

    foreach (['selam@example.com', 'nobot@example.com', 'nobody@example.com', 'root@example.com'] as $email) {
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('reset_sent', true);
    }

    // Paused updates still get a reset they asked for; nobody else gets anything.
    expect(sentTo(555, '/reset-password/'))->toBeTrue();
    Queue::assertPushed(SendTelegramMessage::class, 1);
});

it('changes the password from the link and tells the bot', function () {
    $user = emailCreator(['telegram_id' => 555, 'bot_can_message' => true]);
    $token = Password::broker()->createToken($user);

    $this->post('/reset-password', ['token' => 'wrong', 'email' => 'selam@example.com', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
        ->assertSessionHasErrors('email');

    $this->get("/reset-password/{$token}?email=selam@example.com")->assertOk();

    $this->post('/reset-password', ['token' => $token, 'email' => 'selam@example.com', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
        ->assertRedirect('/login')
        ->assertSessionHas('status');

    expect(Hash::check('brand-new-pass', $user->fresh()->password))->toBeTrue()
        ->and(sentTo(555, 'password was just changed'))->toBeTrue();

    // One use only.
    $this->post('/reset-password', ['token' => $token, 'email' => 'selam@example.com', 'password' => 'another-pass-1', 'password_confirmation' => 'another-pass-1'])
        ->assertSessionHasErrors('email');
});

it('hands out a reset link from the bot with /reset, once a minute', function () {
    emailCreator(['telegram_id' => 555, 'bot_can_message' => true]);

    botSays('/reset');
    botSays('/reset');

    expect(sentTo(555, '/reset-password/'))->toBeTrue();
    Queue::assertPushed(SendTelegramMessage::class, 1);
    Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'just sent you a reset link'));

    botSays('/reset', ['id' => 4242]);
    Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', "isn't connected"));
});

it('sends a change-password link from settings and can disconnect', function () {
    $user = emailCreator(['telegram_id' => 555, 'bot_can_message' => true]);

    $this->actingAs($user)->post('/dashboard/settings/password-link')->assertRedirect();
    expect(sentTo(555, '/reset-password/'))->toBeTrue();

    $this->actingAs($user)->delete('/telegram/connect')->assertRedirect();
    expect($user->fresh())->telegram_id->toBeNull()->bot_can_message->toBeFalse();

    $this->actingAs($user->fresh())->post('/dashboard/settings/password-link');
    Queue::assertPushed(SendTelegramMessage::class, 1);
});

it('announces product news only to connected creators who kept updates on', function () {
    emailCreator(['telegram_id' => 555, 'bot_can_message' => true]);
    User::factory()->emailOnly()->create(['email' => 'muted@example.com', 'telegram_id' => 556, 'bot_can_message' => true, 'notify_product_updates' => false]);
    User::factory()->emailOnly()->create(['email' => 'paused@example.com', 'telegram_id' => 557, 'bot_can_message' => false]);
    User::factory()->emailOnly()->create(['email' => 'nobot@example.com']);

    $this->artisan('buildr:announce', ['message' => 'Share cards come in colors now.'])
        ->expectsOutputToContain('Would send to 1 creators')
        ->assertSuccessful();
    Queue::assertNothingPushed();

    $this->artisan('buildr:announce', ['message' => 'Share cards come in colors now.', '--send' => true])
        ->expectsOutputToContain('Queued 1 messages')
        ->assertSuccessful();

    expect(sentTo(555, 'Share cards come in colors now.'))->toBeTrue();
    Queue::assertPushed(SendTelegramMessage::class, 1);
});

it('prints a reset link for creators who never connected Telegram', function () {
    emailCreator();

    $this->artisan('buildr:reset-link', ['email' => 'Selam@example.com'])
        ->expectsOutputToContain('/reset-password/')
        ->assertSuccessful();

    $this->artisan('buildr:reset-link', ['email' => 'nobody@example.com'])->assertFailed();
});
