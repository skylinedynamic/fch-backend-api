<?php

namespace Tests\Feature\Profile;

use App\Http\Controllers\Api\V1\Borrower\Profile\FinancialInfoController;
use App\Http\Controllers\Api\V1\Borrower\Profile\ProfileCompletionController;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Annual Personal Income and Average Monthly Deposits are optional on the
 * borrower's Financial tab: it saves without them, and profile completion
 * does not ask for them.
 */
#[CoversClass(FinancialInfoController::class)]
#[CoversClass(ProfileCompletionController::class)]
class OptionalFinancialFieldsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->makeBorrowerUser($this->makeWhiteLabelPartner(), [
            'annual_personal_income' => null,
            'average_monthly_deposits' => null,
        ]);
        $this->actingAsUser($this->me);
    }

    private function borrower(): Borrower
    {
        return Borrower::find($this->me->entity_id);
    }

    private function save(array $extra = [])
    {
        return $this->postJson('/api/v1/borrower/profile/financial-info', [
            'currency' => 'USD',
            'monthly_revenue' => 10000,
            'annual_revenue' => 120000,
            ...$extra,
        ]);
    }

    #[Test]
    public function the_financial_tab_saves_without_either_field(): void
    {
        $this->save()->assertOk();

        $borrower = $this->borrower();
        $this->assertEquals(10000, $borrower->monthly_revenue);
        $this->assertNull($borrower->annual_personal_income);
        $this->assertNull($borrower->average_monthly_deposits);
    }

    #[Test]
    public function the_financial_tab_saves_with_both_empty(): void
    {
        $this->save(['annual_personal_income' => null, 'average_monthly_deposits' => null])->assertOk();
    }

    #[Test]
    public function a_given_amount_is_still_saved_and_checked(): void
    {
        $this->save(['annual_personal_income' => 85000, 'average_monthly_deposits' => 9000])->assertOk();

        $this->assertEquals(85000, $this->borrower()->annual_personal_income);
        $this->assertEquals(9000, $this->borrower()->average_monthly_deposits);

        $this->save(['average_monthly_deposits' => -5])->assertStatus(422);
    }

    #[Test]
    public function revenue_cannot_be_saved_empty(): void
    {
        $this->save(['monthly_revenue' => null, 'annual_revenue' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.monthly_revenue', __('borrower_validation.monthly_revenue_required'))
            ->assertJsonPath('errors.annual_revenue', __('borrower_validation.annual_revenue_required'));

        $this->assertNull($this->borrower()->monthly_revenue);
    }

    #[Test]
    public function revenue_of_zero_is_saved(): void
    {
        $this->save(['monthly_revenue' => 0, 'annual_revenue' => 0])->assertOk();

        $this->assertSame(0.0, (float) $this->borrower()->monthly_revenue);
        $this->assertNotNull($this->borrower()->monthly_revenue);
        $this->assertSame(0.0, (float) $this->borrower()->annual_revenue);
        $this->assertNotNull($this->borrower()->annual_revenue);

        // Zero is an answer, so the Financial section is complete.
        $financial = $this->getJson('/api/v1/borrower/profile/completion')->assertOk()->json('data.financial');
        $this->assertTrue($financial['is_complete']);
    }

    #[Test]
    public function profile_completion_does_not_ask_for_them(): void
    {
        $this->save()->assertOk();

        $financial = $this->getJson('/api/v1/borrower/profile/completion')->assertOk()->json('data.financial');

        $this->assertTrue($financial['is_complete']);
        $this->assertNotContains('annual_personal_income', $financial['missing_fields']);
        $this->assertNotContains('average_monthly_deposits', $financial['missing_fields']);
    }
}
