<?php

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

it('renders the admin login screen', function () {
    $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/login'));
});

it('logs admins in and records the login', function () {
    $admin = User::factory()->admin()->create(['email' => 'boss@example.com']);

    $this->post('/admin/login', ['email' => 'boss@example.com', 'password' => 'password'])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($admin);
    expect(LoginEvent::where('user_id', $admin->id)->where('method', 'password')->exists())->toBeTrue();
});

it('sends admins with 2FA to the challenge', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create(['email' => 'boss@example.com']);

    $this->post('/admin/login', ['email' => 'boss@example.com', 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $admin->id);

    $this->assertGuest();
    $this->get('/admin/two-factor-challenge')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-challenge'));
});

it('never lets creators or banned admins use the password login', function () {
    User::factory()->create(['email' => 'creator@example.com', 'password' => 'password']);
    User::factory()->admin()->banned()->create(['email' => 'gone@example.com']);

    $this->post('/admin/login', ['email' => 'creator@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    $this->post('/admin/login', ['email' => 'gone@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('rate limits admin login attempts', function () {
    $admin = User::factory()->admin()->create(['email' => 'boss@example.com']);
    RateLimiter::increment(md5('login'.implode('|', ['boss@example.com', '127.0.0.1'])), amount: 5);

    $this->post('/admin/login', ['email' => 'boss@example.com', 'password' => 'wrong'])->assertTooManyRequests();
});

it('lets admins log out', function () {
    $this->actingAs(User::factory()->admin()->create())->post('/admin/logout')->assertRedirect('/');
    $this->assertGuest();
});

it('shows the security page after password confirmation and updates the password', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/security')->assertRedirect(route('password.confirm'));

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->get('/admin/security')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/security')->where('mustSetUp', true));

    $this->put('/admin/security/password', [
        'current_password' => 'password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertRedirect();

    expect(Hash::check('new-password-123', $admin->fresh()->password))->toBeTrue();

    $this->put('/admin/security/password', [
        'current_password' => 'wrong',
        'password' => 'x-password-456',
        'password_confirmation' => 'x-password-456',
    ])->assertSessionHasErrors('current_password');
})->skip(fn () => ! Features::canManageTwoFactorAuthentication(), '2FA disabled');

it('creates admins only from the console', function () {
    $this->artisan('buildr:make-admin', ['email' => 'Root@Example.com'])
        ->expectsQuestion('Password (min 12 characters)', 'a-very-long-password')
        ->assertSuccessful();

    $admin = User::where('email', 'root@example.com')->first();

    expect($admin->isAdmin())->toBeTrue()
        ->and(Hash::check('a-very-long-password', $admin->password))->toBeTrue();

    $this->get('/register')->assertNotFound();
});

it('generates a password when there is no terminal to type one', function () {
    $this->artisan('buildr:make-admin', ['email' => 'ops@example.com', '--no-interaction' => true])
        ->expectsOutputToContain('Password (shown once):')
        ->assertSuccessful();

    expect(User::where('email', 'ops@example.com')->first()?->isAdmin())->toBeTrue();
});
