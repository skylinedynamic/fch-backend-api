<?php

namespace Tests\Feature\Agreement;

use App\Models\Borrower\Borrower;
use App\Models\Common\AgreementSign;
use App\Models\Common\AgreementTemplate;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\ManageAgreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Signing sends only the values entered (`fields`); the server builds the
 * signed record from the version signed and keeps a SHA-256 of it with the
 * signer's IP and browser. Whatever HTML the browser might send is ignored.
 */
#[CoversClass(AgreementService::class)]
class AgreementServerSignedRecordTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const TEMPLATE = '<html><body><form id="agreement-form"><div class="doc"><div class="p-text">Loan terms</div>'
        . '<input type="text" class="p-input" data-field-id="name" required/>'
        . '<div class="p-sig-box preview-dropzone" data-block-id="sig"><input type="text" class="p-input" data-field-id="sig" required/></div>'
        . '</div></form><script>paginate()</script></body></html>';

    private Partner $partner;

    private AgreementTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['agreement.image_disk' => 'local', 'agreement.image_directory' => 'agreement-images']);

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->template = AgreementTemplate::create([
            'partner_id' => $this->partner->id, 'slug' => 'borrower', 'is_active' => true, 'template_html' => self::TEMPLATE,
        ]);
    }

    private function submit(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('User-Agent', 'Mozilla/5.0 Test')->postJson('/api/v1/borrower/agreement-sign/submit', $payload + [
            'agreement_template_id' => $this->template->id,
            'agreement_template_version_id' => $this->template->currentVersion()->id,
        ]);
    }

    #[Test]
    public function the_server_builds_the_signed_record_from_the_version_and_the_values(): void
    {
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->submit(['fields' => ['name' => 'Jane Doe', 'sig' => self::PNG]])->assertOk();

        $sign = AgreementSign::where('user_id', $me->id)->firstOrFail();
        $this->assertStringContainsString('Loan terms', $sign->content_html);
        $this->assertStringContainsString('Jane Doe', $sign->content_html);
        $this->assertStringContainsString('<img src="' . self::PNG . '"', $sign->content_html);
        $this->assertStringNotContainsString('<script', $sign->content_html);
        $this->assertSame(hash('sha256', $sign->content_html), $sign->content_hash);
        $this->assertSame('Mozilla/5.0 Test', $sign->user_agent);
        $this->assertTrue((bool) Borrower::find($me->entity_id)->has_sign);
    }

    #[Test]
    public function html_sent_by_the_browser_is_ignored(): void
    {
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->submit([
            'fields' => ['name' => 'Jane Doe', 'sig' => self::PNG],
            'content_html' => '<p>The lender owes the borrower $1,000,000</p>',
        ])->assertOk();

        $this->assertStringNotContainsString('owes the borrower', AgreementSign::where('user_id', $me->id)->value('content_html'));
    }

    #[Test]
    public function the_entered_values_are_kept_without_the_signature_image_itself(): void
    {
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->submit(['fields' => ['name' => 'Jane Doe', 'sig' => self::PNG]])->assertOk();

        $values = json_decode(DB::table('agreement_signs')->where('user_id', $me->id)->value('field_values'), true);
        $this->assertSame('Jane Doe', $values['name']);
        $this->assertStringStartsWith('agreement-image:', $values['sig']);
    }

    #[Test]
    public function signing_with_a_required_field_empty_is_refused_naming_it(): void
    {
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->submit(['fields' => ['name' => 'Jane Doe']])
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('errors.fields', ['sig']);

        $this->assertFalse((bool) Borrower::find($me->entity_id)->has_sign);
        $this->assertDatabaseCount('agreement_signs', 0);
    }

    #[Test]
    public function a_partner_signing_the_hosts_agreement_is_built_the_same_way(): void
    {
        $host = Partner::where('is_host', true)->firstOrFail();
        $hosts = AgreementTemplate::create(['partner_id' => $host->id, 'slug' => 'partner', 'is_active' => true, 'template_html' => self::TEMPLATE]);
        $signer = $this->actingAsUser($this->makePartnerAdmin($this->makeCertifiedPartner()));

        $this->postJson('/api/v1/common/agreement-sign/submit', [
            'agreement_template_id' => $hosts->id,
            'fields' => ['name' => 'Acme Partner', 'sig' => self::PNG],
            'content_html' => '<p>forged</p>',
        ])->assertOk();

        $html = AgreementSign::where('user_id', $signer->id)->value('content_html');
        $this->assertStringContainsString('Acme Partner', $html);
        $this->assertStringNotContainsString('forged', $html);
    }

    #[Test]
    public function the_signed_record_ends_with_a_signature_certificate(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-30 15:04:00');
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner, ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@doe.test']));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->submit(['fields' => ['name' => 'Jane Doe', 'sig' => self::PNG]])->assertOk();

        $html = AgreementSign::where('user_id', $me->id)->value('content_html');
        $this->assertStringContainsString('Signature certificate', $html);
        foreach (['Jane Doe', 'jane@doe.test', 'October 30, 2026 3:04 PM UTC', '203.0.113.9', 'Version 1'] as $detail) {
            $this->assertStringContainsString($detail, $html);
        }
        \Illuminate\Support\Carbon::setTestNow();
    }
}
