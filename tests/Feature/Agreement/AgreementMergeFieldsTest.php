<?php

namespace Tests\Feature\Agreement;

use App\Models\Common\AgreementSign;
use App\Models\Common\AgreementTemplate;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\ManageAgreement\AgreementMergeFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Agreement text can hold merge fields such as {{signer.name}} or
 * {{owner.company_name}}, inserted from the Agreement Builder's "Insert field"
 * menu. The signer sees them filled with their own details, and the signed
 * record keeps the filled text - so nobody retypes their name and address,
 * and nobody's details are hard-coded into a template.
 */
#[CoversClass(AgreementMergeFields::class)]
class AgreementMergeFieldsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Partner $host;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['agreement.image_disk' => 'local', 'agreement.image_directory' => 'agreement-images']);
        Carbon::setTestNow('2026-10-30 12:00:00');

        $this->host = $this->makeHostPartner(['company_name' => 'Four Corner Funding']);
        $this->partner = $this->makeWhiteLabelPartner([
            'company_name' => 'Acme Lending', 'email' => 'hello@acme.test', 'phone_number' => '(444) 444-4444',
            'address_line_1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701',
        ]);
        DB::table('app_configurations')->insert([
            'partner_id' => $this->host->id, 'module' => 'agreement', 'key' => 'agreement_signing_enabled', 'value' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function borrowerTemplate(string $body): AgreementTemplate
    {
        return AgreementTemplate::create([
            'partner_id' => $this->partner->id, 'slug' => 'borrower', 'is_active' => true,
            'template_html' => "<html><body><div class=\"doc\">{$body}</div></body></html>",
        ]);
    }

    private function borrower(array $attributes = []): \App\Models\User\User
    {
        return $this->actingAsUser($this->makeBorrowerUser($this->partner, array_merge([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@doe.test', 'phone_number' => '(555) 123-4567',
            'home_address' => '9 Elm Rd', 'city' => 'Dallas', 'state' => 'TX', 'postal_code' => '75201',
            'business_name' => 'Doe Bakery LLC', 'business_address' => '5 Oak Ave',
        ], $attributes)));
    }

    #[Test]
    public function the_borrower_sees_the_agreement_filled_with_their_and_their_partners_details(): void
    {
        $this->borrowerTemplate(
            '<p>Between {{owner.company_name}} ({{owner.address}}) and {{signer.name}} of {{signer.business_name}}, '
            . '{{signer.address}}, {{signer.email}}, {{ signer.phone }}. Dated {{today}}.</p>'
        );
        $this->borrower();

        $html = $this->getJson('/api/v1/borrower/agreement-sign/check')->assertOk()->json('data.template.template_html');

        $this->assertStringContainsString(
            'Between Acme Lending (1 Main St, Austin, TX 78701) and Jane Doe of Doe Bakery LLC, '
            . '9 Elm Rd, Dallas, TX 75201, jane@doe.test, (555) 123-4567. Dated 10/30/2026.',
            $html
        );
    }

    #[Test]
    public function the_signed_record_keeps_the_filled_details(): void
    {
        $template = $this->borrowerTemplate('<p>Signed by {{signer.name}} for {{owner.company_name}}</p>'
            . '<div class="preview-dropzone"><input type="text" data-field-id="sig" required/></div>');
        $me = $this->borrower();

        $this->postJson('/api/v1/borrower/agreement-sign/submit', [
            'agreement_template_id' => $template->id, 'fields' => ['sig' => self::PNG],
        ])->assertOk();

        $html = AgreementSign::where('user_id', $me->id)->value('content_html');
        $this->assertStringContainsString('Signed by Jane Doe for Acme Lending', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    #[Test]
    public function details_are_written_as_text_never_as_markup(): void
    {
        $this->borrowerTemplate('<p>{{signer.business_name}}</p>');
        $this->borrower(['business_name' => '<script>steal()</script>Doe & Co']);

        $html = $this->getJson('/api/v1/borrower/agreement-sign/check')->json('data.template.template_html');

        $this->assertStringNotContainsString('<script>steal()', $html);
        $this->assertStringContainsString('&lt;script&gt;steal()&lt;/script&gt;Doe &amp; Co', $html);
    }

    #[Test]
    public function a_detail_the_signer_has_not_given_is_left_blank(): void
    {
        $this->borrowerTemplate('<p>[{{signer.business_address}}]</p>');
        $this->borrower(['business_address' => null]);

        $html = $this->getJson('/api/v1/borrower/agreement-sign/check')->json('data.template.template_html');

        $this->assertStringContainsString('<p>[]</p>', $html);
    }

    #[Test]
    public function an_unknown_or_sensitive_field_is_never_filled(): void
    {
        $this->borrowerTemplate('<p>{{signer.ssn}} {{signer.business_ein}} {{owner.stripe_customer_id}}</p>');
        $this->borrower(['business_ein' => '12-3456789']);

        $html = $this->getJson('/api/v1/borrower/agreement-sign/check')->json('data.template.template_html');

        $this->assertStringNotContainsString('12-3456789', $html);
        $this->assertStringContainsString('{{signer.ssn}} {{signer.business_ein}} {{owner.stripe_customer_id}}', $html);
    }

    #[Test]
    public function a_partner_signing_the_hosts_agreement_is_the_signer_and_the_host_the_owner(): void
    {
        AgreementTemplate::create([
            'partner_id' => $this->host->id, 'slug' => 'partner', 'is_active' => true,
            'template_html' => '<p>{{owner.company_name}} and {{signer.business_name}} ({{signer.name}})</p>',
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->makeCertifiedPartner(['company_name' => 'Certified Co', 'full_name' => 'Carl Cert'])));

        $html = $this->getJson('/api/v1/common/agreement-sign/check')->json('data.template.template_html');

        $this->assertStringContainsString('Four Corner Funding and Certified Co (Carl Cert)', $html);
    }

    /** Heading, list and table blocks from the builder are text blocks holding that markup. */
    #[Test]
    public function headings_lists_and_tables_keep_their_shape_and_fill_their_merge_fields(): void
    {
        $template = $this->borrowerTemplate(
            '<div class="p-text">Fees</div><ul><li>Borrower: {{signer.name}}</li></ul>'
            . '<table><thead><tr><th>Party</th><th>Name</th></tr></thead>'
            . '<tbody><tr><td>Lender</td><td>{{owner.company_name}}</td></tr></tbody></table>'
            . '<div class="preview-dropzone"><input type="text" data-field-id="sig" required/></div>'
        );
        $me = $this->borrower();

        $this->postJson('/api/v1/borrower/agreement-sign/submit', [
            'agreement_template_id' => $template->id, 'fields' => ['sig' => self::PNG],
        ])->assertOk();

        $html = AgreementSign::where('user_id', $me->id)->value('content_html');
        $this->assertStringContainsString('<ul><li>Borrower: Jane Doe</li></ul>', $html);
        $this->assertStringContainsString('<tr><td>Lender</td><td>Acme Lending</td></tr>', $html);
        $this->assertStringContainsString('<thead><tr><th>Party</th><th>Name</th></tr></thead>', $html);
    }

    #[Test]
    public function it_lists_the_fields_the_builder_offers(): void
    {
        $this->assertSame([
            'signer.name', 'signer.email', 'signer.phone', 'signer.address', 'signer.business_name', 'signer.business_address',
            'owner.company_name', 'owner.email', 'owner.phone', 'owner.address', 'today',
        ], AgreementMergeFields::KEYS);
    }
}
