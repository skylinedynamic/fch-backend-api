<?php

namespace Tests\Feature\Partner;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A white-label partner's setup check (Controller::checkWhiteLabelConfiguration)
 * before it creates anything - a client here.
 *
 * Mail configuration is not part of it: until the partner's own is verified,
 * its mail goes out with the platform's fallback configuration, and the portal
 * says so on every page. Only the branding has to be done first.
 */
class WhiteLabelSetupCheckTest extends TestCase
{
    use RefreshDatabase;

    private function whiteLabelPartner(): Partner
    {
        Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Co',
            'full_name' => 'Host Owner',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        return Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Brand New WLP',
            'full_name' => 'Wendy Partner',
            'email' => 'wlp-' . uniqid() . '@example.test',
        ]);
    }

    private function signInAs(Partner $partner): void
    {
        Passport::actingAs(User::create([
            'name' => 'Wendy Partner',
            'email' => 'wendy-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::PARTNER,
            'partner_id' => $partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
        ]));
    }

    private function brand(Partner $partner): void
    {
        AppConfiguration::create([
            'partner_id' => $partner->id,
            'module' => AppConfiguration::MODULE_BRANDING,
            'key' => 'site_name',
            'value' => 'Brand New WLP',
        ]);
    }

    private function newClient(): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada-' . uniqid() . '@example.test',
            'phone_number' => '(555) 234-5678',
            'country' => 'United States',
            'business_country' => 'United States',
            'is_free_trail' => true,
            'free_trail_end_date' => now()->addDays(14)->toDateString(),
        ];
    }

    #[Test]
    public function a_white_label_partner_with_no_mail_configuration_can_create_a_client(): void
    {
        $partner = $this->whiteLabelPartner();
        $this->brand($partner);
        $this->signInAs($partner);

        $this->postJson('/api/v1/common/borrowers', $this->newClient())->assertCreated();
    }

    #[Test]
    public function the_branding_is_still_required_and_the_message_asks_for_only_that(): void
    {
        $partner = $this->whiteLabelPartner();
        $this->signInAs($partner);

        $this->postJson('/api/v1/common/borrowers', $this->newClient())
            ->assertForbidden()
            ->assertJsonPath('requires_configuration', true)
            ->assertJsonPath('message', 'Please complete your Branding configuration to unlock these features.');
    }
}
