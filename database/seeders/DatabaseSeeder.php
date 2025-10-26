<?php

namespace Database\Seeders;

use App\Models\User\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'admin@mail.com',
            'password' => '12345678', 
            'phone_number' => '08123456789',
            'user_type' => User::USERTYPE['Admin'],
        ]);
    }
}
