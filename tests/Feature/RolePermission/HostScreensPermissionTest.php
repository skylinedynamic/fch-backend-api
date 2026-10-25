<?php

namespace Tests\Feature\RolePermission;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect: BUG-125. A host staff user whose role only allows viewing leads could
 * open Lenders, Manage Tier, Verification Center, Credit Accounts and Capital
 * Access by URL and the API returned the data: those routes checked only that
 * the user was host staff, never the permission the menu item asks for.
 */
class HostScreensPermissionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    /** @return array<string, array{string, string, string}> method, url, permission */
    public static function hostScreens(): array
    {
        return [
            'lenders list' => ['GET', '/api/v1/admin/lenders', 'view lenders'],
            'lender create' => ['POST', '/api/v1/admin/lenders', 'create lenders'],
            'lender delete' => ['POST', '/api/v1/admin/lenders/multiple-delete', 'delete lenders'],
            'product categories' => ['GET', '/api/v1/admin/lenders/product-categories', 'view lenders'],
            'tiers list' => ['POST', '/api/v1/admin/credit-builder/tiers/fetch-tiers', 'view tier'],
            'tier create' => ['POST', '/api/v1/admin/credit-builder/tiers', 'update tier'],
            'tier tasks list' => ['POST', '/api/v1/admin/credit-builder/tier-tasks/fetch-tasks', 'view tier'],
            'credit accounts list' => ['POST', '/api/v1/admin/credit-builder/credit-accounts/fetch-accounts', 'view credit accounts'],
            'credit account create' => ['POST', '/api/v1/admin/credit-builder/credit-accounts', 'create credit accounts'],
            'verification center' => ['POST', '/api/v1/common/credit-builder/verification-center-fetch-all', 'view verification request'],
            'verification status update' => ['POST', '/api/v1/common/credit-builder/verification-center-status-update', 'update verification request'],
            'capital access list' => ['POST', '/api/v1/common/capital-accesses/fetch-all', 'view credit accounts'],
            'capital access create' => ['POST', '/api/v1/common/capital-accesses', 'create credit accounts'],
            'funding sequence list' => ['POST', '/api/v1/admin/funding-sequencings/fetch', 'view funding'],
            'funding sequence create' => ['POST', '/api/v1/admin/funding-sequencings', 'create funding'],
        ];
    }

    #[Test]
    #[DataProvider('hostScreens')]
    public function host_staff_with_only_view_leads_is_refused(string $method, string $url, string $permission): void
    {
        $this->actingAsUser($this->makeStaffUser($this->makeHostPartner(), ['view leads']));

        $this->json($method, $url)->assertForbidden();
    }

    #[Test]
    #[DataProvider('hostScreens')]
    public function host_staff_with_the_screens_permission_gets_past_the_guard(string $method, string $url, string $permission): void
    {
        $this->actingAsUser($this->makeStaffUser($this->makeHostPartner(), [$permission]));

        // Past the permission check: whatever the action answers (data, or a
        // validation error for an empty write), it is not a 403.
        $this->assertNotSame(403, $this->json($method, $url)->status(), $url);
    }

    #[Test]
    public function the_funding_sequence_form_still_loads_lenders_and_categories_with_view_funding(): void
    {
        $this->actingAsUser($this->makeStaffUser($this->makeHostPartner(), ['view funding']));

        $this->getJson('/api/v1/admin/lenders/get/all')->assertOk();
        $this->getJson('/api/v1/admin/lenders/product-categories')->assertOk();
        $this->getJson('/api/v1/admin/lenders')->assertForbidden();
    }
}
