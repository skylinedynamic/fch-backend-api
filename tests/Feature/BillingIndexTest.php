<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BillingIndexTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_billing_hot_paths_are_indexed(): void
    {
        $names = fn(string $table) => array_column(Schema::getIndexes($table), 'name');

        $this->assertContains('subscriptions_billing_cycle_index', $names('subscriptions'));
        $this->assertContains('invoices_subscription_status_index', $names('invoices'));
        $this->assertContains('invoices_status_index', $names('invoices'));
    }
}
