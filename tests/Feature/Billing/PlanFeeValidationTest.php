<?php

namespace Tests\Feature\Billing;

use App\Http\Controllers\Api\V1\FCH\Admin\PlanFee\PlanFeeController;
use App\Http\Requests\Api\V1\FCH\Admin\PlanFee\UpdateSubscriptionPlanRequest;
use App\Models\Partner\SubscriptionPlan;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-096: Plan & Fee saved a plan at $0.00 - the amount was `nullable|min:0`,
 * so 0 and an empty amount went through - and a refused value came back as a
 * generic failure. The update now refuses each bad value with a message for
 * its field, and the plan keeps its price.
 */
#[CoversClass(PlanFeeController::class)]
#[CoversClass(UpdateSubscriptionPlanRequest::class)]
class PlanFeeValidationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $hostAdmin;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostAdmin = $this->makePartnerAdmin($this->makeHostPartner());
        $this->plan = SubscriptionPlan::create([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT,
            'currency' => 'USD', 'currency_symbol' => '$', 'conversion_rate' => 1, 'status' => true,
            'total_amount' => 4997, 'platform_fee' => 10, 'total_seat_count' => 3,
        ]);
        $this->actingAsUser($this->hostAdmin);
    }

    private function update(array $data)
    {
        return $this->putJson("/api/v1/admin/plan-fee/update-subscription-plan/{$this->plan->id}", $data);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function refused(): iterable
    {
        yield 'amount 0' => [['total_amount' => 0], 'total_amount', 'plan_fee.total_amount_gt'];
        yield 'amount negative' => [['total_amount' => -5], 'total_amount', 'plan_fee.total_amount_gt'];
        yield 'amount empty' => [['total_amount' => ''], 'total_amount', 'plan_fee.total_amount_required'];
        yield 'amount missing' => [[], 'total_amount', 'plan_fee.total_amount_required'];
        yield 'amount not a number' => [['total_amount' => 'abc'], 'total_amount', 'plan_fee.total_amount_numeric'];
        yield 'amount too large' => [['total_amount' => 100000000], 'total_amount', 'plan_fee.total_amount_max'];
        yield 'platform fee 0' => [['total_amount' => 59, 'platform_fee' => 0], 'platform_fee', 'plan_fee.platform_fee_gt'];
        yield 'platform fee negative' => [['total_amount' => 59, 'platform_fee' => -1], 'platform_fee', 'plan_fee.platform_fee_gt'];
        yield 'platform fee empty' => [['total_amount' => 59, 'platform_fee' => ''], 'platform_fee', 'plan_fee.platform_fee_required'];
        yield 'platform fee not a number' => [['total_amount' => 59, 'platform_fee' => 'x'], 'platform_fee', 'plan_fee.platform_fee_numeric'];
        yield 'installments 0' => [['total_amount' => 59, 'total_installment_count_upto' => 0], 'total_installment_count_upto', 'plan_fee.installments_min'];
        yield 'installments not whole' => [['total_amount' => 59, 'total_installment_count_upto' => 2.5], 'total_installment_count_upto', 'plan_fee.installments_integer'];
        yield 'seats negative' => [['total_amount' => 59, 'total_seat_count' => -1], 'total_seat_count', 'plan_fee.seats_min'];
        // BUG-076: a price is kept to the cent, decimal(10, 2). 10.555 was let
        // through, shown as $10.55 and would have been saved as 10.56.
        yield 'amount with 3 decimals' => [['total_amount' => 10.555], 'total_amount', 'plan_fee.total_amount_decimals'];
        yield 'amount with 3 decimals as typed' => [['total_amount' => '10.555'], 'total_amount', 'plan_fee.total_amount_decimals'];
        yield 'platform fee with 3 decimals' => [['total_amount' => 59, 'platform_fee' => '1.005'], 'platform_fee', 'plan_fee.platform_fee_decimals'];
    }

    /** @return iterable<string, array{float|int|string, float}> */
    public static function toTheCent(): iterable
    {
        yield 'whole' => [10, 10.0];
        yield 'one decimal' => ['10.5', 10.5];
        yield 'two decimals' => ['10.56', 10.56];
        yield 'two decimals, last 0' => ['10.50', 10.5];
    }

    #[Test]
    #[DataProvider('toTheCent')]
    public function an_amount_to_the_cent_is_saved_as_given(float|int|string $amount, float $saved): void
    {
        $this->update(['total_amount' => $amount])->assertOk();

        $this->assertEquals($saved, $this->plan->fresh()->total_amount);
    }

    #[Test]
    #[DataProvider('refused')]
    public function a_bad_value_is_refused_with_its_fields_message(array $data, string $field, string $messageKey): void
    {
        $this->update($data)
            ->assertStatus(422)
            ->assertJsonPath("errors.{$field}", __($messageKey, ['max' => '99,999,999.99']));

        $this->assertEquals(4997, $this->plan->fresh()->total_amount);
    }

    #[Test]
    public function a_positive_amount_is_saved(): void
    {
        $this->update(['total_amount' => 59.5, 'platform_fee' => 15, 'total_seat_count' => 0])->assertOk();

        $this->assertEquals(59.5, $this->plan->fresh()->total_amount);
        $this->assertEquals(15, $this->plan->fresh()->platform_fee);
    }

    #[Test]
    public function a_plan_without_a_platform_fee_field_is_saved_without_one(): void
    {
        // Seats, borrower plans: the screen sends no platform fee at all.
        $this->update(['total_amount' => 59])->assertOk();

        $this->assertEquals(10, $this->plan->fresh()->platform_fee);
    }

    #[Test]
    public function the_messages_read_as_sentences_not_keys(): void
    {
        foreach (['total_amount_required', 'total_amount_gt', 'total_amount_decimals', 'platform_fee_required', 'platform_fee_gt', 'platform_fee_decimals', 'installments_min', 'seats_min'] as $key) {
            $this->assertNotSame("plan_fee.{$key}", __("plan_fee.{$key}"), "lang/en/plan_fee.php is missing {$key}");
        }
    }

    #[Test]
    public function someone_outside_the_host_is_refused_before_their_values_are_checked(): void
    {
        $this->actingAsUser($this->makeStaffUser($this->makeWhiteLabelPartner()));

        $this->update(['total_amount' => 0])->assertForbidden();
    }

    // ── Check before the confirmation (BUG-096 retest) ────────────────
    //
    // The screen asked "Confirm rate changes $4997 -> $0?" before anything was
    // checked, so a bad value read as accepted. It now asks the API to check the
    // values first, with the same rules as saving, and nothing is saved.

    private function check(array $data)
    {
        return $this->putJson("/api/v1/admin/plan-fee/validate-subscription-plan/{$this->plan->id}", $data);
    }

    #[Test]
    #[DataProvider('refused')]
    public function the_check_refuses_what_saving_refuses(array $data, string $field, string $messageKey): void
    {
        $this->check($data)
            ->assertStatus(422)
            ->assertJsonPath("errors.{$field}", __($messageKey, ['max' => '99,999,999.99']));
    }

    #[Test]
    public function the_check_accepts_good_values_and_saves_nothing(): void
    {
        $this->check(['total_amount' => 59.5, 'platform_fee' => 15, 'total_seat_count' => 0])
            ->assertOk()
            ->assertJsonPath('message', __('plan_fee.values_valid'));

        $plan = $this->plan->fresh();
        $this->assertEquals(4997, $plan->total_amount);
        $this->assertEquals(10, $plan->platform_fee);
        $this->assertNotSame('plan_fee.values_valid', __('plan_fee.values_valid'));
    }

    #[Test]
    public function someone_outside_the_host_cannot_use_the_check(): void
    {
        $this->actingAsUser($this->makeStaffUser($this->makeWhiteLabelPartner()));

        $this->check(['total_amount' => 59])->assertForbidden();
    }
}
