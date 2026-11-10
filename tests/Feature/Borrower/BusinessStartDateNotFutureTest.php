<?php

namespace Tests\Feature\Borrower;

use App\Models\Borrower\Borrower;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-007: the admin's Update Client said success for a business start date in
 * the future and saved it - but the date picker cannot show a date after
 * today, so it read as empty after reload, and the next save cleared it. A
 * future date is now refused with a field error.
 */
class BusinessStartDateNotFutureTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    #[Test]
    public function the_admin_cannot_save_a_business_start_date_in_the_future(): void
    {
        Mail::fake();
        $host = $this->makeHostPartner();
        $client = $this->makeBorrower($host, ['business_start_date' => '2020-01-01']);
        $this->actingAsUser($this->makePartnerAdmin($host));
        $update = fn (string $date) => $this->postJson("/api/v1/common/borrowers/{$client->id}", [
            'first_name' => 'Bo', 'last_name' => 'Rower', 'email' => $client->email, 'business_start_date' => $date,
        ]);

        $update('2027-01-01')->assertUnprocessable()->assertJsonValidationErrors(['business_start_date']);
        $this->assertSame('2020-01-01', Borrower::find($client->id)->getRawOriginal('business_start_date'));

        // A past date still saves.
        $update('2021-06-15')->assertOk();
        $this->assertSame('2021-06-15', Borrower::find($client->id)->getRawOriginal('business_start_date'));
    }
}
