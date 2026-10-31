<?php

namespace Tests\Feature\Agreement;

use App\Casts\AgreementImages;
use App\Models\Common\AgreementSign;
use App\Models\Common\AgreementTemplate;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Agreement templates and signed agreements keep their images on disk, not
 * inside the HTML/JSON in the database. The API still sends and receives the
 * same HTML/JSON as before, so neither portal changes.
 */
#[CoversClass(AgreementImages::class)]
class AgreementImageStorageTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Partner $host;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['agreement.image_disk' => 'local', 'agreement.image_directory' => 'agreement-images']);

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        DB::table('app_configurations')->insert([
            'partner_id' => $this->host->id,
            'module' => 'agreement',
            'key' => 'agreement_signing_enabled',
            'value' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function logo(): string
    {
        return 'data:image/png;base64,' . self::PNG;
    }

    private function reference(): string
    {
        return 'agreement-image:' . hash('sha256', base64_decode(self::PNG)) . '.png';
    }

    #[Test]
    public function a_saved_template_keeps_its_logo_on_disk_once_and_reads_back_unchanged(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $html = '<div class="doc"><img src="' . $this->logo() . '" class="p-logo-img"/><p>Terms</p></div>';
        $json = json_encode(['blocks' => [['type' => 'logo', 'logoSrc' => $this->logo()]], 'pagePad' => 80], JSON_UNESCAPED_SLASHES);

        $id = $this->postJson('/api/v1/common/agreement-templates/save', [
            'slug' => 'borrower', 'template_html' => $html, 'template_json' => $json,
        ])->assertOk()->json('data.id');

        $row = DB::table('agreement_templates')->find($id);
        $this->assertStringNotContainsString('base64', $row->template_html);
        $this->assertStringNotContainsString('base64', $row->template_json);
        $this->assertStringContainsString($this->reference(), $row->template_html);
        $this->assertCount(1, Storage::disk('local')->allFiles('agreement-images'));

        $this->postJson('/api/v1/common/agreement-templates/show', ['slug' => 'borrower'])
            ->assertOk()
            ->assertJsonPath('data.template_html', $html)
            ->assertJsonPath('data.template_json', $json);
    }

    #[Test]
    public function the_agreement_a_borrower_is_asked_to_sign_carries_its_images(): void
    {
        AgreementTemplate::create([
            'partner_id' => $this->partner->id, 'slug' => 'borrower', 'is_active' => true,
            'template_html' => '<img src="' . $this->logo() . '"/>',
        ]);
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->getJson('/api/v1/borrower/agreement-sign/check')
            ->assertOk()
            ->assertJsonPath('data.needs_sign', true)
            ->assertJsonPath('data.template.template_html', '<img src="' . $this->logo() . '"/>');
    }

    #[Test]
    public function a_signed_agreement_keeps_the_signature_image_on_disk_and_reads_back_unchanged(): void
    {
        $template = AgreementTemplate::create([
            'partner_id' => $this->partner->id, 'slug' => 'borrower', 'is_active' => true, 'template_html' => '<p>Terms</p>',
        ]);
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner));
        $signed = '<html><body><p>Terms</p><img src="' . $this->logo() . '" alt="signature"/></body></html>';

        $this->postJson('/api/v1/borrower/agreement-sign/submit', [
            'agreement_template_id' => $template->id, 'content_html' => $signed,
        ])->assertOk();

        $stored = DB::table('agreement_signs')->where('user_id', $me->id)->value('content_html');
        $this->assertStringNotContainsString('base64', $stored);
        $this->assertStringContainsString($this->reference(), $stored);
        $this->assertSame($signed, AgreementSign::where('user_id', $me->id)->first()->content_html);

        $this->getJson('/api/v1/borrower/agreement-sign/signed-html')
            ->assertOk()
            ->assertJsonPath('data.content_html', $signed);
    }

    #[Test]
    public function a_signed_agreement_still_downloads_as_a_pdf(): void
    {
        $template = AgreementTemplate::create([
            'partner_id' => $this->partner->id, 'slug' => 'borrower', 'is_active' => true, 'template_html' => '<p>Terms</p>',
        ]);
        $this->actingAsUser($this->makeBorrowerUser($this->partner));
        $this->postJson('/api/v1/borrower/agreement-sign/submit', [
            'agreement_template_id' => $template->id,
            'content_html' => '<p>Terms</p><img src="' . $this->logo() . '"/>',
        ])->assertOk();

        $response = $this->get('/api/v1/borrower/agreement-sign/download');

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
