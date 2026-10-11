<?php

namespace Tests\Feature\Setting;

use App\Models\Common\Role;
use App\Models\Partner\Partner;
use Database\Seeders\SmsConfigurationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SMS configuration belongs to the partners that run their own branded
 * platform: the host and white-label partners. Certified partners send through
 * the host's account and get no settings screen for it.
 */
class SmsConfigurationPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'update sms configuration settings';

    private function adminRoleFor(bool $isHost, string $type): Role
    {
        $partner = Partner::create([
            'is_host' => $isHost,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => $type,
            'company_name' => $type . uniqid(),
            'full_name' => 'Owner',
            'email' => uniqid() . '@example.test',
        ]);

        return Role::create(['name' => 'Admin', 'partner_id' => $partner->id]);
    }

    #[Test]
    public function host_and_white_label_admins_get_the_permission_and_certified_admins_do_not(): void
    {
        $host = $this->adminRoleFor(true, Partner::PARTNER_TYPE_CERTIFIED);
        $whiteLabel = $this->adminRoleFor(false, Partner::PARTNER_TYPE_WHITE_LABEL);
        $certified = $this->adminRoleFor(false, Partner::PARTNER_TYPE_CERTIFIED);

        $this->seed(SmsConfigurationPermissionSeeder::class);

        $this->assertTrue($host->fresh()->hasPermissionTo(self::PERMISSION));
        $this->assertTrue($whiteLabel->fresh()->hasPermissionTo(self::PERMISSION));
        $this->assertFalse($certified->fresh()->hasPermissionTo(self::PERMISSION));
    }

    #[Test]
    public function running_it_twice_changes_nothing(): void
    {
        $role = $this->adminRoleFor(false, Partner::PARTNER_TYPE_WHITE_LABEL);

        $this->seed(SmsConfigurationPermissionSeeder::class);
        $this->seed(SmsConfigurationPermissionSeeder::class);

        $this->assertSame(1, $role->fresh()->permissions()->where('name', self::PERMISSION)->count());
    }
}
