<?php

namespace Tests\Feature\Configuration;

use App\Http\Controllers\Api\V1\FCH\Common\CommonController;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-115: `is_fresh_payment` drives the admin panel's Configuration Incomplete
 * modal. It was worked out for getConfigPartner(), which is the host for a
 * Certified partner - so a Certified partner with every gateway inactive was
 * reported as configured whenever the host had one active. Payments are each
 * partner's own; branding still comes from the host.
 */
#[CoversClass(CommonController::class)]
class PaymentConfigurationFlagTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private PaymentProvider $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->cash = PaymentProvider::create(['name' => PaymentProvider::CASH, 'display_name' => 'Cash Payment', 'status' => true]);
        $this->link($this->host, true);
    }

    private function link(Partner $partner, bool $active): void
    {
        PartnerPaymentProvider::create([
            'partner_id' => $partner->id,
            'payment_provider_id' => $this->cash->id,
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
            'status' => $active,
            'is_default' => false,
        ]);
    }

    private function paymentFlagFor(Partner $partner)
    {
        $this->actingAsUser($this->makePartnerAdmin($partner));

        return $this->getJson('/api/v1/common/partner-theme')->assertOk()->json('data.status_flags.is_fresh_payment');
    }

    #[Test]
    public function a_certified_partner_with_every_gateway_inactive_is_not_configured_even_when_the_host_is(): void
    {
        $certified = $this->makeCertifiedPartner();
        $this->link($certified, false);

        $this->assertNull($this->paymentFlagFor($certified));
    }

    #[Test]
    public function a_certified_partner_with_no_gateway_at_all_is_not_configured(): void
    {
        $this->assertNull($this->paymentFlagFor($this->makeCertifiedPartner()));
    }

    #[Test]
    public function a_certified_partner_with_an_active_gateway_is_configured(): void
    {
        $certified = $this->makeCertifiedPartner();
        $this->link($certified, true);

        $this->assertTrue($this->paymentFlagFor($certified));
    }

    #[Test]
    public function a_white_label_partner_still_answers_for_its_own_gateways(): void
    {
        $inactive = $this->makeWhiteLabelPartner();
        $this->link($inactive, false);
        $this->assertNull($this->paymentFlagFor($inactive));

        $active = $this->makeWhiteLabelPartner();
        $this->link($active, true);
        $this->assertTrue($this->paymentFlagFor($active));
    }

    #[Test]
    public function the_host_still_answers_for_its_own_gateways(): void
    {
        $this->assertTrue($this->paymentFlagFor($this->host));
    }
}
