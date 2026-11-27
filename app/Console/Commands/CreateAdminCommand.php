<?php

namespace App\Console\Commands;

use App\Services\UserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates an administrator. The way into a fresh production install: the panel has no sign-up,
 * every other user is created by an admin, and the seeder's `test@example.com` / `password`
 * account must never exist on a reachable host.
 *
 * Asks for whatever the options leave out. `--password` exists for provisioning scripts, but it
 * lands in the shell history and the process list, so interactive use should leave it to the
 * prompt.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'users:create-admin
        {--name= : Display name}
        {--email= : Login email}
        {--password= : Password (prompted for when omitted)}';

    protected $description = 'Create an administrator account';

    public function handle(UserService $users): int
    {
        $input = [
            'name' => $this->option('name') ?: text('Name', default: 'Admin', required: true),
            'email' => $this->option('email') ?: text('Email', required: true),
            'password' => $this->option('password') ?: password('Password (8+ characters)', required: true),
        ];

        // The same rules the panel's StoreUserData applies, so an account made here is one the
        // panel could have made.
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = $users->create([...$input, 'is_admin' => true]);

        $this->info("Admin {$user->email} created.");

        return self::SUCCESS;
    }
}
