<?php

namespace Tests\Feature\Agreement;

use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateController;
use App\Models\Common\AgreementSign;
use App\Models\Common\AgreementTemplate;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerCommissionTier;
use App\Services\FCH\Common\ManageAgreement\AgreementCommissionFields;
use App\Services\FCH\Common\ManageAgreement\AgreementMergeFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * {{signer.commission_table}}: the host puts it in the partner agreement and
 * each partner sees a table of their own commission tiers (range and a % or
 * flat $ per deal) when they sign. The signed copy keeps the tiers as signed.
 */
#[CoversClass(AgreementCommissionFields::class)]
#[CoversClass(AgreementMergeFields::class)]
#[CoversClass(AgreementTemplateController::class)]
class AgreementCommissionTableTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

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
    }

    private function partnerTemplate(string $html): AgreementTemplate
    {
        return AgreementTemplate::create([
            'partner_id' => $this->host->id, 'slug' => AgreementTemplate::SLUG_PARTNER, 'is_active' => true, 'template_html' => $html,
        ]);
    }

    private function partnerWithTiers(): Partner
    {
        $partner = $this->makeCertifiedPartner();
        $partner->commissionTiers()->create([
            'min_count' => 0, 'max_count' => 10, 'commission_type' => PartnerCommissionTier::TYPE_FLAT, 'amount' => 1500, 'sort_order' => 0,
        ]);
        $partner->commissionTiers()->create([
            'min_count' => 11, 'max_count' => null, 'commission_type' => PartnerCommissionTier::TYPE_PERCENTAGE, 'percentage' => 27.5, 'sort_order' => 1,
        ]);

        return $partner;
    }

    private function signHtml(): string
    {
        return $this->getJson('/api/v1/common/agreement-sign/check')->assertOk()->json('data.template.template_html');
    }

    private function fields(string $type): array
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        return collect($this->postJson('/api/v1/common/agreement-templates/merge-fields', ['type' => $type])->assertOk()->json('data'))
            ->keyBy('key')->all();
    }

    #[Test]
    public function the_partner_agreement_offers_the_commission_table(): void
    {
        $field = $this->fields(AgreementTemplate::SLUG_PARTNER)['signer.commission_table'] ?? null;

        $this->assertNotNull($field);
        $this->assertSame("Partner's commission tiers", $field['label']);
        $this->assertSame('Signer', $field['group']);
        $this->assertTrue($field['html']);
        $this->assertStringContainsString('<table', $field['sample']);
    }

    #[Test]
    public function the_borrower_agreement_does_not_offer_it(): void
    {
        $this->assertArrayNotHasKey('signer.commission_table', $this->fields(AgreementTemplate::SLUG_BORROWER));
    }

    #[Test]
    public function a_partner_signing_sees_their_own_tiers_as_a_table(): void
    {
        $this->partnerTemplate('<div class="p-text"><p>5.1 Commission.</p><p>{{signer.commission_table}}</p></div>');
        $this->actingAsUser($this->makePartnerAdmin($this->partnerWithTiers()));

        $html = $this->signHtml();

        $this->assertStringContainsString('<table class="ab-commission-table"', $html);
        $this->assertStringNotContainsString('&lt;table', $html);
        $this->assertStringNotContainsString('{{signer.commission_table}}', $html);
        // The table replaces its own paragraph - never a table inside a <p>.
        $this->assertStringNotContainsString('<p><table', $html);
        $this->assertMatchesRegularExpression('#<td[^>]*>0</td>\s*<td[^>]*>10</td>\s*<td[^>]*>\$1,500</td>#', $html);
        $this->assertMatchesRegularExpression('#<td[^>]*>11</td>\s*<td[^>]*>and up</td>\s*<td[^>]*>27\.5%</td>#', $html);
    }

    #[Test]
    public function the_token_inside_a_sentence_still_becomes_the_table(): void
    {
        $this->partnerTemplate('<p>Commission: {{signer.commission_table}}</p>');
        $this->actingAsUser($this->makePartnerAdmin($this->partnerWithTiers()));

        $html = $this->signHtml();

        $this->assertStringContainsString('<table class="ab-commission-table"', $html);
        $this->assertStringContainsString('27.5%', $html);
    }

    #[Test]
    public function another_partners_tiers_never_show(): void
    {
        $this->partnerWithTiers();
        $mine = $this->makeCertifiedPartner();
        $mine->commissionTiers()->create([
            'min_count' => 0, 'max_count' => null, 'commission_type' => PartnerCommissionTier::TYPE_PERCENTAGE, 'percentage' => 40, 'sort_order' => 0,
        ]);
        $this->partnerTemplate('<p>{{signer.commission_table}}</p>');
        $this->actingAsUser($this->makePartnerAdmin($mine));

        $html = $this->signHtml();

        $this->assertStringContainsString('40%', $html);
        $this->assertStringNotContainsString('$1,500', $html);
    }

    #[Test]
    public function a_partner_with_no_tiers_is_told_none_is_set(): void
    {
        $this->partnerTemplate('<p>{{signer.commission_table}}</p>');
        $this->actingAsUser($this->makePartnerAdmin($this->makeCertifiedPartner()));

        $html = $this->signHtml();

        $this->assertStringContainsString('No commission is set.', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    #[Test]
    public function a_borrower_agreement_drops_the_token(): void
    {
        $partner = $this->makeWhiteLabelPartner();
        AgreementTemplate::create([
            'partner_id' => $partner->id, 'slug' => AgreementTemplate::SLUG_BORROWER, 'is_active' => true,
            'template_html' => '<p>[{{signer.commission_table}}]</p>',
        ]);
        $this->actingAsUser($this->makeBorrowerUser($partner));

        $html = $this->getJson('/api/v1/borrower/agreement-sign/check')->assertOk()->json('data.template.template_html');

        $this->assertStringContainsString('<p>[]</p>', $html);
    }

    #[Test]
    public function the_signed_copy_keeps_the_tiers_as_signed(): void
    {
        $template = $this->partnerTemplate('<html><body><div class="doc"><p>{{signer.commission_table}}</p>'
            . '<input type="text" class="p-input" data-field-id="name" required/></div></body></html>');
        $partner = $this->partnerWithTiers();
        $signer = $this->actingAsUser($this->makePartnerAdmin($partner));

        $this->postJson('/api/v1/common/agreement-sign/submit', [
            'agreement_template_id' => $template->id,
            'fields' => ['name' => 'Acme Partner'],
        ])->assertOk();

        $partner->commissionTiers()->update(['commission_type' => PartnerCommissionTier::TYPE_PERCENTAGE, 'percentage' => 5, 'amount' => null]);

        $html = AgreementSign::where('user_id', $signer->id)->value('content_html');
        $this->assertStringContainsString('ab-commission-table', $html);
        $this->assertMatchesRegularExpression('#<td[^>]*>\$1,500</td>#', $html);
        $this->assertStringContainsString('27.5%', $html);
        $this->assertStringNotContainsString('>5%<', $html);
    }
}
