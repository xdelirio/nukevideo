<?php

/**
 * `users:create-admin` is the way into a fresh production install: the panel has no sign-up, and
 * the seeder's admin has a password printed in the README.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates an admin with a default project', function () {
    $this->artisan('users:create-admin', [
        '--name' => 'Ops',
        '--email' => 'ops@example.com',
        '--password' => 'correct-horse',
    ])->assertSuccessful();

    $user = User::where('email', 'ops@example.com')->firstOrFail();

    expect($user->is_admin)->toBeTrue()
        ->and(Hash::check('correct-horse', $user->password))->toBeTrue()
        ->and($user->projects()->count())->toBe(1);
});

it('refuses a short password or a taken email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('users:create-admin', ['--name' => 'A', '--email' => 'new@example.com', '--password' => 'short'])
        ->assertFailed();
    $this->artisan('users:create-admin', ['--name' => 'A', '--email' => 'taken@example.com', '--password' => 'long-enough'])
        ->assertFailed();

    expect(User::where('email', 'new@example.com')->exists())->toBeFalse();
});
