<?php

namespace Tests\Feature\Payment;

use App\Http\Requests\Api\V1\FCH\Common\Setting\StorePartnerPaymentProviderRequest;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PaymentProvider;
use App\Rules\RoutingNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Settings -> Payment Method -> Bank Transfer: the routing number a partner
 * gives its borrowers is a nine-digit ABA number. It stays optional.
 */
#[CoversClass(StorePartnerPaymentProviderRequest::class)]
#[CoversClass(RoutingNumber::class)]
class PartnerBankDetailsRoutingNumberTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private PaymentProvider $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->bank = PaymentProvider::create([
            'name' => PaymentProvider::BANK_TRANSFER,
            'display_name' => 'Bank Transfer',
            'status' => true,
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));
    }

    private function save(?string $routingNumber)
    {
        return $this->postJson('/api/v1/common/partner-payment-providers', [
            'payment_provider_id' => $this->bank->id,
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
            'status' => true,
            'bank_details' => [[
                'bank_name' => 'First Bank',
                'account_name' => 'Acme LLC',
                'account_number' => '000123456789',
                'routing_number' => $routingNumber,
                'payment_mode' => PaymentProvider::MODE_SANDBOX,
            ]],
        ]);
    }

    #[Test]
    public function a_routing_number_that_is_not_nine_digits_is_rejected(): void
    {
        foreach (['12345', '1234567890', '12100035A', '121-000-358'] as $bad) {
            $this->save($bad)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['bank_details.0.routing_number']);
        }

        $this->assertDatabaseCount('payment_method_bank_details', 0);
    }

    #[Test]
    public function a_nine_digit_routing_number_is_saved_with_its_leading_zero(): void
    {
        $this->save('021000021')->assertOk();

        $this->assertDatabaseHas('payment_method_bank_details', ['routing_number' => '021000021']);
    }

    #[Test]
    public function the_routing_number_stays_optional(): void
    {
        $this->save(null)->assertOk();

        $this->assertDatabaseHas('payment_method_bank_details', ['bank_name' => 'First Bank', 'routing_number' => null]);
    }
}
