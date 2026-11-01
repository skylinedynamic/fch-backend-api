<?php

namespace Tests\Feature\Setting;

use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect: BUG-115. A Certified partner with every payment gateway switched
 * off was never stopped by the Configuration Incomplete modal: the
 * is_fresh_payment flag was worked out for the partner whose branding the
 * portal shows, and a Certified partner trades under the host's brand, so the
 * flag reported the host's gateways (always on) instead of its own.
 */
class PaymentConfigurationGuardFlagTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private PaymentProvider $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cash = PaymentProvider::create(['name' => PaymentProvider::CASH, 'display_name' => 'Cash', 'status' => true]);
        // The host always has a gateway on.
        $this->gateway($this->makeHostPartner(), true);
    }

    private function gateway(Partner $partner, bool $on): void
    {
        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($partner->id, [
            'payment_provider_id' => $this->cash->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => $on,
            'configuration' => [],
        ]);
    }

    private function paymentFlag(Partner $partner)
    {
        $this->actingAsUser($this->makePartnerAdmin($partner));

        return $this->getJson('/api/v1/common/partner-theme')->assertOk()->json('data.status_flags.is_fresh_payment');
    }

    #[Test]
    public function a_certified_partner_with_every_gateway_off_is_missing_payment(): void
    {
        $certified = $this->makeCertifiedPartner();
        $this->gateway($certified, false);

        $this->assertNull($this->paymentFlag($certified));
    }

    #[Test]
    public function a_certified_partner_with_no_gateway_saved_is_missing_payment(): void
    {
        $this->assertNull($this->paymentFlag($this->makeCertifiedPartner()));
    }

    #[Test]
    public function a_certified_partner_with_a_gateway_on_has_payment(): void
    {
        $certified = $this->makeCertifiedPartner();
        $this->gateway($certified, true);

        $this->assertTrue($this->paymentFlag($certified));
    }

    #[Test]
    public function a_white_label_partner_is_judged_on_its_own_gateways_as_before(): void
    {
        $whiteLabel = $this->makeWhiteLabelPartner();
        $this->assertNull($this->paymentFlag($whiteLabel));

        $this->gateway($whiteLabel, true);
        $this->assertTrue($this->paymentFlag($whiteLabel));
    }
}
