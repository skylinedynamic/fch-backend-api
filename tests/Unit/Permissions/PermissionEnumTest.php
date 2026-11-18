<?php

namespace Tests\Unit\Permissions;

use App\Enums\Permission;
use App\Enums\PermissionModule;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every permission is declared once, as a case of App\Enums\Permission, with
 * its module and who may hold it. config/permissions.php is built from the
 * enum (the seeders read that config), and code checks permissions through
 * the enum - never by writing the name as a string.
 */
class PermissionEnumTest extends TestCase
{
    /** The config as it was before the enum (2026-11-12), seeders depend on its shape. */
    private const SNAPSHOT = 'tests/Fixtures/Permissions/config-permissions.json';

    /** Code that must refer to permissions through the enum. */
    private const CHECKED_DIRS = ['app', 'routes'];

    #[Test]
    public function the_permissions_config_is_unchanged(): void
    {
        $snapshot = json_decode(file_get_contents(base_path(self::SNAPSHOT)), true);

        $this->assertSame($snapshot, config('permissions'));
    }

    #[Test]
    public function the_permissions_config_is_built_from_the_enum(): void
    {
        $this->assertSame(Permission::toConfig(), config('permissions'));
    }

    #[Test]
    public function each_permission_knows_its_module_and_who_may_hold_it(): void
    {
        $this->assertSame(PermissionModule::Partners, Permission::ManagePartnerConfiguration->module());
        $this->assertTrue(Permission::ManagePartnerConfiguration->isHost());
        $this->assertFalse(Permission::ManagePartnerConfiguration->isCertified());

        $this->assertSame(PermissionModule::Invoices, Permission::PayInvoice->module());
        $this->assertFalse(Permission::PayInvoice->isHost());
        $this->assertTrue(Permission::PayInvoice->isCertified());
        $this->assertFalse(Permission::PayInvoice->isDeveloper());

        $this->assertSame(PermissionModule::BusinessInsights, Permission::ViewBusinessInsights->module());
        $this->assertTrue(Permission::ViewBusinessInsights->isDeveloper());
    }

    #[Test]
    public function only_a_developer_only_permission_carries_the_is_developer_flag_in_the_config(): void
    {
        $config = config('permissions');

        $this->assertSame(1, $config['business insights']['permissions'][0]['is_developer']);
        $this->assertArrayNotHasKey('is_developer', $config['leads']['permissions'][0]);
    }

    #[Test]
    public function a_permission_gives_its_route_middleware(): void
    {
        $this->assertSame('permission:update clients', Permission::UpdateClients->middleware());
    }

    #[Test]
    public function no_permission_name_is_written_as_a_string_outside_the_enum(): void
    {
        $names = array_map(fn (Permission $p) => $p->value, Permission::cases());
        $pattern = '/([\'"])(?:permission:)?(' . implode('|', array_map(fn ($n) => preg_quote($n, '/'), $names)) . ')\1/';
        $enumDir = realpath(app_path('Enums'));

        $found = [];
        foreach (self::CHECKED_DIRS as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php' || str_starts_with($file->getRealPath(), $enumDir)) {
                    continue;
                }
                foreach (file($file->getRealPath()) as $i => $line) {
                    if (preg_match($pattern, $line, $m)) {
                        $found[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getRealPath()) . ':' . ($i + 1) . " '{$m[2]}'";
                    }
                }
            }
        }

        $this->assertSame([], $found, "Use App\\Enums\\Permission instead of the name:\n" . implode("\n", $found));
    }
}
