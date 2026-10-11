<?php

namespace Tests\Feature\CapitalAccess;

use App\Http\Requests\Api\V1\Borrower\CapitalAccess\SubmitCapitalAccessStepRequest;
use App\Models\CreditBuilder\CapitalAccessApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What an approved credit limit is allowed to be.
 *
 * It used to be clamped to the step's own estimate - `min:est_limit_min` and
 * `max:est_limit_max` - so an approval outside that band could not be recorded
 * at all. The band is a projection; the approval is a fact, and the two do not
 * have to agree. The estimate is guidance beside the field now, not a rule.
 *
 * What is still enforced is only what makes a figure an amount: present when the
 * status is Approved, numeric, not negative.
 */
class SubmitCapitalAccessStepRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $data
     */
    private function validate(array $data): \Illuminate\Contracts\Validation\Validator
    {
        $request = new SubmitCapitalAccessStepRequest();

        return Validator::make($data, $request->rules(), $request->messages());
    }

    private function approved(mixed $creditLimit): array
    {
        return [
            'status' => CapitalAccessApplication::STATUS_APPROVED,
            'credit_limit' => $creditLimit,
        ];
    }

    #[Test]
    public function an_amount_far_above_the_estimate_is_accepted(): void
    {
        $this->assertFalse($this->validate($this->approved('5000000'))->fails());
    }

    #[Test]
    public function an_amount_far_below_the_estimate_is_accepted(): void
    {
        $this->assertFalse($this->validate($this->approved('1'))->fails());
    }

    #[Test]
    public function a_decimal_amount_is_accepted(): void
    {
        $this->assertFalse($this->validate($this->approved('2500.75'))->fails());
    }

    #[Test]
    public function no_upper_or_lower_bound_is_declared_at_all(): void
    {
        // Pins the rule string itself: a re-introduced min:/max: would otherwise
        // only show up for whichever amount a test happened to pick.
        $rule = (new SubmitCapitalAccessStepRequest())->rules()['credit_limit'];

        $this->assertStringNotContainsString('max:', $rule);
        $this->assertDoesNotMatchRegularExpression('/min:[1-9]/', $rule);
    }

    #[Test]
    public function a_negative_amount_is_still_refused(): void
    {
        $this->assertTrue($this->validate($this->approved('-100'))->fails());
    }

    #[Test]
    public function a_non_numeric_amount_is_still_refused(): void
    {
        $this->assertTrue($this->validate($this->approved('abc'))->fails());
    }

    #[Test]
    public function an_approved_step_still_requires_an_amount(): void
    {
        $this->assertTrue($this->validate([
            'status' => CapitalAccessApplication::STATUS_APPROVED,
        ])->fails());
    }
}
