<?php

namespace Tests\Unit\Notification;

use App\Services\FCH\Common\Notification\NotificationTypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No database - which is the point: the unit suite's in-memory database has no
 * notification_types table, exactly like a production read that fails.
 */
#[CoversClass(NotificationTypeRegistry::class)]
class NotificationTypeRegistryTest extends TestCase
{
    #[Test]
    public function an_unreadable_overrides_table_falls_back_to_the_config_catalogue(): void
    {
        // Every email now passes through the registry on its way out. A broken
        // overrides table must cost the runtime overrides, not the emails.
        $type = app(NotificationTypeRegistry::class)->find('system.test');

        $this->assertNotNull($type);
        $this->assertSame('Test notification', $type->title);
    }
}
