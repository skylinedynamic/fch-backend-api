<?php

namespace Database\Seeders;

/**
 * Adds `manage partner configuration` (Partner profile › Configuration: the
 * host editing a partner's Email, SMS, Branding and Payment settings) and gives
 * it to roles 1 and 2 - the host's Developer and Admin - and to their users.
 *
 * Safe to run again. On an existing install:
 *   php artisan db:seed --class=PartnerConfigurationPermissionSeeder
 */
class PartnerConfigurationPermissionSeeder extends GiveHostRolesPermissionSeeder
{
    protected function definition(): array
    {
        return config('permissions.partners.permissions.manage_configuration');
    }
}
