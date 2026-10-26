<?php

use App\Models\Creator;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Telegram\TelegramLoginVerifier;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['buildr.telegram.bot_token' => '123456:TEST-TOKEN']);
    Queue::fake();
});

function signedPayload(array $overrides = []): array
{
    $payload = array_merge([
        'id' => 424242,
        'first_name' => 'Selam',
        'last_name' => 'Tesfaye',
        'username' => 'SelamBuilds',
        'photo_url' => 'https://t.me/i/userpic/320/selam.jpg',
        'auth_date' => time(),
    ], $overrides);

    $payload['hash'] = app(TelegramLoginVerifier::class)->sign($payload, '123456:TEST-TOKEN');

    return $payload;
}

it('verifies a correctly signed widget payload', function () {
    expect(app(TelegramLoginVerifier::class)->verify(signedPayload()))->toBeTrue();
});

it('rejects tampered, unsigned and stale payloads', function () {
    $verifier = app(TelegramLoginVerifier::class);

    $tampered = signedPayload();
    $tampered['id'] = 1;

    $stale = signedPayload(['auth_date' => time() - 90_000]);

    $unsigned = signedPayload();
    unset($unsigned['hash']);

    expect($verifier->verify($tampered))->toBeFalse()
        ->and($verifier->verify($stale))->toBeFalse()
        ->and($verifier->verify($unsigned))->toBeFalse()
        ->and($verifier->verify(signedPayload(), 'other-token'))->toBeFalse();
});

it('logs a new creator in and sends them to onboarding', function () {
    $this->post('/auth/telegram/callback', signedPayload())
        ->assertRedirect('/onboarding');

    $user = User::where('telegram_id', 424242)->first();

    $this->assertAuthenticatedAs($user);
    expect($user->telegram_username)->toBe('selambuilds')
        ->and($user->name)->toBe('Selam Tesfaye')
        ->and(LoginEvent::where('user_id', $user->id)->where('method', 'telegram')->exists())->toBeTrue();
});

it('sends returning creators to the dashboard', function () {
    $user = User::factory()->create(['telegram_id' => 424242]);
    Creator::factory()->for($user)->create();

    $this->post('/auth/telegram/callback', signedPayload())->assertRedirect('/dashboard');
});

it('refuses invalid payloads', function () {
    $payload = signedPayload();
    $payload['hash'] = str_repeat('0', 64);

    $this->post('/auth/telegram/callback', $payload)->assertSessionHasErrors('telegram');
    $this->assertGuest();
});

it('logs banned users out on their next request', function () {
    $user = User::factory()->banned('Impersonation')->create();

    $this->actingAs($user)->get('/dashboard')->assertStatus(403);
    $this->assertGuest();
});

it('redirects guests to the right login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('handles bot webhook updates with the secret token only', function () {
    config(['buildr.telegram.webhook_secret' => 'shh']);
    $user = User::factory()->create(['telegram_id' => 777, 'bot_can_message' => false]);

    $update = ['message' => ['text' => '/start', 'chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777]]];

    $this->postJson('/telegram/webhook', $update)->assertForbidden();
    $this->postJson('/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'shh'])->assertOk();

    expect($user->refresh()->bot_can_message)->toBeTrue();

    $blocked = ['my_chat_member' => ['chat' => ['type' => 'private'], 'from' => ['id' => 777], 'new_chat_member' => ['status' => 'kicked']]];
    $this->postJson('/telegram/webhook', $blocked, ['X-Telegram-Bot-Api-Secret-Token' => 'shh'])->assertOk();

    expect($user->refresh()->bot_can_message)->toBeFalse();
});
