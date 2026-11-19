<?php

namespace Tests\Feature\Agreement;

use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementSignController;
use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateController;
use App\Models\Borrower\Borrower;
use App\Models\Common\AgreementTemplate;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Services\FCH\Common\ManageAgreement\AgreementPreviewSigners;
use App\Services\FCH\Common\ManageAgreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Agreement Builder › Preview as Partner / Preview as Borrower: the preview is
 * shown as one real signer would see it. The builder lists who signs the
 * agreement being edited (the host's partners for the partner agreement, the
 * owner's own clients for the borrower agreement) and fills the preview with
 * the chosen one's details - the values signing fills it with.
 *
 * A partner's / client's Agreement tab previews the active agreement they have
 * not signed yet, filled with their details.
 */
#[CoversClass(AgreementTemplateController::class)]
#[CoversClass(AgreementSignController::class)]
#[CoversClass(AgreementPreviewSigners::class)]
#[CoversClass(AgreementService::class)]
class AgreementPreviewAsSignerTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner([
            'company_name' => 'Acme Lending',
            'full_name' => 'Ada Partner',
            'email' => 'ada@acme.test',
        ]);
    }

    private function signers(array $query): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/common/agreement-templates/preview-signers?' . http_build_query($query));
    }

    /* ── Signer list ──────────────────────────────────────────────────────── */

    #[Test]
    public function the_host_lists_its_partners_for_the_partner_agreement(): void
    {
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $this->makeBorrowerUser($this->host);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $rows = $this->signers(['type' => 'partner'])->assertOk()->json('data');

        $this->assertSame([$partnerUser->id], array_column($rows, 'user_id'));
        $this->assertSame('Ada Partner', $rows[0]['name']);
        $this->assertSame('ada@acme.test', $rows[0]['email']);
        $this->assertSame('Acme Lending', $rows[0]['business_name']);
    }

    #[Test]
    public function the_borrower_agreement_lists_only_the_owners_own_clients(): void
    {
        $own = $this->makeBorrowerUser($this->partner, ['first_name' => 'Cleo', 'last_name' => 'Client']);
        $this->makeBorrowerUser($this->makeWhiteLabelPartner());
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $rows = $this->signers(['type' => 'borrower'])->assertOk()->json('data');

        $this->assertSame([$own->id], array_column($rows, 'user_id'));
        $this->assertSame('Cleo Client', $rows[0]['name']);
    }

    #[Test]
    public function a_partner_that_is_not_the_host_has_nobody_signing_its_partner_agreement(): void
    {
        $this->makePartnerAdmin($this->makeWhiteLabelPartner());
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->assertSame([], $this->signers(['type' => 'partner'])->assertOk()->json('data'));
    }

    #[Test]
    public function the_list_can_be_searched_by_name_or_email(): void
    {
        $this->makeBorrowerUser($this->partner, ['first_name' => 'Cleo', 'last_name' => 'Client']);
        $match = $this->makeBorrowerUser($this->partner, ['first_name' => 'Dan', 'last_name' => 'Doe', 'email' => 'dan@doe.test']);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->assertSame([$match->id], array_column($this->signers(['type' => 'borrower', 'search' => 'doe'])->json('data'), 'user_id'));
        $this->assertSame([$match->id], array_column($this->signers(['type' => 'borrower', 'search' => 'dan@'])->json('data'), 'user_id'));
    }

    #[Test]
    public function the_host_lists_the_clients_of_the_partner_it_configures(): void
    {
        $client = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host, ['manage partner configuration']));

        $rows = $this->signers(['type' => 'borrower', 'partner_id' => $this->partner->id])->assertOk()->json('data');

        $this->assertSame([$client->id], array_column($rows, 'user_id'));
    }

    #[Test]
    public function another_partners_clients_need_the_host_permission(): void
    {
        $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->signers(['type' => 'borrower', 'partner_id' => $this->partner->id])->assertForbidden();
    }

    #[Test]
    public function the_host_lists_every_partners_clients_with_their_partner(): void
    {
        $own = $this->makeBorrowerUser($this->host, ['first_name' => 'Hal']);
        $theirs = $this->makeBorrowerUser($this->partner, ['first_name' => 'Cleo']);
        $other = $this->makeBorrowerUser($this->makeWhiteLabelPartner(['company_name' => 'Zeta Capital']), ['first_name' => 'Zed']);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $rows = collect($this->signers(['type' => 'borrower'])->assertOk()->json('data'))->keyBy('user_id');

        $this->assertEqualsCanonicalizing([$own->id, $theirs->id, $other->id], $rows->keys()->all());
        $this->assertSame('Acme Lending', $rows[$theirs->id]['partner_name']);
        $this->assertSame('Zeta Capital', $rows[$other->id]['partner_name']);
    }

    #[Test]
    public function the_hosts_staff_search_every_client_too(): void
    {
        $theirs = $this->makeBorrowerUser($this->partner, ['first_name' => 'Cleo', 'last_name' => 'Client']);
        $this->makeBorrowerUser($this->makeWhiteLabelPartner(), ['first_name' => 'Zed']);
        $this->actingAsUser($this->makeStaffUser($this->host));

        $rows = $this->signers(['type' => 'borrower', 'search' => 'cleo'])->assertOk()->json('data');

        $this->assertSame([$theirs->id], array_column($rows, 'user_id'));
    }

    #[Test]
    public function a_partners_staff_search_only_their_own_clients(): void
    {
        $own = $this->makeBorrowerUser($this->partner, ['first_name' => 'Cleo']);
        $this->makeBorrowerUser($this->makeWhiteLabelPartner(), ['first_name' => 'Cleo']);
        $this->actingAsUser($this->makeStaffUser($this->partner));

        $rows = $this->signers(['type' => 'borrower', 'search' => 'cleo'])->assertOk()->json('data');

        $this->assertSame([$own->id], array_column($rows, 'user_id'));
    }

    #[Test]
    public function the_host_lists_every_partner_and_client_from_another_partners_configuration_too(): void
    {
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $otherClient = $this->makeBorrowerUser($this->makeWhiteLabelPartner());
        $this->actingAsUser($this->makePartnerAdmin($this->host, ['manage partner configuration']));

        $partners = $this->signers(['type' => 'partner', 'partner_id' => $this->partner->id])->assertOk()->json('data');
        $clients = $this->signers(['type' => 'borrower', 'partner_id' => $this->partner->id])->assertOk()->json('data');

        $this->assertContains($partnerUser->id, array_column($partners, 'user_id'));
        $this->assertContains($otherClient->id, array_column($clients, 'user_id'));
    }

    #[Test]
    public function a_client_is_previewed_with_their_own_partners_details_not_the_hosts(): void
    {
        $client = $this->makeBorrowerUser($this->partner, ['first_name' => 'Cleo', 'last_name' => 'Client']);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        // From the host's Settings: another partner's client.
        $values = $this->postJson('/api/v1/common/agreement-templates/preview-values', ['type' => 'borrower', 'signer_user_id' => $client->id])
            ->assertOk()->json('data');

        $this->assertSame('Cleo Client', $values['signer.name']);
        $this->assertSame('Acme Lending', $values['owner.company_name']);
        $this->assertSame('ada@acme.test', $values['owner.email']);
    }

    #[Test]
    public function a_partner_is_previewed_with_the_hosts_details(): void
    {
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host, ['manage partner configuration']));

        // Even from that partner's own Configuration: the host owns the partner agreement.
        $values = $this->postJson('/api/v1/common/agreement-templates/preview-values', [
            'type' => 'partner', 'signer_user_id' => $partnerUser->id, 'partner_id' => $this->partner->id,
        ])->assertOk()->json('data');

        $this->assertSame('Ada Partner', $values['signer.name']);
        $this->assertSame('Host Co', $values['owner.company_name']);
    }

    #[Test]
    public function each_agreement_is_previewed_only_as_its_own_kind_of_signer(): void
    {
        $client = $this->makeBorrowerUser($this->partner);
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson('/api/v1/common/agreement-templates/preview-values', ['type' => 'partner', 'signer_user_id' => $client->id])->assertNotFound();
        $this->postJson('/api/v1/common/agreement-templates/preview-values', ['type' => 'borrower', 'signer_user_id' => $partnerUser->id])->assertNotFound();
    }

    #[Test]
    public function a_partner_never_sees_another_partners_clients_or_the_partner_list(): void
    {
        $this->makePartnerAdmin($this->makeWhiteLabelPartner());
        $this->makeBorrowerUser($this->makeWhiteLabelPartner());
        $own = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->assertSame([], $this->signers(['type' => 'partner'])->assertOk()->json('data'));
        $this->assertSame([$own->id], array_column($this->signers(['type' => 'borrower'])->json('data'), 'user_id'));
    }

    #[Test]
    public function each_signer_shows_their_enrollment_type_and_whether_they_signed(): void
    {
        $installment = $this->makeBorrowerUser($this->partner, ['first_name' => 'Ina', 'has_sign' => true]);
        $oneTime = $this->makeBorrowerUser($this->partner, ['first_name' => 'Otto']);
        $none = $this->makeBorrowerUser($this->partner, ['first_name' => 'Nell']);
        $this->subscribe($installment->entity_id, ['enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT, 'installment_type' => 1]);
        // An older one-time enrollment, then the latest installment one: the latest counts.
        $this->subscribe($oneTime->entity_id, ['enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT, 'installment_type' => 1]);
        $this->subscribe($oneTime->entity_id, ['enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT, 'installment_type' => 0]);
        // A monthly subscription is no enrollment.
        $this->subscribe($none->entity_id, ['enrollment_type' => Subscription::ENROLLMENT_TYPE_MONTHLY, 'installment_type' => 0]);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $rows = collect($this->signers(['type' => 'borrower'])->assertOk()->json('data'))->keyBy('user_id');

        $this->assertSame(SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT, $rows[$installment->id]['enrollment_type']);
        $this->assertTrue($rows[$installment->id]['has_sign']);
        $this->assertSame(SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT, $rows[$oneTime->id]['enrollment_type']);
        $this->assertFalse($rows[$oneTime->id]['has_sign']);
        $this->assertNull($rows[$none->id]['enrollment_type']);
    }

    #[Test]
    public function a_partner_signer_shows_its_own_enrollment_type_and_sign_status(): void
    {
        $this->partner->update(['has_sign' => true]);
        $partnerUser = $this->makePartnerAdmin($this->partner);
        Subscription::create([
            'entity_type' => Partner::class, 'entity_id' => $this->partner->id, 'status' => Subscription::STATUS_ACTIVE,
            'current_phase' => Subscription::PHASE_ENROLLMENT,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT, 'installment_type' => 0,
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $row = $this->signers(['type' => 'partner'])->assertOk()->json('data.0');

        $this->assertSame($partnerUser->id, $row['user_id']);
        $this->assertSame(SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT, $row['enrollment_type']);
        $this->assertTrue($row['has_sign']);
    }

    private function subscribe(int $borrowerId, array $attributes): Subscription
    {
        return Subscription::create(array_merge([
            'entity_type' => Borrower::class, 'entity_id' => $borrowerId, 'status' => Subscription::STATUS_ACTIVE,
            'current_phase' => Subscription::PHASE_ENROLLMENT,
        ], $attributes));
    }

    #[Test]
    public function the_list_needs_a_known_agreement_type(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->signers(['type' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    /* ── Preview values for one signer ────────────────────────────────────── */

    #[Test]
    public function the_preview_values_carry_the_chosen_clients_details(): void
    {
        $client = $this->makeBorrowerUser($this->partner, [
            'first_name' => 'Cleo',
            'last_name' => 'Client',
            'email' => 'cleo@client.test',
            'business_name' => 'Cleo Co',
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $values = $this->postJson('/api/v1/common/agreement-templates/preview-values', [
            'type' => 'borrower',
            'signer_user_id' => $client->id,
        ])->assertOk()->json('data');

        $this->assertSame('Cleo Client', $values['signer.name']);
        $this->assertSame('cleo@client.test', $values['signer.email']);
        $this->assertSame('Cleo Co', $values['signer.business_name']);
        $this->assertSame('Acme Lending', $values['owner.company_name']);
    }

    #[Test]
    public function the_preview_values_carry_the_chosen_partners_details(): void
    {
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $values = $this->postJson('/api/v1/common/agreement-templates/preview-values', [
            'type' => 'partner',
            'signer_user_id' => $partnerUser->id,
        ])->assertOk()->json('data');

        $this->assertSame('Ada Partner', $values['signer.name']);
        $this->assertSame('Acme Lending', $values['signer.business_name']);
        $this->assertSame('Host Co', $values['owner.company_name']);
    }

    #[Test]
    public function someone_who_does_not_sign_this_agreement_cannot_be_previewed(): void
    {
        $otherClient = $this->makeBorrowerUser($this->makeWhiteLabelPartner());
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->postJson('/api/v1/common/agreement-templates/preview-values', [
            'type' => 'borrower',
            'signer_user_id' => $otherClient->id,
        ])->assertNotFound();
    }

    #[Test]
    public function a_signer_needs_the_agreement_type(): void
    {
        $client = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->postJson('/api/v1/common/agreement-templates/preview-values', ['signer_user_id' => $client->id])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    /* ── Agreement tab: the unsigned agreement for one partner / client ──── */

    #[Test]
    public function the_host_previews_the_active_partner_agreement_a_partner_has_not_signed(): void
    {
        AgreementTemplate::create(['partner_id' => $this->host->id, 'slug' => 'partner', 'is_active' => true, 'template_html' => '<p>Dear {{signer.name}}</p>']);
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/common/agreement-sign/preview/{$partnerUser->id}")
            ->assertOk()
            ->assertJsonPath('data.slug', 'partner')
            ->assertJsonPath('data.template_html', '<p>Dear Ada Partner</p>');
    }

    #[Test]
    public function a_partner_previews_its_own_clients_borrower_agreement(): void
    {
        AgreementTemplate::create(['partner_id' => $this->partner->id, 'slug' => 'borrower', 'is_active' => true, 'template_html' => '<p>{{owner.company_name}} and {{signer.name}}</p>']);
        $client = $this->makeBorrowerUser($this->partner, ['first_name' => 'Cleo', 'last_name' => 'Client']);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->getJson("/api/v1/common/agreement-sign/preview/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.template_html', '<p>Acme Lending and Cleo Client</p>');
    }

    #[Test]
    public function an_inactive_agreement_has_no_preview(): void
    {
        AgreementTemplate::create(['partner_id' => $this->host->id, 'slug' => 'partner', 'is_active' => false, 'template_html' => '<p>Off</p>']);
        $partnerUser = $this->makePartnerAdmin($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/common/agreement-sign/preview/{$partnerUser->id}")->assertNotFound();
    }

    #[Test]
    public function another_partners_client_cannot_be_previewed(): void
    {
        $other = $this->makeWhiteLabelPartner();
        AgreementTemplate::create(['partner_id' => $other->id, 'slug' => 'borrower', 'is_active' => true, 'template_html' => '<p>x</p>']);
        $client = $this->makeBorrowerUser($other);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->getJson("/api/v1/common/agreement-sign/preview/{$client->id}")->assertNotFound();
    }

    #[Test]
    public function a_borrower_cannot_preview_anyone(): void
    {
        AgreementTemplate::create(['partner_id' => $this->partner->id, 'slug' => 'borrower', 'is_active' => true, 'template_html' => '<p>x</p>']);
        $client = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        // Borrowers use the v1/borrower routes: the common routes turn them away before the controller.
        $this->getJson("/api/v1/common/agreement-sign/preview/{$client->id}")->assertForbidden();
    }
}
