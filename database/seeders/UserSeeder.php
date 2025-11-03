<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Admin User
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@fch.com',
            'phone_number' => '+1234567890',
            'user_type' => User::ADMIN,
            'password' => Hash::make('12345678'),
            'email_verified_at' => now(),
        ]);

        // Create Partner Users
        User::create([
            'name' => 'John Partner',
            'email' => 'john.partner@example.com',
            'phone_number' => '+1234567891',
            'user_type' => User::PARTNER,
            'password' => Hash::make('12345678'),
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'Sarah Wilson',
            'email' => 'sarah.wilson@example.com',
            'phone_number' => '+1234567892',
            'user_type' => User::PARTNER,
            'password' => Hash::make('12345678'),
            'email_verified_at' => now(),
        ]);

        // Create Borrower Users
        User::create([
            'name' => 'Michael Johnson',
            'email' => 'michael.johnson@example.com',
            'phone_number' => '+1234567893',
            'user_type' => User::BORROWER,
            'password' => Hash::make('12345678'),
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'Emily Davis',
            'email' => 'emily.davis@example.com',
            'phone_number' => '+1234567894',
            'user_type' => User::BORROWER,
            'password' => Hash::make('12345678'),
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'Robert Brown',
            'email' => 'robert.brown@example.com',
            'phone_number' => '+1234567895',
            'user_type' => User::BORROWER,
            'password' => Hash::make('12345678'),
            'email_verified_at' => now(),
        ]);
    }
}