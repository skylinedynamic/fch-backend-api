<?php

namespace Tests\Unit\Rules;

use App\Rules\OfferedFundingSequence;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The funding sequence id must be a number and there must be a funding type
 * to offer sequences for - both refused before any query is made. (Whether
 * the sequence is offered for the type is covered with the database in
 * Feature/FundApplication/FundingSequenceOnFundingRequestStepTest.)
 */
#[CoversClass(OfferedFundingSequence::class)]
class OfferedFundingSequenceTest extends TestCase
{
    /** @return array<string, array{mixed}> */
    public static function notAnId(): array
    {
        return [
            'text' => ['Term Loans'],
            'array' => [[6]],
            'null' => [null],
            // An empty string never reaches the rule: Laravel skips it, and
            // the step's `required` refuses it first.
        ];
    }

    #[Test]
    #[DataProvider('notAnId')]
    public function a_value_that_is_not_an_id_is_refused(mixed $value): void
    {
        $validator = Validator::make(['funding_sequence_id' => $value], [
            'funding_sequence_id' => [new OfferedFundingSequence('Term Loan')],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertSame(__('borrower_validation.funding_sequence_invalid'), $validator->errors()->first('funding_sequence_id'));
    }

    #[Test]
    public function without_a_funding_type_no_sequence_is_offered(): void
    {
        $validator = Validator::make(['funding_sequence_id' => 6], [
            'funding_sequence_id' => [new OfferedFundingSequence(null)],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertSame(__('borrower_validation.funding_sequence_invalid'), $validator->errors()->first('funding_sequence_id'));
    }
}
