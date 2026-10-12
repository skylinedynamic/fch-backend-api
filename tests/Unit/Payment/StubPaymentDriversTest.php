<?php

namespace Tests\Unit\Payment;

use App\Contracts\Payment\PaymentGatewayInterface;
use App\Services\GlobalPayment\Drivers\AuthorizeNetPaymentDriver;
use App\Services\GlobalPayment\Drivers\EcryptPaymentDriver;
use App\Services\GlobalPayment\Drivers\SquarePaymentDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Square, eCrypt and Authorize.Net drivers are not built.
 *
 * Defect (O52): their charge() returned success with a made-up transaction id,
 * so anything that routed one of these providers through its driver would have
 * marked an invoice paid with no money moved. Unbuilt, they now fail closed.
 */
class StubPaymentDriversTest extends TestCase
{
    public static function drivers(): array
    {
        return [
            'Square' => [SquarePaymentDriver::class],
            'eCrypt' => [EcryptPaymentDriver::class],
            'Authorize.Net' => [AuthorizeNetPaymentDriver::class],
        ];
    }

    private function driver(string $class): PaymentGatewayInterface
    {
        return (new $class())->initialize(['key' => 'x'], 'sandbox');
    }

    #[Test]
    #[DataProvider('drivers')]
    public function a_charge_never_reports_success(string $class): void
    {
        $result = $this->driver($class)->charge(['amount' => 100, 'currency' => 'usd']);

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('transaction_id', $result);
        $this->assertNotEmpty($result['error']);
    }

    #[Test]
    #[DataProvider('drivers')]
    public function customers_and_subscriptions_are_not_pretended_either(string $class): void
    {
        $driver = $this->driver($class);

        $this->assertFalse($driver->createCustomer(['email' => 'a@example.test'])['success']);
        $this->assertFalse($driver->createSubscription(['plan' => 'p'])['success']);
        $this->assertFalse($driver->cancelSubscription('sub_1'));
    }
}
