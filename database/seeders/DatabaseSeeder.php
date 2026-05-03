<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Direct insert (not factory) so the seeder runs under
        // composer install --no-dev, where fakerphp/faker is absent
        // and UserFactory::definition()'s fake() calls would crash.
        //
        // The (None, None) template ships no auth UI, so this user
        // exists only as a placeholder for code that calls
        // auth()->user() in tests or via Tinker. We still source the
        // credentials from the same env-var chain as the auth-shipping
        // templates so a future addition of a login surface picks up
        // the right values without changing this file:
        //   1. INITIAL_USER_EMAIL / INITIAL_USER_PASSWORD - production
        //      App Spec env (visible to user code at runtime).
        //   2. MAKERLOFT_USER_EMAIL / MAKERLOFT_USER_PASSWORD - preview
        //      orchestrator env (stripped before php-fpm starts).
        //   3. Hardcoded test@example.com / 'password' fallback.
        $email = (string) (env('INITIAL_USER_EMAIL') ?? env('MAKERLOFT_USER_EMAIL', 'test@example.com'));
        $name = (string) env('MAKERLOFT_USER_NAME', 'Test User');
        $password = (string) (env('INITIAL_USER_PASSWORD') ?? env('MAKERLOFT_USER_PASSWORD', 'password'));

        User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );
    }
}
