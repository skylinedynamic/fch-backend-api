<?php

namespace Database\Seeders;

/**
 * BUG-097: adds `manage credit profile requests` (Intake & Review › Request
 * Credit Profile, checked by the admin panel) and gives it to roles 1 and 2 -
 * the host's Developer and Admin - and to their users.
 *
 * Safe to run again. On an existing install:
 *   php artisan db:seed --class=CreditProfileRequestPermissionSeeder
 */
class CreditProfileRequestPermissionSeeder extends GiveHostRolesPermissionSeeder
{
    protected function definition(): array
    {
        return config('permissions.intake and review.permissions.manage_credit_profile_requests');
    }
}
