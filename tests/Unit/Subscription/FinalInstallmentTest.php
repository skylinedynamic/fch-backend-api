<?php

namespace Tests\Unit\Subscription;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-049: the monthly installment is the balance divided by the months,
 * rounded to the cent, and was billed every month - so the installments added
 * up to a few cents more (or less) than the plan. The last installment now
 * takes up the difference: balance - monthly x (months - 1).
 *
 * 6696.70 over 12 months: 558.06 a month, 12 x 558.06 = 6696.72 (2 cents over);
 * the 12th installment is 6696.70 - 11 x 558.06 = 558.04.
 */
#[CoversClass(Subscription::class)]
class FinalInstallmentTest extends TestCase
{
    private function subscription(array $attributes = []): Subscription
    {
        return (new Subscription())->forceFill(array_merge([
            'entity_type' => Partner::class,
            'installment_type' => 1,
            'installment_count' => 12,
            'installments_paid' => 0,
            'down_payment' => 1000.00,
            'payable_amount' => 7696.70,
            'total_subscription_amount' => 6696.70,
            'subscription_amount_per_month' => 558.06,
            'monthly_platform_fee' => 399.00,
            'subscription_start_date' => '2026-01-21',
            'subscription_end_date' => '2026-11-21',
            'enrollment_completed_at' => null,
        ], $attributes));
    }

    #[Test]
    public function the_last_installment_takes_up_the_rounding(): void
    {
        $subscription = $this->subscription();

        $this->assertSame(558.04, $subscription->finalInstallmentAmount());
        $this->assertSame(-0.02, $subscription->finalInstallmentAdjustment());
    }

    #[Test]
    public function a_balance_short_by_rounding_down_is_made_up_on_the_last_installment(): void
    {
        // 1000 over 3: 333.33 a month, 3 x 333.33 = 999.99 - the last one is 333.34.
        $subscription = $this->subscription([
            'installment_count' => 3,
            'total_subscription_amount' => 1000.00,
            'subscription_amount_per_month' => 333.33,
        ]);

        $this->assertSame(333.34, $subscription->finalInstallmentAmount());
        $this->assertSame(0.01, $subscription->finalInstallmentAdjustment());
    }

    #[Test]
    public function an_even_split_needs_no_adjustment(): void
    {
        $subscription = $this->subscription([
            'installment_count' => 3,
            'total_subscription_amount' => 900.00,
            'subscription_amount_per_month' => 300.00,
        ]);

        $this->assertSame(300.0, $subscription->finalInstallmentAmount());
        $this->assertNull($subscription->finalInstallmentAdjustment());
    }

    #[Test]
    public function every_installment_but_the_last_is_the_monthly_amount(): void
    {
        $this->assertFalse($this->subscription(['installments_paid' => 10])->isNextInstallmentFinal());
        $this->assertSame(558.06, $this->subscription(['installments_paid' => 10])->nextInstallmentAmount());

        $this->assertTrue($this->subscription(['installments_paid' => 11])->isNextInstallmentFinal());
        $this->assertSame(558.04, $this->subscription(['installments_paid' => 11])->nextInstallmentAmount());
    }

    #[Test]
    public function the_installments_then_add_up_to_the_plan_exactly(): void
    {
        $subscription = $this->subscription();
        $billed = 0.0;

        for ($paid = 0; $paid < 12; $paid++) {
            $subscription->installments_paid = $paid;
            $billed += $subscription->nextInstallmentAmount();
        }

        $this->assertSame(6696.70, round($billed, 2));
    }

    #[Test]
    public function the_next_bill_is_the_adjusted_amount_when_it_is_the_last_one(): void
    {
        $next = $this->subscription(['installments_paid' => 11])->nextBilling();
        $this->assertSame(558.04, $next['amount']);

        $borrower = $this->subscription(['entity_type' => Borrower::class, 'down_payment' => 0, 'installments_paid' => 11])->nextBilling();
        $this->assertSame(558.04, $borrower['amount']);
    }

    #[Test]
    public function without_the_figures_to_work_it_out_the_monthly_amount_stands(): void
    {
        // A row missing its balance or count cannot be adjusted; billing the
        // agreed monthly figure beats inventing one.
        $noTotal = $this->subscription(['total_subscription_amount' => null, 'installments_paid' => 11]);
        $this->assertSame(558.06, $noTotal->nextInstallmentAmount());
        $this->assertNull($noTotal->finalInstallmentAdjustment());

        $noCount = $this->subscription(['installment_count' => null]);
        $this->assertNull($noCount->finalInstallmentAmount());
        $this->assertSame(558.06, $noCount->nextInstallmentAmount());
    }

    #[Test]
    public function a_one_time_plan_has_no_installments(): void
    {
        $subscription = $this->subscription(['installment_type' => 0, 'subscription_amount_per_month' => null]);

        $this->assertNull($subscription->finalInstallmentAmount());
        $this->assertNull($subscription->finalInstallmentAdjustment());
    }
}
