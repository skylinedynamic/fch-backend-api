<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1) Link Admin to Host Partner via polymorphic mapping
        $hostPartner = DB::table('partners')->where('is_host', true)->first();
        User::updateOrCreate(
            ['email' => 'craig@fourcornerfunding.com'],
            [
                'name' => $hostPartner?->full_name ?: $hostPartner?->company_name,
                'phone_number' => $hostPartner?->phone_number,
                'user_type' => User::ADMIN,
                'password' => Hash::make('12345678'),
                'status' => User::STATUS['active'],
                'email_verified_at' => now(),
                'partner_id' => $hostPartner?->id,
                'entity_type' => Partner::class,
                'entity_id' => $hostPartner?->id,
            ]
        );

        // 1.1) Create Developer user linked to Host Partner
        User::updateOrCreate(
            ['email' => 'developer@fch.com'],
            [
                'name' => 'Developer',
                'phone_number' => $hostPartner?->phone_number,
                'user_type' => User::ADMIN,
                'password' => Hash::make('12345678'),
                'status' => User::STATUS['active'],
                'email_verified_at' => now(),
                'partner_id' => $hostPartner?->id,
                'entity_type' => Partner::class,
                'entity_id' => $hostPartner?->id,
            ]
        );

        // 2) Create Partner users for non-host partners
        $partners = DB::table('partners')->where('is_host', false)->get();
        foreach ($partners as $p) {
            $name = $p->full_name ?: $p->company_name;
            $email = $p->email;
            $phone = $p->phone_number;

            if (!$email) {
                // Skip if no usable email
                continue;
            }

            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name ?? ('Partner #' . $p->id),
                    'phone_number' => $phone,
                    'user_type' => User::PARTNER,
                    'password' => Hash::make('12345678'),
                    'status' => User::STATUS['active'],
                    'email_verified_at' => now(),
                    'partner_id' => $p->id,
                    'entity_type' => Partner::class,
                    'entity_id' => $p->id,
                ]
            );
        }

        // 3) Create Borrower users linked to borrowers under partners
        $borrowers = DB::table('borrowers')->get();
        foreach ($borrowers as $b) {
            $email = $b->email;
            if (!$email) {
                // Ensure a unique placeholder if missing
                $email = 'borrower_' . $b->id . '@example.com';
            }

            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $b->first_name . ' ' . $b->last_name ?? ('Borrower #' . $b->id),
                    'phone_number' => $b->phone_number,
                    'user_type' => User::BORROWER,
                    'password' => Hash::make('12345678'),
                    'status' => User::STATUS['active'],
                    'email_verified_at' => now(),
                    'partner_id' => $b->partner_id,
                    'entity_type' => Borrower::class,
                    'entity_id' => $b->id,
                ]
            );
        }

       

        // 5) Add 10 generic users per partner (idempotent)
        // $partners = DB::table('partners')->get();
        // foreach ($partners as $p) {
        //     for ($i = 1; $i <= 10; $i++) {
        //         $email = "user{$i}.partner{$p->id}@example.test";
        //         $name = "Partner {$p->id} User {$i}";
        //         $phone = $p->phone_number ?? ('555' . str_pad($p->id . $i, 7, '0', STR_PAD_LEFT));

        //         User::updateOrCreate(
        //             ['email' => $email],
        //             [
        //                 'name' => $name,
        //                 'phone_number' => $phone,
        //                 'user_type' => $p->is_host ? User::ADMIN : User::PARTNER,
        //                 'password' => Hash::make('12345678'),
        //                 'status' => User::STATUS['active'],
        //                 'email_verified_at' => now(),
        //                 'partner_id' => $p->id,
        //                 'entity_type' => null,
        //                 'entity_id' => null,
        //             ]
        //         );
        //     }
        // }
    }
}