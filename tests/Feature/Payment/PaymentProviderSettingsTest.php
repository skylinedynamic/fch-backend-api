<?php

namespace Tests\Feature\Payment;

use App\Http\Controllers\Api\V1\FCH\Admin\PaymentMethod\PaymentMethodController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\PartnerPaymentProviderController;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Which payment gateways exist at all (admin/payment-methods - the host's
 * switch for the whole platform) and which a partner offers its borrowers
 * (common/partner-payment-providers/status - each partner's own switch).
 */
#[CoversClass(PaymentMethodController::class)]
#[CoversClass(PartnerPaymentProviderController::class)]
class PaymentProviderSettingsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private PaymentProvider $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);
    }

    #[Test]
    public function the_host_admin_lists_every_gateway_on_the_platform(): void
    {
        PaymentProvider::create(['name' => PaymentProvider::CASH, 'display_name' => 'Cash', 'status' => false]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson('/api/v1/admin/payment-methods')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'logo', 'status', 'slug']]]);
    }

    #[Test]
    public function the_host_admin_switches_a_gateway_off_for_the_whole_platform(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson("/api/v1/admin/payment-methods/{$this->stripe->id}", ['status' => 'false'])
            ->assertOk()
            ->assertJsonPath('data.status', false);

        $this->assertFalse((bool) $this->stripe->fresh()->status);
    }

    #[Test]
    public function updating_an_unknown_gateway_fails(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson('/api/v1/admin/payment-methods/999999', ['status' => true])->assertStatus(400);
    }

    #[Test]
    public function the_platform_gateways_are_the_host_partner_accounts_alone(): void
    {
        // host staff: user_type Admin (passes the admin middleware) but not the partner itself
        $this->actingAsUser($this->makeStaffUser($this->host));
        $this->getJson('/api/v1/admin/payment-methods')->assertForbidden();
        $this->postJson("/api/v1/admin/payment-methods/{$this->stripe->id}", ['status' => false])->assertForbidden();

        // a non-host partner never gets past the admin middleware
        $this->actingAsUser($this->makePartnerAdmin($this->makeWhiteLabelPartner()));
        $this->getJson('/api/v1/admin/payment-methods')->assertForbidden();

        $this->assertTrue((bool) $this->stripe->fresh()->status);
    }

    #[Test]
    public function the_admin_routes_need_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/admin/payment-methods')->assertUnauthorized();
        $this->postJson("/api/v1/admin/payment-methods/{$this->stripe->id}")->assertUnauthorized();
    }

    #[Test]
    public function a_partner_turns_a_gateway_on_for_its_borrowers_creating_the_link_if_needed(): void
    {
        $partner = $this->makeWhiteLabelPartner();
        $this->actingAsUser($this->makePartnerAdmin($partner));

        $this->postJson('/api/v1/common/partner-payment-providers/status', [
            'payment_provider_id' => $this->stripe->id,
            'status' => true,
        ])->assertOk();

        $this->assertDatabaseHas('partner_payment_providers', [
            'partner_id' => $partner->id,
            'payment_provider_id' => $this->stripe->id,
            'status' => true,
        ]);
    }

    #[Test]
    public function a_partner_switching_a_gateway_off_touches_only_its_own_link(): void
    {
        $partner = $this->makeWhiteLabelPartner();
        $other = $this->makeWhiteLabelPartner();
        foreach ([$partner, $other] as $p) {
            PartnerPaymentProvider::create([
                'partner_id' => $p->id,
                'payment_provider_id' => $this->stripe->id,
                'status' => true,
                'is_default' => true,
                'configuration' => [],
            ]);
        }
        $this->actingAsUser($this->makePartnerAdmin($partner));

        $this->postJson('/api/v1/common/partner-payment-providers/status', [
            'payment_provider_id' => $this->stripe->id,
            'status' => false,
        ])->assertOk();

        $status = fn (Partner $p) => (bool) PartnerPaymentProvider::where('partner_id', $p->id)->value('status');
        $this->assertFalse($status($partner));
        $this->assertTrue($status($other));
    }

    #[Test]
    public function the_status_switch_validates_its_input(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeWhiteLabelPartner()));

        $this->postJson('/api/v1/common/partner-payment-providers/status', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_provider_id', 'status']);
        $this->postJson('/api/v1/common/partner-payment-providers/status', ['payment_provider_id' => 999999, 'status' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_provider_id', 'status']);
    }

    #[Test]
    public function the_status_switch_needs_a_signed_in_user(): void
    {
        $this->postJson('/api/v1/common/partner-payment-providers/status')->assertUnauthorized();
    }
}
