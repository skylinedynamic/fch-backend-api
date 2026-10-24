<?php

namespace Tests\Unit\Validation;

use App\Rules\BusinessCreditScore;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one definition of a business credit score: a whole number of 0 or more.
 *
 * Applies to the borrower's paydex_score and the lender threshold compared
 * against it (min_business_credit_score). No upper bound, by decision: the
 * business score is not tied to one bureau's scale. 0 is a real score.
 *
 * The admin client paths had it as plain `numeric`, so -5 and 12.5 were saved.
 */
#[CoversClass(BusinessCreditScore::class)]
class BusinessCreditScoreTest extends TestCase
{
    private function fails(mixed $value, array $extra = ['nullable']): bool
    {
        return Validator::make(
            ['paydex_score' => $value],
            ['paydex_score' => array_merge($extra, [new BusinessCreditScore()])]
        )->fails();
    }

    public static function validScores(): array
    {
        return [
            'zero' => [0],
            'typical' => [80],
            'no upper bound' => [775],
            'large' => [100000],
            'as a string' => ['80'],
            'whole float' => [80.0],
            'decimal writing' => ['80.00'],
        ];
    }

    #[Test]
    #[DataProvider('validScores')]
    public function a_whole_number_of_zero_or_more_passes(mixed $value): void
    {
        $this->assertFalse($this->fails($value));
    }

    public static function invalidScores(): array
    {
        return [
            'negative' => [-1],
            'negative string' => ['-5'],
            'a fraction' => [12.5],
            'a fractional string' => ['12.5'],
            'letters' => ['high'],
            'a range, not a score' => ['80-100'],
            'an array' => [[80]],
        ];
    }

    #[Test]
    #[DataProvider('invalidScores')]
    public function anything_else_fails(mixed $value): void
    {
        $this->assertTrue($this->fails($value));
    }

    #[Test]
    public function it_says_what_a_valid_score_is(): void
    {
        $message = Validator::make(['paydex_score' => -1], ['paydex_score' => [new BusinessCreditScore()]])
            ->errors()->first('paydex_score');

        $this->assertSame('Enter the business credit score as a whole number of 0 or more.', $message);
    }

    #[Test]
    public function an_empty_value_is_left_to_required_or_nullable(): void
    {
        $this->assertFalse($this->fails(null));
        $this->assertFalse($this->fails(''));
        $this->assertTrue($this->fails(null, ['required']));
    }
}
