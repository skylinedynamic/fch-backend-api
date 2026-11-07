<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Controllers\Api\V1\Global\Website\EmbedForm\PublicEmbedFormController;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The marketing site's /apply page embeds the host's application form. It
 * asks this public endpoint where that form lives instead of hard-coding a
 * key that changes whenever the host regenerates it.
 */
#[CoversClass(PublicEmbedFormController::class)]
class WebsiteApplyFormTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/website/embed-form';

    private function partner(bool $isHost): Partner
    {
        return Partner::create([
            'is_host' => $isHost,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Co ' . uniqid(),
            'full_name' => 'Co',
            'email' => 'p-' . uniqid() . '@example.test',
        ]);
    }

    private function form(Partner $partner, bool $active = true): EmbedFormConfig
    {
        return EmbedFormConfig::create([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Business Loan Application',
            'is_active' => $active,
            'allowed_domains' => ['https://secret-partner.example'],
            'notification_email' => 'ops@example.test',
        ]);
    }

    #[Test]
    public function it_returns_the_host_forms_title_and_link(): void
    {
        config(['app.base_website' => 'fourcornerfunding.com']);
        $this->form($this->partner(false));
        $form = $this->form($this->partner(true));

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.form_title', 'Business Loan Application')
            ->assertJsonPath('data.form_url', 'https://fourcornerfunding.com/form/' . $form->public_key);
    }

    #[Test]
    public function it_shares_nothing_beyond_what_the_public_page_needs(): void
    {
        $this->form($this->partner(true));

        $data = $this->getJson(self::ENDPOINT)->assertOk()->json('data');

        $this->assertSame(['form_title', 'form_url'], array_keys($data));
    }

    #[Test]
    public function an_inactive_host_form_is_not_offered(): void
    {
        $this->form($this->partner(true), active: false);

        $this->getJson(self::ENDPOINT)->assertNotFound();
    }

    #[Test]
    public function asking_does_not_create_a_form_for_a_host_without_one(): void
    {
        $this->partner(true);

        $this->getJson(self::ENDPOINT)->assertNotFound();
        $this->assertSame(0, EmbedFormConfig::count());
    }
}
