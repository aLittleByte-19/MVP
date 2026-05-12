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
        User::query()->updateOrCreate(
            ['email' => env('FILAMENT_ADMIN_EMAIL', 'admin@nexum.local')],
            [
                'name' => env('FILAMENT_ADMIN_NAME', 'NEXUM Admin'),
                'password' => Hash::make(env('FILAMENT_ADMIN_PASSWORD', 'password')),
                'is_admin' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
