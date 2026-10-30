<?php

namespace Tests\Integration\Borrower;

use App\Models\Borrower\BorrowerDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BorrowerDocType::required(), the query the Getting Started walkthrough uses
 * to find the categories a borrower has to upload a document in.
 */
#[CoversClass(BorrowerDocType::class)]
class BorrowerDocTypeRequiredScopeTest extends TestCase
{
    use RefreshDatabase;

    /** The categories BorrowerDocTypesSeeder sets up, in its order and spelling. */
    private const SEEDED = [
        'Driver’s License',
        'Voided Business Check',
        'EIN / Corporation Docs',
        'Business Bank Statements',
        'Equipment Invoice / Quote',
        'A/R Aging Report',
        'Business Plan',
        'Business Tax Returns',
        'Personal Tax Returns',
        'Miscellaneous Documents',
        'Others',
    ];

    private function seedTypes(array $names): void
    {
        foreach ($names as $i => $name) {
            BorrowerDocType::create(['name' => $name, 'sort_order' => $i + 1]);
        }
    }

    #[Test]
    public function only_the_four_required_categories_are_returned(): void
    {
        $this->seedTypes(self::SEEDED);

        $this->assertSame(
            ['Driver’s License', 'Voided Business Check', 'EIN / Corporation Docs', 'Business Bank Statements'],
            BorrowerDocType::required()->orderBy('sort_order')->pluck('name')->all()
        );
    }

    #[Test]
    public function names_match_whatever_the_case_or_apostrophe(): void
    {
        $this->seedTypes(["DRIVER'S LICENSE", 'voided business check', ' EIN / Corporation Docs ', 'Business Plan']);

        $this->assertSame(
            ["DRIVER'S LICENSE", 'voided business check', ' EIN / Corporation Docs '],
            BorrowerDocType::required()->orderBy('sort_order')->pluck('name')->all()
        );
    }

    #[Test]
    public function a_deleted_category_is_not_required(): void
    {
        $this->seedTypes(self::SEEDED);
        BorrowerDocType::where('name', 'Voided Business Check')->first()->delete();

        $this->assertNotContains('Voided Business Check', BorrowerDocType::required()->pluck('name')->all());
    }
}
