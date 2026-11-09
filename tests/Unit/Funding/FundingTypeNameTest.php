<?php

namespace Tests\Unit\Funding;

use App\Support\Funding\FundingTypeName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Funding type names worded differently for the same product are the same
 * funding type: same meaningful words, any order and case, punctuation and
 * filler words (business, loan, financing, of, ...) aside.
 */
#[CoversClass(FundingTypeName::class)]
class FundingTypeNameTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function names(): array
    {
        return [
            'same name' => ['Term Loan', 'Term Loan', true],
            'other case' => ['term loan', 'Term Loan', true],
            'filler word' => ['Line of Credit', 'Business Line of Credit', true],
            'loan vs financing' => ['SBA Loan', 'SBA Financing', true],
            'pre-qualification purpose' => ['Equipment', 'Equipment Financing', true],
            'other word order' => ['Debt Refinance / Consolidation', 'Debt Consolidation / Refinance', true],
            'different product' => ['Equipment Financing', 'Equipment Leasing', false],
            'one word more' => ['Real Estate', 'Commercial Real Estate Financing', false],
            'nothing meaningful' => ['Financing', 'Loan', false],
            'empty' => ['', '', false],
        ];
    }

    #[Test]
    #[DataProvider('names')]
    public function names_with_the_same_meaningful_words_are_the_same_funding_type(string $a, string $b, bool $same): void
    {
        $this->assertSame($same, FundingTypeName::same($a, $b));
        $this->assertSame($same, FundingTypeName::same($b, $a));
    }

    #[Test]
    public function the_category_for_a_name_is_the_first_with_the_same_words(): void
    {
        $categories = ['Term Loan', 'Business Line of Credit', 'SBA Financing'];

        $this->assertSame('Business Line of Credit', FundingTypeName::categoryFor('Line of Credit', $categories));
        $this->assertSame('SBA Financing', FundingTypeName::categoryFor('SBA Loan', $categories));
        $this->assertNull(FundingTypeName::categoryFor('Real Estate', $categories));
        $this->assertNull(FundingTypeName::categoryFor(null, $categories));
    }

    #[Test]
    public function the_key_is_the_meaningful_words_sorted(): void
    {
        $this->assertSame('credit line', FundingTypeName::key('Business Line of Credit'));
        $this->assertSame('', FundingTypeName::key(null));
    }
}
