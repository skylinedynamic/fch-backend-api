<?php

namespace Tests\Feature\Borrower;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-006 (QA round 2): Owner portal › client 153 › Business Financials saved an
 * annual revenue of 99999999999999999 (stored rounded to 1e17), a profit margin
 * of 150% and, from the admin form, a current debt of -500 as 500. The values
 * feed readiness and eligibility, so out-of-range ones are refused instead.
 *
 * Amounts: 0 to 999,999,999.99 - the bound the borrower's own Financial Details
 * already had. Profit margin: -100% to 100% - a loss-making business has a
 * negative margin.
 */
class ClientBusinessFinancialsBoundsTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;

    private const AMOUNT_FIELDS = [
        'monthly_revenue',
        'annual_revenue',
        'annual_personal_income',
        'avg_bank_balance',
        'average_monthly_deposits',
        'current_business_debt',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'email' => 'michael@example.test',
            'annual_revenue' => 1200000,
            'profit_margin' => 12.5,
            'current_business_debt' => 25000,
        ]);

        $admin = User::create([
            'name' => 'Host Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::ADMIN,
            'partner_id' => $partner->id,
            'status' => User::STATUS['active'],
        ]);

        Passport::actingAs($admin, [], 'api');
    }

    private function save(array $payload)
    {
        return $this->postJson("/api/v1/common/borrowers/{$this->borrower->id}/business-financials", $payload);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function amountFields(): iterable
    {
        foreach (self::AMOUNT_FIELDS as $field) {
            yield $field => [$field];
        }
    }

    #[Test]
    #[DataProvider('amountFields')]
    public function an_amount_over_the_ceiling_is_refused(string $field): void
    {
        $this->save([$field => '99999999999999999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field => __('borrower_validation.amount_max', ['max' => '999,999,999.99'])]);
    }

    #[Test]
    #[DataProvider('amountFields')]
    public function a_negative_amount_is_refused(string $field): void
    {
        $this->save([$field => '-500'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field => __('borrower_validation.amount_min')]);
    }

    #[Test]
    public function qa_values_leave_the_stored_financials_unchanged(): void
    {
        $this->save([
            'annual_revenue' => '99999999999999999',
            'profit_margin' => '150',
            'current_business_debt' => '-500',
        ])->assertStatus(422);

        $borrower = $this->borrower->fresh();
        $this->assertEquals(1200000, (float) $borrower->annual_revenue);
        $this->assertEquals(12.5, (float) $borrower->profit_margin);
        $this->assertEquals(25000, (float) $borrower->current_business_debt);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badMargins(): iterable
    {
        yield 'QA: 150%' => ['150'];
        yield 'just over 100' => ['100.01'];
        yield 'below -100' => ['-100.01'];
    }

    #[Test]
    #[DataProvider('badMargins')]
    public function a_profit_margin_outside_minus_100_to_100_is_refused(string $margin): void
    {
        $this->save(['profit_margin' => $margin])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profit_margin' => __('borrower_validation.profit_margin_between', ['min' => -100, 'max' => 100])]);
    }

    #[Test]
    public function a_negative_profit_margin_and_amounts_at_the_bounds_are_saved(): void
    {
        $this->save([
            'profit_margin' => '-35.5',
            'annual_revenue' => '999,999,999.99',
            'current_business_debt' => '0',
        ])->assertOk();

        $borrower = $this->borrower->fresh();
        $this->assertEquals(-35.5, (float) $borrower->profit_margin);
        $this->assertEquals(999999999.99, (float) $borrower->annual_revenue);
        $this->assertEquals(0, (float) $borrower->current_business_debt);
    }

    #[Test]
    public function a_profit_margin_of_exactly_minus_100_or_100_is_saved(): void
    {
        $this->save(['profit_margin' => '100'])->assertOk();
        $this->save(['profit_margin' => '-100'])->assertOk();
    }
}
