<?php

namespace Tests\Feature\Payment;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E2E-017: the portals carry card forms (AuthorizeNetForm, SquareForm,
 * EcryptForm) that post the raw card number, expiry and CVV in payment_token.
 * They are not wired into any screen today - Square, Authorize.Net and Ecrypt
 * are taken as manual payments with a reference number, like cash - but the
 * API accepted such a payload. Raw card data is now refused outright, while
 * the manual flow for those providers keeps working.
 */
class NoRawCardPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private User $partnerUser;
    /** @var array<string, PaymentProvider> */
    private array $providers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        foreach ([PaymentProvider::STRIPE, PaymentProvider::SQUARE, PaymentProvider::AUTHORIZE_NET, PaymentProvider::ECRYPT, PaymentProvider::CASH] as $name) {
            $this->providers[$name] = PaymentProvider::create(['name' => $name, 'display_name' => $name, 'status' => true]);
            PartnerPaymentProvider::create([
                'partner_id' => $this->host->id,
                'payment_provider_id' => $this->providers[$name]->id,
                'payment_mode' => PaymentProvider::MODE_SANDBOX,
                'status' => true,
                'is_default' => $name === PaymentProvider::STRIPE,
                'configuration' => ['sandbox' => ['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x']],
            ]);
        }

        $this->partnerUser = User::create([
            'name' => 'Partner Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
        ]);

        Passport::actingAs($this->partnerUser);
    }

    private function unpaidInvoice(): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-TEST-' . uniqid(),
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
            'customer_name' => 'Host Owner',
            'email' => 'host@example.test',
            'payment_amount' => 10,
            'month_count' => 1,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1,
        ]);
    }

    #[Test]
    public function the_manual_providers_are_still_offered(): void
    {
        $names = collect($this->getJson('/api/v1/payment/get-payment-methods')->assertOk()->json('data'))->pluck('name');

        foreach ([PaymentProvider::STRIPE, PaymentProvider::SQUARE, PaymentProvider::AUTHORIZE_NET, PaymentProvider::ECRYPT, PaymentProvider::CASH] as $name) {
            $this->assertContains($name, $names);
        }
    }

    #[Test]
    public function a_manual_payment_with_a_reference_number_still_goes_through(): void
    {
        $invoice = $this->unpaidInvoice();

        $this->postJson('/api/v1/payment/process', [
            'invoice_id' => $invoice->id,
            'payment_method_id' => $this->providers[PaymentProvider::SQUARE]->id,
            'payment_token' => json_encode(['gateway' => 'square', 'mode' => 'manual', 'transaction_number' => 'SQ-123']),
        ])->assertOk();

        $this->assertSame(Invoice::STATUS_PROCESSING, $invoice->fresh()->status);
    }

    #[Test]
    public function raw_card_data_is_refused_whatever_the_provider(): void
    {
        $invoice = $this->unpaidInvoice();

        $this->postJson('/api/v1/payment/process', [
            'invoice_id' => $invoice->id,
            'payment_method_id' => $this->providers[PaymentProvider::CASH]->id,
            'payment_token' => json_encode(['gateway' => 'authorize_net', 'cardNumber' => '4111111111111111', 'expMonth' => '12', 'expYear' => '34', 'cardCode' => '123']),
        ])->assertStatus(422);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }
}
