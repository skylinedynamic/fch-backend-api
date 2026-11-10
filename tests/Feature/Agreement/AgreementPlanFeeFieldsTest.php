<?php

namespace Tests\Feature\Agreement;

use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateController;
use App\Models\Common\AgreementTemplate;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Services\FCH\Common\ManageAgreement\AgreementMergeFields;
use App\Services\FCH\Common\ManageAgreement\AgreementPlanFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Plan & Fee prices as Agreement Builder dynamic values ({{plan....}}): the
 * price list of the active plans, the same for every signer. The partner
 * agreement offers and fills all of them; the borrower agreement only the
 * Borrower Enrollment ones. Keys are built from the plan's names, not its id,
 * so a template means the same plan on every server.
 */
#[CoversClass(AgreementPlanFields::class)]
#[CoversClass(AgreementMergeFields::class)]
#[CoversClass(AgreementTemplateController::class)]
class AgreementPlanFeeFieldsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner(['company_name' => 'Four Corner Funding']);
        $this->partner = $this->makeWhiteLabelPartner(['company_name' => 'Acme Lending']);
        DB::table('app_configurations')->insert([
            'partner_id' => $this->host->id, 'module' => 'agreement', 'key' => 'agreement_signing_enabled', 'value' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->plan('partner_enrollment', 'Certified Partner', 'certified-partner', 'One Time Payment', 'one_time_payment', 4997, 0, 1);
        $this->plan('partner_enrollment', 'Certified Partner', 'certified-partner', 'Installment', 'installment', 5496.70, 299, 24);
        $this->plan('partner_enrollment', 'White-Label Partner (WL)', 'white-label-partner-wl', 'Payment Plan (11-month plan)', 'payment-plan-11-month-plan', 7696.70, 399, 11);
        $this->plan('partner_enrollment', 'White-Label Partner (WL)', 'white-label-partner-wl', 'Old Plan', 'old-plan', 100, 0, 1, ['status' => 0]);
        $this->plan('user_seat', 'Certified Partner', 'certified-partner', 'Monthly', 'monthly', 49, null, null, ['total_seat_count' => 3]);
        $this->plan('borrower_fee', null, null, 'Fixed Enrollment', 'fixed_enrollment', 800, null, null, ['is_partner_paying' => 1]);
        $this->plan('borrower_enrollment', null, null, 'One Time Payment', 'one_time_payment', 2497, null, 1);
        $this->plan('borrower_enrollment', null, null, 'Installment', 'installment', 3000, null, 36);
        $this->plan('borrower_enrollment', null, null, '6 Month Payment', '6-month-payment', 3000, null, 6, ['status' => 0]);
    }

    private function plan(string $group, ?string $type, ?string $typeSlug, string $enrollment, string $enrollmentSlug,
        float $total, ?float $platformFee, ?int $installments, array $extra = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'group' => $group, 'partner_type' => $type, 'partner_type_slug' => $typeSlug,
            'enrollment_type' => $enrollment, 'enrollment_type_slug' => $enrollmentSlug,
            'total_amount' => $total, 'platform_fee' => $platformFee, 'total_installment_count_upto' => $installments,
            'currency' => 'USD', 'currency_symbol' => '$', 'conversion_rate' => 1, 'status' => 1,
        ], $extra));
    }

    private function fields(string $type): array
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        return collect($this->postJson('/api/v1/common/agreement-templates/merge-fields', ['type' => $type])->assertOk()->json('data'))
            ->keyBy('key')->all();
    }

    #[Test]
    public function the_partner_agreement_offers_every_active_plan_and_fee(): void
    {
        $fields = $this->fields(AgreementTemplate::SLUG_PARTNER);

        $this->assertSame([
            'key' => 'plan.partner_enrollment.certified_partner.one_time_payment.total_amount',
            'label' => 'Certified Partner · One Time Payment: Total amount',
            'group' => 'Plan & Fee: Partner enrollment',
            'sample' => '$4,997.00',
        ], $fields['plan.partner_enrollment.certified_partner.one_time_payment.total_amount']);
        $this->assertSame('$299.00', $fields['plan.partner_enrollment.certified_partner.installment.platform_fee']['sample']);
        $this->assertSame('24', $fields['plan.partner_enrollment.certified_partner.installment.installments']['sample']);
        $this->assertSame('$7,696.70', $fields['plan.partner_enrollment.white_label_partner_wl.payment_plan_11_month_plan.total_amount']['sample']);
        $this->assertSame('$49.00', $fields['plan.user_seat.certified_partner.monthly.total_amount']['sample']);
        $this->assertSame('Certified Partner · Monthly: Monthly seat fee', $fields['plan.user_seat.certified_partner.monthly.total_amount']['label']);
        $this->assertSame('3', $fields['plan.user_seat.certified_partner.monthly.free_seats']['sample']);
        $this->assertSame('$800.00', $fields['plan.borrower_fee.fixed_enrollment.total_amount']['sample']);
        $this->assertSame('Client activation fee', $fields['plan.borrower_fee.fixed_enrollment.total_amount']['label']);
        $this->assertSame('$2,497.00', $fields['plan.borrower_enrollment.one_time_payment.total_amount']['sample']);
        $this->assertSame('36', $fields['plan.borrower_enrollment.installment.installments']['sample']);

        // A one-off payment has no instalments to name; an inactive plan is not offered.
        $this->assertArrayNotHasKey('plan.partner_enrollment.certified_partner.one_time_payment.installments', $fields);
        $this->assertArrayNotHasKey('plan.partner_enrollment.white_label_partner_wl.old_plan.total_amount', $fields);
        $this->assertArrayNotHasKey('plan.borrower_enrollment.6_month_payment.total_amount', $fields);
    }

    #[Test]
    public function the_borrower_agreement_offers_only_borrower_enrollment(): void
    {
        $fields = $this->fields(AgreementTemplate::SLUG_BORROWER);

        $this->assertSame([
            'plan.borrower_enrollment.one_time_payment.total_amount',
            'plan.borrower_enrollment.installment.total_amount',
            'plan.borrower_enrollment.installment.installments',
        ], array_keys($fields));
        $this->assertSame('Plan & Fee: Borrower enrollment', $fields['plan.borrower_enrollment.installment.total_amount']['group']);
        $this->assertSame('Installment: Total amount', $fields['plan.borrower_enrollment.installment.total_amount']['label']);
    }

    #[Test]
    public function the_agreement_type_is_required(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson('/api/v1/common/agreement-templates/merge-fields', ['type' => 'anything'])
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    #[Test]
    public function a_partner_signing_the_partner_agreement_sees_the_prices_filled(): void
    {
        AgreementTemplate::create([
            'partner_id' => $this->host->id, 'slug' => AgreementTemplate::SLUG_PARTNER, 'is_active' => true,
            'template_html' => '<p>Fee {{plan.partner_enrollment.certified_partner.installment.total_amount}} '
                . 'in {{plan.partner_enrollment.certified_partner.installment.installments}} payments, '
                . 'seat {{plan.user_seat.certified_partner.monthly.total_amount}}, '
                . 'activation {{plan.borrower_fee.fixed_enrollment.total_amount}}, '
                . 'client {{plan.borrower_enrollment.one_time_payment.total_amount}}, '
                . '11-month {{plan.partner_enrollment.white_label_partner_wl.payment_plan_11_month_plan.total_amount}}.</p>',
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->makeCertifiedPartner(['company_name' => 'Certified Co'])));

        $html = $this->getJson('/api/v1/common/agreement-sign/check')->assertOk()->json('data.template.template_html');

        $this->assertStringContainsString(
            'Fee $5,496.70 in 24 payments, seat $49.00, activation $800.00, client $2,497.00, 11-month $7,696.70.',
            $html
        );
    }

    #[Test]
    public function a_borrower_sees_only_borrower_enrollment_prices_filled(): void
    {
        AgreementTemplate::create([
            'partner_id' => $this->partner->id, 'slug' => AgreementTemplate::SLUG_BORROWER, 'is_active' => true,
            'template_html' => '<p>[{{plan.borrower_enrollment.installment.total_amount}}]'
                . '[{{plan.partner_enrollment.certified_partner.one_time_payment.total_amount}}]'
                . '[{{plan.borrower_fee.fixed_enrollment.total_amount}}]</p>',
        ]);
        $this->actingAsUser($this->makeBorrowerUser($this->partner, ['first_name' => 'Jane', 'last_name' => 'Doe']));

        $html = $this->getJson('/api/v1/borrower/agreement-sign/check')->assertOk()->json('data.template.template_html');

        // Partner prices stay out of a borrower's agreement - blank, never the raw {{...}}.
        $this->assertStringContainsString('<p>[$3,000.00][][]</p>', $html);
    }

    #[Test]
    public function a_plan_switched_off_after_the_template_was_written_is_left_blank(): void
    {
        AgreementTemplate::create([
            'partner_id' => $this->host->id, 'slug' => AgreementTemplate::SLUG_PARTNER, 'is_active' => true,
            'template_html' => '<p>[{{plan.partner_enrollment.white_label_partner_wl.old_plan.total_amount}}]</p>',
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->makeCertifiedPartner()));

        $html = $this->getJson('/api/v1/common/agreement-sign/check')->assertOk()->json('data.template.template_html');

        $this->assertStringContainsString('<p>[]</p>', $html);
    }
}
