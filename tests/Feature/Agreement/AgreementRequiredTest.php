<?php

namespace Tests\Feature\Agreement;

use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementSignController;
use App\Models\Borrower\Borrower;
use App\Models\Common\AgreementTemplate;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\ManageAgreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The host can excuse one partner from the partner agreement, or one client
 * from their borrower agreement, while signing stays on for everyone else.
 * Everyone is required to sign by default; the global switch and the
 * templates' own on/off still apply on top.
 */
#[CoversClass(AgreementService::class)]
#[CoversClass(AgreementSignController::class)]
class AgreementRequiredTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();

        DB::table('app_configurations')->insert([
            'partner_id' => $this->host->id, 'module' => 'agreement', 'key' => 'agreement_signing_enabled', 'value' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // The host's partner agreement, and this partner's own borrower agreement.
        AgreementTemplate::create(['partner_id' => $this->host->id, 'slug' => 'partner', 'is_active' => true, 'template_html' => '<p>Partner terms</p>']);
        AgreementTemplate::create(['partner_id' => $this->partner->id, 'slug' => 'borrower', 'is_active' => true, 'template_html' => '<p>Borrower terms</p>']);
    }

    private function needsSign(User $user): bool
    {
        return (bool) app(AgreementService::class)->checkSignNeed($user)['needs_sign'];
    }

    private function setRequired(User $target, mixed $required): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/common/agreement-sign/required/{$target->id}", ['required' => $required]);
    }

    #[Test]
    public function everyone_is_required_to_sign_by_default(): void
    {
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $borrowerUser = $this->makeBorrowerUser($this->partner);

        $this->assertTrue($this->needsSign($partnerUser));
        $this->assertTrue($this->needsSign($borrowerUser));
        $this->assertTrue((bool) Partner::find($this->partner->id)->agreement_required);
        $this->assertTrue((bool) Borrower::find($borrowerUser->entity_id)->agreement_required);
    }

    #[Test]
    public function a_partner_excused_by_the_host_is_not_asked_to_sign(): void
    {
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $otherPartnerUser = $this->makePartnerAdmin($this->makeWhiteLabelPartner());
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->setRequired($partnerUser, false)->assertOk()->assertJsonPath('data.agreement_required', false);

        $this->assertFalse((bool) Partner::find($this->partner->id)->agreement_required);
        $this->assertFalse($this->needsSign($partnerUser));
        $this->assertTrue($this->needsSign($otherPartnerUser), 'other partners still sign');
    }

    #[Test]
    public function a_client_excused_by_the_host_is_not_asked_to_sign(): void
    {
        $excused = $this->makeBorrowerUser($this->partner);
        $neighbour = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->setRequired($excused, false)->assertOk();

        $this->assertFalse((bool) Borrower::find($excused->entity_id)->agreement_required);
        $this->assertFalse($this->needsSign($excused));
        $this->assertTrue($this->needsSign($neighbour), 'the same partner\'s other clients still sign');
    }

    #[Test]
    public function the_host_can_require_signing_again(): void
    {
        $borrowerUser = $this->makeBorrowerUser($this->partner, ['agreement_required' => false]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->setRequired($borrowerUser, true)->assertOk()->assertJsonPath('data.agreement_required', true);

        $this->assertTrue($this->needsSign($borrowerUser));
    }

    #[Test]
    public function switching_it_on_or_off_flags_that_user_to_refresh_their_portal(): void
    {
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $borrowerUser = $this->makeBorrowerUser($this->partner);
        $hostUser = $this->makePartnerAdmin($this->host);
        User::whereIn('id', [$partnerUser->id, $borrowerUser->id, $hostUser->id])->update(['is_refresh' => false]);
        $this->actingAsUser($hostUser);

        $this->setRequired($partnerUser, false)->assertOk();
        $this->assertTrue((bool) $partnerUser->fresh()->is_refresh);

        $partnerUser->update(['is_refresh' => false]);
        $this->setRequired($partnerUser, true)->assertOk();
        $this->assertTrue((bool) $partnerUser->fresh()->is_refresh);

        $this->setRequired($borrowerUser, false)->assertOk();
        $this->assertTrue((bool) $borrowerUser->fresh()->is_refresh);

        // Only the person switched is flagged, not the host doing it.
        $this->assertFalse((bool) $hostUser->fresh()->is_refresh);
    }

    #[Test]
    public function the_global_switch_and_the_templates_still_apply_to_someone_required(): void
    {
        $borrowerUser = $this->makeBorrowerUser($this->partner);

        AgreementTemplate::where('partner_id', $this->partner->id)->update(['is_active' => false]);
        $this->assertFalse($this->needsSign($borrowerUser), 'template off');

        AgreementTemplate::where('partner_id', $this->partner->id)->update(['is_active' => true]);
        DB::table('app_configurations')->where('key', 'agreement_signing_enabled')->update(['value' => '0']);
        $this->assertFalse($this->needsSign($borrowerUser), 'global switch off');
    }

    #[Test]
    public function only_the_hosts_team_can_change_it(): void
    {
        $target = $this->makeBorrowerUser($this->partner);

        foreach ([$this->makePartnerAdmin($this->partner), $this->makeStaffUser($this->partner)] as $notHost) {
            $this->actingAsUser($notHost);
            $this->setRequired($target, false)->assertNotFound();
        }
        // A borrower - the target themselves included - is stopped before it,
        // by the common routes' own guard.
        $this->actingAsUser($target);
        $this->setRequired($target, false)->assertForbidden();

        $this->assertTrue((bool) Borrower::find($target->entity_id)->agreement_required);
    }

    private function requirement(User $target): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/v1/common/agreement-sign/required/{$target->id}");
    }

    #[Test]
    public function the_host_sees_whether_it_can_be_changed_while_everything_is_on(): void
    {
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->requirement($partnerUser)->assertOk()->assertJson(['data' => [
            'user_id' => $partnerUser->id,
            'agreement_required' => true,
            'signing_enabled' => true,
            'template_active' => true,
            'can_change' => true,
            'locked_reason' => null,
        ]]);
    }

    #[Test]
    public function it_is_locked_while_signing_is_off_in_settings(): void
    {
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));
        DB::table('app_configurations')->where('key', 'agreement_signing_enabled')->update(['value' => '0']);

        $this->requirement($partnerUser)->assertOk()
            ->assertJsonPath('data.signing_enabled', false)
            ->assertJsonPath('data.can_change', false)
            ->assertJsonPath('data.locked_reason', __('agreement.required_locked_globally'));

        $this->setRequired($partnerUser, false)->assertStatus(422)
            ->assertJsonPath('message', __('agreement.required_locked_globally'));
        $this->assertTrue((bool) Partner::find($this->partner->id)->agreement_required);
    }

    #[Test]
    public function a_partner_is_locked_while_the_partner_agreement_is_off_in_settings(): void
    {
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $borrowerUser = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));
        AgreementTemplate::where('partner_id', $this->host->id)->where('slug', 'partner')->update(['is_active' => false]);

        $this->requirement($partnerUser)->assertOk()
            ->assertJsonPath('data.template_active', false)
            ->assertJsonPath('data.can_change', false)
            ->assertJsonPath('data.locked_reason', __('agreement.required_locked_template_partner'));
        $this->setRequired($partnerUser, false)->assertStatus(422);
        $this->assertTrue((bool) Partner::find($this->partner->id)->agreement_required);

        // The partner's own borrower agreement is still on: their clients can be changed.
        $this->requirement($borrowerUser)->assertOk()->assertJsonPath('data.can_change', true);
    }

    #[Test]
    public function a_client_is_locked_while_their_partners_borrower_agreement_is_off(): void
    {
        $borrowerUser = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));
        AgreementTemplate::where('partner_id', $this->partner->id)->where('slug', 'borrower')->update(['is_active' => false]);

        $this->requirement($borrowerUser)->assertOk()
            ->assertJsonPath('data.template_active', false)
            ->assertJsonPath('data.can_change', false)
            ->assertJsonPath('data.locked_reason', __('agreement.required_locked_template_borrower'));
        $this->setRequired($borrowerUser, false)->assertStatus(422);
        $this->assertTrue((bool) Borrower::find($borrowerUser->entity_id)->agreement_required);
    }

    #[Test]
    public function only_the_hosts_team_can_see_it(): void
    {
        $target = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->requirement($target)->assertNotFound();
    }

    #[Test]
    public function it_needs_a_yes_or_no(): void
    {
        $target = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->setRequired($target, null)->assertStatus(422)->assertJsonValidationErrors(['required']);
        $this->setRequired($target, 'maybe')->assertStatus(422)->assertJsonValidationErrors(['required']);
    }

    #[Test]
    public function an_unknown_user_is_not_found(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson('/api/v1/common/agreement-sign/required/999999', ['required' => false])->assertNotFound();
    }

    #[Test]
    public function the_partner_and_client_details_say_whether_signing_is_required(): void
    {
        $borrower = $this->makeBorrower($this->partner, ['agreement_required' => false]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/admin/partners-details/{$this->partner->id}")
            ->assertOk()
            ->assertJsonPath('data.agreement_required', true);
        $this->getJson("/api/v1/common/borrowers/{$borrower->id}")
            ->assertOk()
            ->assertJsonPath('data.agreement_required', false);
    }
}
