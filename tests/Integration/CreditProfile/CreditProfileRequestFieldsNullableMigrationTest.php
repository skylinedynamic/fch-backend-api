<?php

namespace Tests\Integration\CreditProfile;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A borrower's "Request update" opens a credit profile request even when they
 * never submitted the request form (the host saved their report directly). It
 * is built from their profile, which may not hold every detail, so the details
 * may be empty. The request form itself still requires them all.
 */
class CreditProfileRequestFieldsNullableMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_request_details_may_be_empty(): void
    {
        $columns = collect(Schema::getColumns('credit_profile_requests'))->keyBy('name');

        foreach (['first_name', 'last_name', 'address', 'city', 'state', 'zip', 'ssn', 'date_of_birth', 'phone', 'email'] as $name) {
            $this->assertTrue($columns[$name]['nullable'], "{$name} should be nullable");
        }

        // Who the request is for is never optional.
        $this->assertFalse($columns['borrower_id']['nullable']);
    }
}
