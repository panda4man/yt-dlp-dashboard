<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates admin user', function () {
    $this->artisan('admin:create admin@example.com password123')
        ->assertSuccessful()
        ->expectsOutput('Admin user created: admin@example.com');

    $user = User::where('email', 'admin@example.com')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and($user->name)->toBe('Admin');
});

it('updates password when user already exists', function () {
    User::factory()->create(['email' => 'admin@example.com']);

    $this->artisan('admin:create admin@example.com newpassword')
        ->assertSuccessful()
        ->expectsOutput('Admin user updated: admin@example.com');

    expect(Hash::check('newpassword', User::where('email', 'admin@example.com')->value('password')))->toBeTrue();
});
