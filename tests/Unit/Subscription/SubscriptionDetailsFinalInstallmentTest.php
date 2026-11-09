<?php

namespace Tests\Unit\Subscription;

use App\Http\Resources\FCH\Subscription\SubscriptionDetailsResource;
use App\Models\Borrower\Borrower;
use App\Models\Subscription\Subscription;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-049: the Subscription Details page shows the last installment when it is
 * adjusted for rounding, and next billing names that amount when it is next.
 */
#[CoversClass(SubscriptionDetailsResource::class)]
class SubscriptionDetailsFinalInstallmentTest extends TestCase
{
    private function details(array $attributes): array
    {
        $subscription = (new Subscription())->forceFill(array_merge([
            'entity_type' => Borrower::class,
            'installment_type' => 1,
            'installment_count' => 12,
            'installments_paid' => 0,
            'down_payment' => 0,
            'payable_amount' => 6696.70,
            'total_subscription_amount' => 6696.70,
            'subscription_amount_per_month' => 558.06,
            'subscription_end_date' => '2026-11-21',
        ], $attributes));

        return (new SubscriptionDetailsResource($subscription))->toArray(Request::create('/'));
    }

    #[Test]
    public function it_shows_the_adjusted_last_installment(): void
    {
        $details = $this->details([]);

        $this->assertSame('$558.06', $details['subscription_amount_per_month']);
        $this->assertSame('$558.04', $details['final_installment_amount']);
        $this->assertSame('$558.06', $details['next_billing_amount']);
    }

    #[Test]
    public function next_billing_is_the_adjusted_amount_when_the_last_installment_is_next(): void
    {
        $this->assertSame('$558.04', $this->details(['installments_paid' => 11])['next_billing_amount']);
    }

    #[Test]
    public function an_even_split_has_no_separate_last_installment(): void
    {
        $details = $this->details(['total_subscription_amount' => 6696.00, 'subscription_amount_per_month' => 558.00]);

        $this->assertNull($details['final_installment_amount']);
    }
}
