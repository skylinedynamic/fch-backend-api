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
 * A client's FICO and business credit (PAYDEX) scores on the admin's Business
 * Financials section, through the real endpoint.
 *
 * FICO is a whole number from 300 to 850; the business score a whole number of
 * 0 or more. The section accepted any number for both - a FICO of 9000, a
 * business score of -5 or 12.5 - and lender matching compares them with the
 * lenders' minimums, so a typo silently changed which lenders a client saw.
 */
class ClientCreditScoresTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;

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
            'fico_score' => 697,
            'paydex_score' => 80,
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
     * @return iterable<string, array{mixed}>
     */
    public static function badFicoScores(): iterable
    {
        yield 'below the scale' => [299];
        yield 'above the scale' => [851];
        yield 'zero' => [0];
        yield 'a fraction' => [720.5];
        yield 'text' => ['seven hundred'];
    }

    #[Test]
    #[DataProvider('badFicoScores')]
    public function a_fico_score_off_the_scale_is_refused_and_not_stored(mixed $score): void
    {
        $this->save(['fico_score' => $score])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fico_score' => __('common.fico_score_invalid', ['min' => 300, 'max' => 850])]);

        $this->assertSame(697, (int) $this->borrower->fresh()->fico_score);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badBusinessScores(): iterable
    {
        yield 'negative' => [-5];
        yield 'a fraction' => [12.5];
        yield 'text' => ['high'];
    }

    #[Test]
    #[DataProvider('badBusinessScores')]
    public function a_business_score_that_is_not_a_whole_number_of_zero_or_more_is_refused(mixed $score): void
    {
        $this->save(['paydex_score' => $score])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['paydex_score' => __('common.business_credit_score_invalid')]);

        $this->assertSame(80, (int) $this->borrower->fresh()->paydex_score);
    }

    #[Test]
    public function valid_scores_are_stored(): void
    {
        $this->save(['fico_score' => 850, 'paydex_score' => 0])->assertOk();

        $borrower = $this->borrower->fresh();
        $this->assertSame(850, (int) $borrower->fico_score);
        $this->assertSame(0, (int) $borrower->paydex_score);
    }

    #[Test]
    public function the_business_score_has_no_upper_bound(): void
    {
        $this->save(['paydex_score' => 775])->assertOk();

        $this->assertSame(775, (int) $this->borrower->fresh()->paydex_score);
    }

    #[Test]
    public function both_scores_stay_optional(): void
    {
        $this->save(['fico_score' => null, 'paydex_score' => null])->assertOk();
    }
}
