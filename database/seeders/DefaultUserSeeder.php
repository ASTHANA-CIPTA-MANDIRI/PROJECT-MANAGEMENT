<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DefaultUserSeeder extends Seeder
{
    /**
     * Seed the initial administrator.
     *
     * Idempotent: keyed on the email it actually creates (the previous version
     * checked a different address and, since the users.email unique constraint
     * was dropped, produced duplicate admins on re-seed).
     *
     * The password comes from SEED_ADMIN_PASSWORD; when unset a strong random
     * one is generated and printed once, so no weak hard-coded credential
     * ('123') ever ships.
     */
    public function run(): void
    {
        $email = env('SEED_ADMIN_EMAIL', 'admin@example.com');
        $password = env('SEED_ADMIN_PASSWORD');
        $generated = false;

        if (! $password) {
            $password = Str::random(20);
            $generated = true;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Administrator',
                'password' => bcrypt($password),
                'email_verified_at' => now(),
            ]
        );

        if ($user->wasRecentlyCreated) {
            $user->creation_token = null;
            $user->save();

            if ($generated && $this->command) {
                $this->command->warn("Seeded admin [{$email}] with a generated password: {$password}");
                $this->command->warn('Save it now and change it after the first login.');
            }
        }
    }
}
