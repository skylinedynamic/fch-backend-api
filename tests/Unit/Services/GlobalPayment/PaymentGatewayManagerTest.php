<?php

namespace Tests\Unit\Services\GlobalPayment;

use App\Contracts\Payment\PaymentGatewayInterface;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\GlobalPayment\Drivers\AuthorizeNetPaymentDriver;
use App\Services\GlobalPayment\Drivers\EcryptPaymentDriver;
use App\Services\GlobalPayment\Drivers\SquarePaymentDriver;
use App\Services\GlobalPayment\Drivers\StripePaymentDriver;
use App\Services\GlobalPayment\PaymentGatewayManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class PaymentGatewayManagerTest extends TestCase
{
    use RefreshDatabase;

    protected PaymentGatewayManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // Testing-infrastructure gap (see FundingApplicationServiceTest for the
        // full explanation): no ServiceProvider registers
        // Factory::guessFactoryNamesUsing(), so namespaced models (Partner,
        // Borrower, ...) can't resolve their flat database/factories/*Factory
        // classes without this local override. Not fixed in shared
        // tests/TestCase.php to stay within this task's file scope.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->manager = new PaymentGatewayManager;
    }

    public function test_driver_resolves_stripe_driver_class(): void
    {
        $driver = $this->manager->driver(PaymentProvider::STRIPE);

        $this->assertInstanceOf(StripePaymentDriver::class, $driver);
        $this->assertInstanceOf(PaymentGatewayInterface::class, $driver);
    }

    public function test_driver_resolves_case_insensitive_lowercase_alias(): void
    {
        $this->assertInstanceOf(StripePaymentDriver::class, $this->manager->driver('stripe'));
        $this->assertInstanceOf(EcryptPaymentDriver::class, $this->manager->driver('ecrypt'));
        $this->assertInstanceOf(SquarePaymentDriver::class, $this->manager->driver('square'));
        $this->assertInstanceOf(AuthorizeNetPaymentDriver::class, $this->manager->driver('authorize_net'));
    }

    public function test_driver_throws_invalid_argument_exception_for_unsupported_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported payment gateway driver: NotAProvider');

        $this->manager->driver('NotAProvider');
    }

    public function test_resolve_partner_provider_returns_active_default_provider(): void
    {
        $partner = Partner::factory()->create();
        $provider = PaymentProvider::factory()->stripe()->create();

        $partnerProvider = PartnerPaymentProvider::factory()->create([
            'partner_id' => $partner->id,
            'payment_provider_id' => $provider->id,
            'status' => true,
            'is_default' => true,
        ]);

        $resolved = $this->manager->resolvePartnerProvider($partner->id);

        $this->assertTrue($resolved->is($partnerProvider));
    }

    public function test_resolve_partner_provider_ignores_inactive_providers(): void
    {
        $partner = Partner::factory()->create();
        $provider = PaymentProvider::factory()->stripe()->create();

        PartnerPaymentProvider::factory()->create([
            'partner_id' => $partner->id,
            'payment_provider_id' => $provider->id,
            'status' => false,
        ]);

        $this->expectException(RuntimeException::class);

        $this->manager->resolvePartnerProvider($partner->id);
    }

    public function test_resolve_partner_provider_throws_when_none_configured(): void
    {
        $partner = Partner::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active payment provider configured for partner ID: '.$partner->id);

        $this->manager->resolvePartnerProvider($partner->id);
    }

    public function test_resolve_partner_provider_filters_by_explicit_provider_id(): void
    {
        $partner = Partner::factory()->create();
        $stripeProvider = PaymentProvider::factory()->stripe()->create();
        $bankProvider = PaymentProvider::factory()->bankTransfer()->create();

        PartnerPaymentProvider::factory()->create([
            'partner_id' => $partner->id,
            'payment_provider_id' => $stripeProvider->id,
            'is_default' => false,
        ]);

        $bankPartnerProvider = PartnerPaymentProvider::factory()->create([
            'partner_id' => $partner->id,
            'payment_provider_id' => $bankProvider->id,
            'is_default' => true,
        ]);

        $resolved = $this->manager->resolvePartnerProvider($partner->id, $bankProvider->id);

        $this->assertTrue($resolved->is($bankPartnerProvider));
    }

    public function test_driver_for_initializes_driver_from_partner_provider_configuration(): void
    {
        $partner = Partner::factory()->create();
        $provider = PaymentProvider::factory()->stripe()->create();

        $partnerProvider = PartnerPaymentProvider::factory()->create([
            'partner_id' => $partner->id,
            'payment_provider_id' => $provider->id,
        ]);
        $partnerProvider->load('provider');

        $driver = $this->manager->driverFor($partnerProvider);

        $this->assertInstanceOf(StripePaymentDriver::class, $driver);
    }

    public function test_resolve_for_partner_resolves_and_initializes_driver_in_one_call(): void
    {
        $partner = Partner::factory()->create();
        $provider = PaymentProvider::factory()->stripe()->create();

        PartnerPaymentProvider::factory()->create([
            'partner_id' => $partner->id,
            'payment_provider_id' => $provider->id,
        ]);

        $driver = $this->manager->resolveForPartner($partner->id);

        $this->assertInstanceOf(PaymentGatewayInterface::class, $driver);
    }
}
