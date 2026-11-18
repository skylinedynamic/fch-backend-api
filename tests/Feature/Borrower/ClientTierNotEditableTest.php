<?php

namespace Tests\Feature\Borrower;

use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateBorrowerRequest;
use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\CreditBuilder\Tier;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Borrower\BorrowerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * A client's tier moves only by verifying its tasks (TaskVerificationService /
 * VerificationCenterService). The client update accepted current_tier and
 * saved it, so anyone allowed to edit the client could move it past Tier 0
 * without its tasks - and Capital Access, which counts leaving Tier 0 as the
 * EIN Consistency & Identity Verification task done, then showed "EIN
 * Verified" complete (R3-BUG-035). Whatever current_tier is sent, it is not
 * saved; the rest of the update still is.
 */
#[CoversClass(UpdateBorrowerRequest::class)]
#[CoversClass(BorrowerService::class)]
class ClientTierNotEditableTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private Tier $tier0;
    private Tier $tier1;
    private Borrower $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        AppConfiguration::create(['partner_id' => $this->host->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Host']);
        $this->partner = $this->makeWhiteLabelPartner();
        $this->tier0 = Tier::create(['name' => 'Tier 0', 'title' => 'Foundation', 'code' => 'T0', 'order' => 1]);
        $this->tier1 = Tier::create(['name' => 'Tier 1', 'title' => 'Starter', 'code' => 'T1', 'order' => 2]);
        $this->client = $this->makeBorrower($this->partner, ['first_name' => 'QA-TEST R3 Tier', 'current_tier' => $this->tier0->id]);
    }

    private function updateAs(User $user, array $data)
    {
        $this->actingAsUser($user);

        return $this->postJson("/api/v1/common/borrowers/{$this->client->id}", array_merge(['first_name' => 'QA-TEST R3 Tier'], $data));
    }

    #[Test]
    public function the_host_cannot_move_a_client_to_another_tier(): void
    {
        $this->updateAs($this->makePartnerAdmin($this->host), ['current_tier' => $this->tier1->id])->assertOk();

        $this->assertSame($this->tier0->id, (int) $this->client->fresh()->current_tier);
    }

    #[Test]
    public function the_partner_cannot_move_its_client_to_another_tier(): void
    {
        $this->updateAs($this->makePartnerAdmin($this->partner), ['current_tier' => $this->tier1->id])->assertOk();

        $this->assertSame($this->tier0->id, (int) $this->client->fresh()->current_tier);
    }

    #[Test]
    public function an_empty_tier_does_not_clear_it_either(): void
    {
        $this->updateAs($this->makePartnerAdmin($this->host), ['current_tier' => null])->assertOk();

        $this->assertSame($this->tier0->id, (int) $this->client->fresh()->current_tier);
    }

    #[Test]
    public function the_rest_of_the_update_still_saves(): void
    {
        $this->updateAs($this->makePartnerAdmin($this->host), ['first_name' => 'Renamed', 'current_tier' => $this->tier1->id])->assertOk();

        $client = $this->client->fresh();
        $this->assertSame('Renamed', $client->first_name);
        $this->assertSame($this->tier0->id, (int) $client->current_tier);
    }
}
