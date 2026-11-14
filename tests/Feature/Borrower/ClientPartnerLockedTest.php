<?php

namespace Tests\Feature\Borrower;

use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateBorrowerRequest;
use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
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
 * R3-BUG-024: a partner moved its client to another partner by sending
 * partner_id on the client update. Decision (2026-11-15): once created, a
 * client's partner never changes - not by the partner, not by the host.
 * Sending the client's own partner_id (the edit form does) still saves.
 */
#[CoversClass(UpdateBorrowerRequest::class)]
#[CoversClass(BorrowerService::class)]
class ClientPartnerLockedTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $wanda;
    private Partner $other;
    private Borrower $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        AppConfiguration::create(['partner_id' => $this->host->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Host']);
        $this->wanda = $this->makeWhiteLabelPartner();
        $this->other = $this->makeCertifiedPartner();
        $this->client = $this->makeBorrower($this->wanda, ['first_name' => 'QA-TEST R2 PP-CLI-02']);
    }

    private function moveAs(User $user, int $partnerId)
    {
        $this->actingAsUser($user);

        return $this->postJson("/api/v1/common/borrowers/{$this->client->id}", [
            'first_name' => 'QA-TEST R2 PP-CLI-02',
            'partner_id' => $partnerId,
        ]);
    }

    #[Test]
    public function a_partner_cannot_move_its_client_to_another_partner(): void
    {
        $this->moveAs($this->makePartnerAdmin($this->wanda), $this->other->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['partner_id' => __('borrower_validation.partner_id_locked')]);

        $this->assertSame($this->wanda->id, $this->client->fresh()->partner_id);
    }

    #[Test]
    public function the_host_cannot_move_a_client_either(): void
    {
        $this->moveAs($this->makePartnerAdmin($this->host), $this->other->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('partner_id');

        $this->assertSame($this->wanda->id, $this->client->fresh()->partner_id);
    }

    #[Test]
    public function sending_the_clients_own_partner_still_saves(): void
    {
        $this->moveAs($this->makePartnerAdmin($this->wanda), $this->wanda->id)->assertOk();
        $this->moveAs($this->makePartnerAdmin($this->host), $this->wanda->id)->assertOk();

        $this->assertSame($this->wanda->id, $this->client->fresh()->partner_id);
    }

    /** Only the partner is fixed: the host's team still edits another partner's client. */
    #[Test]
    public function the_host_and_host_staff_still_edit_another_partners_client(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));
        $this->postJson("/api/v1/common/borrowers/{$this->client->id}", [
            'first_name' => 'Host Edit',
            'partner_id' => $this->wanda->id,
        ])->assertOk();
        $this->assertSame('Host Edit', $this->client->fresh()->first_name);

        $this->actingAsUser($this->makeStaffUser($this->host));
        $this->postJson("/api/v1/common/borrowers/{$this->client->id}", ['first_name' => 'Staff Edit'])->assertOk();
        $this->assertSame('Staff Edit', $this->client->fresh()->first_name);
        $this->assertSame($this->wanda->id, $this->client->fresh()->partner_id);
    }

    #[Test]
    public function an_edit_without_a_partner_still_saves(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->wanda));

        $this->postJson("/api/v1/common/borrowers/{$this->client->id}", ['first_name' => 'Renamed'])->assertOk();

        $this->assertSame('Renamed', $this->client->fresh()->first_name);
        $this->assertSame($this->wanda->id, $this->client->fresh()->partner_id);
    }

    #[Test]
    public function the_service_never_writes_a_new_partner(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        app(BorrowerService::class)->update($this->client, ['first_name' => 'Direct', 'partner_id' => $this->other->id]);

        $this->assertSame('Direct', $this->client->fresh()->first_name);
        $this->assertSame($this->wanda->id, $this->client->fresh()->partner_id);
    }
}
