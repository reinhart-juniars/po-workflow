<?php

use App\Models\User;
use Illuminate\Auth\SessionGuard;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticated;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

test('login screen can be rendered', function () {
    $response = get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    /** @var User $user */
    $user = User::factory()->create();

    $response = post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can be remembered across sessions when remember me is checked', function () {
    /** @var User $user */
    $user = User::factory()->create([
        'remember_token' => null,
    ]);

    $response = post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => 'on',
    ]);

    assertAuthenticated();
    expect($user->fresh()->getRememberToken())->not->toBeNull();
    $response->assertCookie('remember_web_'.sha1(SessionGuard::class));
});

test('users can not authenticate with invalid password', function () {
    /** @var User $user */
    $user = User::factory()->create();

    post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    assertGuest();
});

test('users can logout', function () {
    /** @var User $user */
    $user = User::factory()->create();

    actingAs($user);

    $response = post('/logout');

    assertGuest();
    $response->assertRedirect('/');
});
