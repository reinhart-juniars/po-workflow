<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    foreach (['owner', 'admin', 'accounting', 'sales', 'production', 'delivery'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

it('allows owner to create a user with multiple roles', function () {
    $owner = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $owner->assignRole('owner');

    actingAs($owner);

    $response = from(route('ownerapp.users.index'))->post(route('ownerapp.users.store'), [
        'name' => 'Multi Role User',
        'email' => 'multi-role@example.com',
        'roles' => ['admin', 'delivery', 'production'],
        'is_active' => '1',
    ]);

    $response->assertRedirect(route('ownerapp.users.index'));

    $user = User::where('email', 'multi-role@example.com')->firstOrFail();

    expect($user->getRoleNames()->sort()->values()->all())
        ->toBe(['admin', 'delivery', 'production']);
});

it('rejects request when no role is selected', function () {
    $owner = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $owner->assignRole('owner');

    actingAs($owner);

    $response = from(route('ownerapp.users.index'))->post(route('ownerapp.users.store'), [
        'name' => 'Too Many Roles',
        'email' => 'too-many@example.com',
        'is_active' => '1',
    ]);

    $response
        ->assertRedirect(route('ownerapp.users.index'))
        ->assertSessionHasErrors('roles');
});

it('allows owner to update a user into multiple roles', function () {
    $owner = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $owner->assignRole('owner');

    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->assignRole('admin');

    actingAs($owner);

    $response = from(route('ownerapp.users.index'))->put(route('ownerapp.users.update', $user), [
        'roles' => ['accounting', 'production', 'delivery'],
    ]);

    $response->assertRedirect(route('ownerapp.users.index'));

    expect($user->fresh()->getRoleNames()->sort()->values()->all())
        ->toBe(['accounting', 'delivery', 'production']);
});

it('rejects combining owner with other roles', function () {
    $owner = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $owner->assignRole('owner');

    actingAs($owner);

    $response = from(route('ownerapp.users.index'))->post(route('ownerapp.users.store'), [
        'name' => 'Owner Campur',
        'email' => 'owner-campur@example.com',
        'roles' => ['owner', 'admin'],
        'is_active' => '1',
    ]);

    $response
        ->assertRedirect(route('ownerapp.users.index'))
        ->assertSessionHas('error', 'Role owner tidak boleh digabung dengan role lain.');
});

it('redirects multi-role users to the highest-priority dashboard', function () {
    $user = User::factory()->create([
        'force_password_change' => false,
        'is_active' => true,
    ]);
    $user->syncRoles(['admin', 'delivery']);

    actingAs($user);

    get('/dashboard')
        ->assertRedirect(route('adminapp.dashboard'));
});
