<?php

use App\Models\Creator;
use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['buildr.telegram.bot_token' => '123456:TEST-TOKEN']);
    Queue::fake();
});

it('creates a creator account and sends them to onboarding', function () {
    $this->post('/register', ['name' => 'Selam Tesfaye', 'email' => 'Selam@Example.com', 'password' => 'long-password-1'])
        ->assertRedirect('/onboarding');

    $user = User::where('email', 'selam@example.com')->first();

    $this->assertAuthenticatedAs($user);
    expect($user->isAdmin())->toBeFalse()
        ->and(LoginEvent::where('user_id', $user->id)->where('method', 'register')->exists())->toBeTrue();
});

it('logs creators in with email and password', function () {
    $user = User::factory()->create(['email' => 'selam@example.com', 'password' => 'long-password-1']);
    Creator::factory()->for($user)->create();

    $this->post('/login', ['email' => 'selam@example.com', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post('/login', ['email' => 'SELAM@example.com', 'password' => 'long-password-1'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

it('never lets an admin skip 2FA through the creator login', function () {
    User::factory()->admin()->create(['email' => 'root@example.com', 'password' => 'long-password-1']);

    $this->post('/login', ['email' => 'root@example.com', 'password' => 'long-password-1'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('rejects duplicate emails and short passwords', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/register', ['name' => 'A', 'email' => 'taken@example.com', 'password' => 'long-password-1'])->assertSessionHasErrors('email');
    $this->post('/register', ['name' => 'A', 'email' => 'new@example.com', 'password' => 'short'])->assertSessionHasErrors('password');
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
