<?php

namespace Tests\Feature\Borrower;

use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateBorrowerRequest;
use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-027: a client on a paid plan was moved to the free plan through the
 * API (the form never offers it) and, moved back, ended up with two open
 * enrollment invoices - one it could never pay. A paid client can no longer
 * be moved to the free plan; free → paid and plain edits are unchanged.
 */
#[CoversClass(UpdateBorrowerRequest::class)]
class ClientPaidToFreeBlockedTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        AppConfiguration::create(['partner_id' => $this->host->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Host']);
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
        $this->actingAsUser($this->hostAdmin);
    }

    #[Test]
    public function a_paid_client_cannot_be_moved_to_the_free_plan(): void
    {
        $client = $this->makeBorrower($this->host, ['first_name' => 'QA-TEST R3', 'is_free_trail' => false]);

        $this->postJson("/api/v1/common/borrowers/{$client->id}", ['first_name' => 'QA-TEST R3', 'is_free_trail' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_free_trail' => __('borrower_validation.paid_to_free_not_allowed')]);

        $this->assertFalse((bool) $client->fresh()->is_free_trail);
    }

    #[Test]
    public function a_paid_client_saved_as_paid_still_saves(): void
    {
        $client = $this->makeBorrower($this->host, ['is_free_trail' => false]);

        $this->postJson("/api/v1/common/borrowers/{$client->id}", ['first_name' => 'Renamed', 'is_free_trail' => false])->assertOk();
        $this->postJson("/api/v1/common/borrowers/{$client->id}", ['first_name' => 'Renamed again'])->assertOk();

        $this->assertSame('Renamed again', $client->fresh()->first_name);
    }

    #[Test]
    public function a_free_client_saved_as_free_still_saves(): void
    {
        $client = $this->makeBorrower($this->host, ['is_free_trail' => true]);

        $this->postJson("/api/v1/common/borrowers/{$client->id}", ['first_name' => 'Still Free', 'is_free_trail' => true])->assertOk();

        $this->assertTrue((bool) $client->fresh()->is_free_trail);
    }

    #[Test]
    public function a_free_client_moving_to_paid_still_needs_its_enrollment(): void
    {
        $client = $this->makeBorrower($this->host, ['is_free_trail' => true]);

        $this->postJson("/api/v1/common/borrowers/{$client->id}", ['first_name' => 'Upgrading', 'is_free_trail' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enrollment')
            ->assertJsonMissingValidationErrors('is_free_trail');
    }
}
