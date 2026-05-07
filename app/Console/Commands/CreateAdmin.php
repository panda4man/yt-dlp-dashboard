<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature   = 'admin:create {email} {password}';
    protected $description = 'Create or update the admin user';

    public function handle(): int
    {
        $email    = $this->argument('email');
        $password = $this->argument('password');
        $user     = User::where('email', $email)->first();

        if ($user) {
            $user->update(['password' => Hash::make($password)]);
            $this->info("Admin user updated: {$email}");
        } else {
            User::create([
                'name'     => 'Admin',
                'email'    => $email,
                'password' => Hash::make($password),
            ]);
            $this->info("Admin user created: {$email}");
        }

        return Command::SUCCESS;
    }
}
