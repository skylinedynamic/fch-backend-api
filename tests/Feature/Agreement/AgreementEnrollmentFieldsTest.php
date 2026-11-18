<?php

namespace Tests\Feature\Agreement;

use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateController;
use App\Models\Borrower\Borrower;
use App\Models\Common\AgreementTemplate;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Services\FCH\Common\ManageAgreement\AgreementEnrollmentFields;
use App\Services\FCH\Common\ManageAgreement\AgreementMergeFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * {{signer.enrollment_table}}: a table of the amounts set for the signer when
 * they enrolled - a partner's own in the partner agreement, a borrower's own in
 * the borrower agreement - with the rows their enrollment type has (a template
 * can't know whether a signer pays once or in installments). Like the
 * commission table, the token alone in its paragraph takes that paragraph's place.
 */
#[CoversClass(AgreementEnrollmentFields::class)]
#[CoversClass(AgreementMergeFields::class)]
#[CoversClass(AgreementTemplateController::class)]
class AgreementEnrollmentFieldsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const KEY = 'signer.enrollment_table';

    private const HTML = '<p>Enrollment Details</p><p>{{signer.enrollment_table}}</p>';

    private const INSTALLMENT = [
        'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT, 'installment_type' => 1, 'installment_count' => 24,
        'payable_amount' => 6000, 'down_payment' => 500, 'total_subscription_amount' => 5500,
        'subscription_amount_per_month' => 229.17, 'monthly_platform_fee' => 299,
    ];

    private const ONE_TIME = [
        'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT, 'installment_type' => 0,
        'payable_amount' => 4997, 'down_payment' => 0, 'total_subscription_amount' => 4997,
        'subscription_amount_per_month' => null, 'monthly_platform_fee' => 199,
    ];

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['agreement.image_disk' => 'local', 'agreement.image_directory' => 'agreement-images']);

        $this->host = $this->makeHostPartner();
        DB::table('app_configurations')->insert([
            'partner_id' => $this->host->id, 'module' => 'agreement', 'key' => 'agreement_signing_enabled', 'value' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        SubscriptionPlan::create([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT_SLUG,
            'total_amount' => 800, 'currency' => 'USD', 'currency_symbol' => '$', 'conversion_rate' => 1, 'status' => 1,
        ]);
        SubscriptionPlan::create([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT, 'partner_type' => 'Certified Partner', 'partner_type_slug' => 'certified-partner',
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_MONTHLY_SEAT_FEE, 'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_MONTHLY_SEAT_FEE_SLUG,
            'total_amount' => 49, 'total_seat_count' => 3, 'currency' => 'USD', 'currency_symbol' => '$', 'conversion_rate' => 1, 'status' => 1,
        ]);
    }

    private function fields(string $type): array
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        return collect($this->postJson('/api/v1/common/agreement-templates/merge-fields', ['type' => $type])->assertOk()->json('data'))
            ->keyBy('key')->all();
    }

    private function subscribe(Partner|Borrower $entity, array $attributes): Subscription
    {
        return Subscription::create(array_merge([
            'entity_type' => $entity::class, 'entity_id' => $entity->id, 'status' => Subscription::STATUS_ACTIVE,
            'current_phase' => Subscription::PHASE_ENROLLMENT,
        ], $attributes));
    }

    private function partnerSees(Partner $partner, string $html = self::HTML): string
    {
        AgreementTemplate::create([
            'partner_id' => $this->host->id, 'slug' => AgreementTemplate::SLUG_PARTNER, 'is_active' => true, 'template_html' => $html,
        ]);
        $this->actingAsUser($this->makePartnerAdmin($partner));

        return $this->getJson('/api/v1/common/agreement-sign/check')->assertOk()->json('data.template.template_html');
    }

    private function borrowerSees(?array $subscription, string $html = self::HTML): string
    {
        $partner = $this->makeWhiteLabelPartner();
        AgreementTemplate::create([
            'partner_id' => $partner->id, 'slug' => AgreementTemplate::SLUG_BORROWER, 'is_active' => true, 'template_html' => $html,
        ]);
        $user = $this->makeBorrowerUser($partner);
        if ($subscription) {
            $this->subscribe(Borrower::find($user->entity_id), $subscription);
        }
        $this->actingAsUser($user);

        return $this->getJson('/api/v1/borrower/agreement-sign/check')->assertOk()->json('data.template.template_html');
    }

    /** Each row of the table as [label, value]. */
    private function rows(string $html): array
    {
        preg_match_all('#<tr><td[^>]*>(.*?)</td><td[^>]*>(.*?)</td></tr>#', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($row) => [html_entity_decode($row[1]), html_entity_decode($row[2])], $m);
    }

    #[Test]
    public function the_partner_agreement_offers_one_enrollment_table_and_says_what_each_type_shows(): void
    {
        $fields = $this->fields(AgreementTemplate::SLUG_PARTNER);

        $enrollment = array_values(array_filter($fields, fn ($f) => str_starts_with($f['key'], 'signer.enrollment')));
        $this->assertCount(1, $enrollment);
        $field = $enrollment[0];
        $this->assertSame(self::KEY, $field['key']);
        $this->assertSame('Enrollment Details', $field['label']);
        $this->assertSame('Signer: Enrollment', $field['group']);
        $this->assertTrue($field['html']);
        // The preview's example: a table for each enrollment type, each captioned
        // (a signer sees only the one for their own type).
        $this->assertSame(2, substr_count($field['sample'], '<table'));
        $this->assertStringContainsString('Example: Installment', $field['sample']);
        $this->assertStringContainsString('Example: One Time Payment', $field['sample']);
        $this->assertLessThan(strpos($field['sample'], 'Example: One Time Payment'), strpos($field['sample'], 'Down payment'));
        $oneTime = substr($field['sample'], strpos($field['sample'], 'Example: One Time Payment'));
        $this->assertStringNotContainsString('Down payment', $oneTime);
        $this->assertStringContainsString('$4,997.00', $oneTime);
        $this->assertStringContainsString('Free seat count', $oneTime);
        $this->assertStringNotContainsString('Installment months', $oneTime);
        $this->assertSame([
            ['type' => 'Installment', 'rows' => ['Enrollment type', 'Total enrollment amount', 'Down payment', 'Subscription amount', 'Monthly subscription', 'Installment months', 'Platform fee', 'Per user seat fee', 'Free seat count', 'Client activation fee']],
            ['type' => 'One Time Payment', 'rows' => ['Enrollment type', 'Total enrollment amount', 'Platform fee', 'Per user seat fee', 'Free seat count', 'Client activation fee']],
        ], $field['variants']);
    }

    #[Test]
    public function the_borrower_agreement_table_has_only_the_borrowers_rows(): void
    {
        $field = $this->fields(AgreementTemplate::SLUG_BORROWER)[self::KEY];

        $this->assertSame([
            ['type' => 'Installment', 'rows' => ['Enrollment type', 'Total enrollment amount', 'Down payment', 'Subscription amount', 'Monthly subscription', 'Installment months']],
            ['type' => 'One Time Payment', 'rows' => ['Enrollment type', 'Total enrollment amount']],
        ], $field['variants']);
    }

    #[Test]
    public function a_partner_on_installments_sees_a_table_of_their_own_enrollment(): void
    {
        $partner = $this->makeCertifiedPartner();
        // Its own free seats on the enrollment; no per-seat price of its own, so the seat plan's.
        $this->subscribe($partner, self::INSTALLMENT + ['user_seats' => 5]);

        $html = $this->partnerSees($partner);

        $this->assertSame([
            ['Enrollment type', 'Installment'],
            ['Total enrollment amount', '$6,000.00'],
            ['Down payment', '$500.00'],
            ['Subscription amount', '$5,500.00'],
            ['Monthly subscription', '$229.17'],
            ['Installment months', '24'],
            ['Platform fee', '$299.00'],
            ['Per user seat fee', '$49.00'],
            ['Free seat count', '5'],
            ['Client activation fee', '$800.00 (it may vary per borrower)'],
        ], $this->rows($html));
        // The table takes the paragraph's place: no <table> inside a <p>.
        $this->assertStringNotContainsString('<p><table', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    #[Test]
    public function a_partner_paying_once_sees_only_the_one_time_rows(): void
    {
        $partner = $this->makeCertifiedPartner();
        // Its own per-seat price; no free seats of its own, so the seat plan's.
        $this->subscribe($partner, self::ONE_TIME + ['per_seat_amount' => 39.99]);

        $this->assertSame([
            ['Enrollment type', 'One Time Payment'],
            ['Total enrollment amount', '$4,997.00'],
            ['Platform fee', '$199.00'],
            ['Per user seat fee', '$39.99'],
            ['Free seat count', '3'],
            ['Client activation fee', '$800.00 (it may vary per borrower)'],
        ], $this->rows($this->partnerSees($partner)));
    }

    #[Test]
    public function a_seat_subscription_is_not_taken_for_the_enrollment(): void
    {
        $partner = $this->makeCertifiedPartner();
        $this->subscribe($partner, self::INSTALLMENT);
        $this->subscribe($partner, [
            'enrollment_type' => Subscription::ENROLLMENT_TYPE_MONTHLY, 'current_phase' => Subscription::PHASE_USER_SEAT,
            'payable_amount' => 49, 'total_subscription_amount' => 49, 'monthly_platform_fee' => 49, 'user_seats' => 1, 'per_seat_amount' => 49,
        ]);

        $rows = $this->rows($this->partnerSees($partner));

        $this->assertSame(['Total enrollment amount', '$6,000.00'], $rows[1]);
    }

    #[Test]
    public function a_partner_with_no_enrollment_is_told_so(): void
    {
        $html = $this->partnerSees($this->makeCertifiedPartner());

        $this->assertStringContainsString('No enrollment found.', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    #[Test]
    public function a_borrower_on_installments_sees_their_own_rows(): void
    {
        $html = $this->borrowerSees([
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT, 'installment_type' => 1, 'installment_count' => 36,
            'payable_amount' => 3000, 'down_payment' => 300, 'total_subscription_amount' => 2700, 'subscription_amount_per_month' => 75,
        ]);

        $this->assertSame([
            ['Enrollment type', 'Installment'],
            ['Total enrollment amount', '$3,000.00'],
            ['Down payment', '$300.00'],
            ['Subscription amount', '$2,700.00'],
            ['Monthly subscription', '$75.00'],
            ['Installment months', '36'],
        ], $this->rows($html));
    }

    #[Test]
    public function a_borrower_paying_once_sees_their_total(): void
    {
        $html = $this->borrowerSees([
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT, 'installment_type' => 0,
            'payable_amount' => 2497, 'down_payment' => 0, 'total_subscription_amount' => 2497, 'monthly_platform_fee' => 99,
        ]);

        // Never a partner's platform or activation fee.
        $this->assertSame([
            ['Enrollment type', 'One Time Payment'],
            ['Total enrollment amount', '$2,497.00'],
        ], $this->rows($html));
    }

    #[Test]
    public function a_field_from_the_earlier_one_amount_per_field_version_is_left_blank(): void
    {
        $html = $this->borrowerSees(self::INSTALLMENT, '<p>Total: {{signer.enrollment.total_amount}}</p>');

        $this->assertSame('<p>Total: </p>', $html);
    }

    #[Test]
    public function the_table_mid_sentence_is_filled_in_place(): void
    {
        $partner = $this->makeCertifiedPartner();
        $this->subscribe($partner, self::ONE_TIME);

        $html = $this->partnerSees($partner, '<p>Your enrollment: {{signer.enrollment_table}} as agreed.</p>');

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('as agreed.', $html);
    }
}
