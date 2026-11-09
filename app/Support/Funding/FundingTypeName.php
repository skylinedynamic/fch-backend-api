<?php

namespace App\Support\Funding;

/**
 * Compares funding type names by their meaningful words, so names worded
 * differently for the same product match: "Line of Credit" and "Business Line
 * of Credit", "SBA Loan" and "SBA Financing", "Equipment" and "Equipment
 * Financing", "Refinance / Debt Consolidation" and "Debt Consolidation /
 * Refinance". Used by Lender Information matching and the Pre-Qualification
 * list's funding type filter.
 */
final class FundingTypeName
{
    /** Words that only say "this is funding". */
    private const FILLER_WORDS = ['a', 'and', 'business', 'financing', 'for', 'loan', 'loans', 'of', 'the'];

    /** A name's meaningful words, sorted: "Business Line of Credit" => "credit line". */
    public static function key(?string $name): string
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_unique(array_diff($words, self::FILLER_WORDS));
        sort($words);

        return implode(' ', $words);
    }

    /** Whether two names are the same funding type. */
    public static function same(?string $a, ?string $b): bool
    {
        $key = self::key($a);

        return $key !== '' && $key === self::key($b);
    }

    /**
     * The category among $categories that is the same funding type as $name
     * (the first, in their order), or null when none is.
     *
     * @param  array<int, string>  $categories
     */
    public static function categoryFor(?string $name, array $categories): ?string
    {
        foreach ($categories as $category) {
            if (self::same($name, $category)) {
                return $category;
            }
        }

        return null;
    }
}
