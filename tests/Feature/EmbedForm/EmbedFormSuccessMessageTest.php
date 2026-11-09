<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Common\AppConfiguration;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Defect (SCRUM-435 S435-BUG-02): the confirmation said the team "will contact
 * you shortly", a response-time promise the brief does not allow. It must
 * explain the next step without promising a time, approval or funding, and name
 * whichever company the form belongs to.
 */
class EmbedFormSuccessMessageTest extends TestCase
{
    use RefreshDatabase;

    private function partner(string $companyName, bool $isHost = false, ?string $siteName = null): Partner
    {
        $partner = Partner::create([
            'is_host' => $isHost,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => $companyName,
            'full_name' => $companyName,
            'email' => strtolower(preg_replace('/\W/', '', $companyName)) . '@example.test',
        ]);

        if ($siteName !== null) {
            AppConfiguration::create([
                'partner_id' => $partner->id,
                'module' => AppConfiguration::MODULE_BRANDING,
                'key' => 'site_name',
                'value' => $siteName,
            ]);
        }

        return $partner;
    }

    private function formPage(Partner $partner): string
    {
        $config = EmbedFormConfig::create([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Funding Application',
            'is_active' => true,
        ]);

        return $this->get('/form/' . $config->public_key)->assertOk()->getContent();
    }

    private function successScreen(string $companyName, ?Partner $partner = null): string
    {
        $page = $this->formPage($partner ?? $this->partner($companyName));

        $matched = preg_match('/<div id="successScreen".*?id="successRefBadge"/s', $page, $m);
        $this->assertSame(1, $matched, 'The success screen was not found');

        return preg_replace('/\s+/', ' ', $m[0]);
    }

    #[Test]
    public function the_confirmation_promises_no_response_time(): void
    {
        $screen = strtolower($this->successScreen('Acme Capital'));

        foreach (['shortly', 'soon', 'within', 'hours', 'business day'] as $promise) {
            $this->assertStringNotContainsString($promise, $screen);
        }
    }

    #[Test]
    public function the_confirmation_explains_the_next_step_and_guarantees_nothing(): void
    {
        $screen = $this->successScreen('Acme Capital');

        $this->assertStringContainsString('has received your application and will review it.', $screen);
        $this->assertStringContainsString("we'll contact you using the details you provided.", $screen);
        $this->assertStringContainsString('Submitting an application does not guarantee approval or funding.', $screen);
    }

    #[Test]
    public function the_confirmation_names_the_company_the_form_belongs_to(): void
    {
        $this->assertStringContainsString(
            'Thank you for applying with <strong>Acme Capital</strong>.',
            $this->successScreen('Acme Capital'),
        );
    }

    #[Test]
    public function another_partners_form_names_that_partner_instead(): void
    {
        $screen = $this->successScreen('Northwind Lending');

        $this->assertStringContainsString('Thank you for applying with <strong>Northwind Lending</strong>.', $screen);
        $this->assertStringNotContainsString('Four Corner Funding', $screen);
    }

    #[Test]
    public function a_partners_own_site_name_comes_before_its_company_name(): void
    {
        $this->partner('Host Holdings', true, 'Host Brand');
        $partner = $this->partner('Acme Capital LLC', false, 'Acme Capital');

        $this->assertStringContainsString(
            'Thank you for applying with <strong>Acme Capital</strong>.',
            $this->successScreen('Acme Capital LLC', $partner),
        );
    }

    /**
     * Defect: a partner with no site_name of its own was shown the host's, so an
     * applicant on that partner's form was thanked for applying with another
     * company.
     */
    #[Test]
    public function a_partner_with_no_site_name_shows_its_company_name_not_the_hosts_name(): void
    {
        $this->partner('Host Holdings', true, 'Host Brand');
        $partner = $this->partner('Acme Capital LLC');

        $screen = $this->successScreen('Acme Capital LLC', $partner);

        $this->assertStringContainsString('Thank you for applying with <strong>Acme Capital LLC</strong>.', $screen);
        $this->assertStringNotContainsString('Host Brand', $screen);
    }

    #[Test]
    public function the_host_form_shows_the_hosts_site_name(): void
    {
        $host = $this->partner('Host Holdings', true, 'Host Brand');

        $this->assertStringContainsString(
            'Thank you for applying with <strong>Host Brand</strong>.',
            $this->successScreen('Host Holdings', $host),
        );
    }

    /**
     * Defect: the same name is in the consents, so an applicant on a partner's
     * form with no site_name authorized the host instead of that partner.
     */
    #[Test]
    public function the_authorizations_name_the_partner_too_not_the_host(): void
    {
        $this->partner('Host Holdings', true, 'Host Brand');

        $page = preg_replace('/\s+/', ' ', $this->formPage($this->partner('Acme Capital LLC')));

        $this->assertStringContainsString('I authorize Acme Capital LLC and its funding partners', $page);
        $this->assertStringContainsString('I authorize Acme Capital LLC to verify', $page);
        $this->assertStringNotContainsString('Host Brand', $page);
    }

    /**
     * Defect (SCRUM-435 S435-BUG-06): Bootstrap's .badge never wraps, so on a
     * phone the Reference ID ran past the form and was clipped. Measured in a
     * real browser by fch-website-react's e2e/apply-confirmation-mobile.spec.ts.
     */
    #[Test]
    public function the_reference_id_may_wrap_and_stays_within_the_form(): void
    {
        $page = $this->formPage($this->partner('Acme Capital'));

        $this->assertSame(1, preg_match('/#successRefBadge\s*\{(.*?)\}/s', $page, $m), 'The badge has no rule of its own');
        $this->assertStringContainsString('white-space: normal', $m[1]);
        $this->assertStringContainsString('overflow-wrap: anywhere', $m[1]);
        $this->assertStringContainsString('max-width: 100%', $m[1]);
    }

    #[Test]
    public function a_company_name_is_escaped_on_the_confirmation(): void
    {
        $this->assertStringContainsString(
            '<strong>Smith &amp; Sons</strong>',
            $this->successScreen('Smith & Sons'),
        );
    }
}
