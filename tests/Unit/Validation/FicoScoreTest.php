<?php

namespace Tests\Unit\Validation;

use App\Rules\FicoScore;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one definition of a FICO score: a whole number from 300 to 850.
 *
 * Applies to the borrower's own score and to every FICO threshold compared
 * against it (a lender's min_fico, a credit account's typical minimum, a
 * capital-access requirement). They were `numeric`, `min:0`, digits only, or
 * 300-850 on one path and `min:0` on the other, so a FICO of 9000 or a
 * minimum of 0 could be saved.
 */
#[CoversClass(FicoScore::class)]
class FicoScoreTest extends TestCase
{
    private function fails(mixed $value, array $extra = ['nullable']): bool
    {
        return Validator::make(
            ['fico_score' => $value],
            ['fico_score' => array_merge($extra, [new FicoScore()])]
        )->fails();
    }

    private function message(mixed $value): ?string
    {
        return Validator::make(['fico_score' => $value], ['fico_score' => [new FicoScore()]])
            ->errors()->first('fico_score') ?: null;
    }

    public static function validScores(): array
    {
        return [
            'lowest' => [300],
            'typical' => [720],
            'highest' => [850],
            'as a string' => ['720'],
            'padded string' => [' 720 '],
            'whole float' => [720.0],
            // A decimal column (capital access) echoes its value back this way.
            'decimal column writing' => ['700.00'],
        ];
    }

    #[Test]
    #[DataProvider('validScores')]
    public function a_whole_number_on_the_scale_passes(mixed $value): void
    {
        $this->assertFalse($this->fails($value));
    }

    public static function invalidScores(): array
    {
        return [
            'just below the scale' => [299],
            'just above the scale' => [851],
            'zero' => [0],
            'negative' => [-720],
            'way off the scale' => [9000],
            'a fraction' => [720.5],
            'a fractional string' => ['720.5'],
            'letters' => ['abc'],
            'exponent' => ['7e2'],
            'a range, not a score' => ['700-749'],
            'an array' => [[720]],
            'a boolean' => [true],
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
        $this->assertSame('Enter a FICO score as a whole number between 300 and 850.', $this->message(900));
    }

    #[Test]
    public function the_bounds_come_from_config(): void
    {
        // funding_application.credit_score is what the embedded form and the
        // portal's score bands read, so the rule cannot drift from them.
        config(['funding_application.credit_score.min' => 350, 'funding_application.credit_score.max' => 800]);

        $this->assertTrue($this->fails(320));
        $this->assertTrue($this->fails(820));
        $this->assertFalse($this->fails(350));
        $this->assertSame('Enter a FICO score as a whole number between 350 and 800.', $this->message(900));
    }

    #[Test]
    public function an_empty_value_is_left_to_required_or_nullable(): void
    {
        $this->assertFalse($this->fails(null));
        $this->assertFalse($this->fails(''));
    }

    #[Test]
    public function a_required_field_still_refuses_an_empty_value(): void
    {
        $this->assertTrue($this->fails(null, ['required']));
    }
}
