<?php

namespace Tests\Unit\Subscription;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Subscription::nextBilling() - what Subscription Details, My Subscription and
 * the borrower's billing page show as the next amount due. Its labels come from
 * lang/en/subscription.php (next_billing.*), not literals in the model.
 */
#[CoversClass(Subscription::class)]
class NextBillingTest extends TestCase
{
    private function subscription(array $attributes): Subscription
    {
        return (new Subscription())->forceFill(array_merge([
            'entity_type' => Partner::class,
            'installment_type' => 1,
            'installments_paid' => 0,
            'down_payment' => 1000.00,
            'payable_amount' => 7696.70,
            'total_subscription_amount' => 6696.70,
            'subscription_amount_per_month' => 558.06,
            'monthly_platform_fee' => 399.00,
            'subscription_start_date' => null,
            'subscription_end_date' => '2026-11-21',
            'enrollment_completed_at' => null,
        ], $attributes));
    }

    /** @return iterable<string, array{array<string, mixed>, string, float}> */
    public static function states(): iterable
    {
        yield 'installment, nothing paid yet: the down payment' => [[], 'down_payment', 1000.00];
        yield 'installment, down payment paid (BUG-126): the monthly installment' => [
            ['subscription_start_date' => '2026-10-21'], 'installment', 558.06,
        ];
        yield 'installment without a down payment, nothing paid: the monthly installment' => [
            ['down_payment' => 0], 'installment', 558.06,
        ];
        yield 'installment, a few installments paid: the monthly installment' => [
            ['subscription_start_date' => '2026-07-21', 'installments_paid' => 3], 'installment', 558.06,
        ];
        yield 'installment paid off: the platform fee' => [
            ['subscription_start_date' => '2025-10-21', 'enrollment_completed_at' => '2026-10-21'], 'platform_fee', 399.00,
        ];
        yield 'one time, not paid: the enrollment' => [
            ['installment_type' => 0, 'down_payment' => 0, 'payable_amount' => 6997.00], 'one_time_enrollment', 6997.00,
        ];
        yield 'one time, paid: the platform fee' => [
            ['installment_type' => 0, 'down_payment' => 0, 'subscription_start_date' => '2026-10-21', 'enrollment_completed_at' => '2026-10-21'], 'platform_fee', 399.00,
        ];
        yield 'borrower installment: the monthly installment' => [
            ['entity_type' => Borrower::class, 'down_payment' => 0], 'installment', 558.06,
        ];
        yield 'additional seats: the seat fee' => [
            ['entity_type' => 'App\Models\Employee\Employee', 'user_seats' => 2, 'per_seat_amount' => 49.00], 'seat_fee', 98.00,
        ];
    }

    #[Test]
    #[DataProvider('states')]
    public function it_names_and_prices_the_next_bill(array $attributes, string $label, float $amount): void
    {
        $next = $this->subscription($attributes)->nextBilling();

        $this->assertSame(__("subscription.next_billing.{$label}"), $next['type']);
        $this->assertSame($amount, $next['amount']);
    }

    #[Test]
    public function every_label_is_a_sentence_in_the_language_file(): void
    {
        foreach (['one_time_enrollment', 'platform_fee', 'down_payment', 'installment', 'seat_fee'] as $key) {
            $this->assertNotSame("subscription.next_billing.{$key}", __("subscription.next_billing.{$key}"), "lang/en/subscription.php is missing next_billing.{$key}");
        }
    }
}
