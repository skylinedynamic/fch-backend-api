<?php

namespace Tests\Unit\Borrower;

use App\Models\Borrower\BorrowerDocType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Getting Started walkthrough needs a document in four categories only:
 * Driver's License, Voided Business Check, EIN / Corporation Docs and Business
 * Bank Statements. Categories are matched by name, ignoring case, surrounding
 * spaces and whether the apostrophe is straight or curly (the seeder names it
 * "Driver’s License").
 */
#[CoversClass(BorrowerDocType::class)]
class BorrowerDocTypeRequiredNamesTest extends TestCase
{
    #[Test]
    public function the_required_categories_are_exactly_the_four(): void
    {
        $this->assertSame(
            ["Driver's License", 'Voided Business Check', 'EIN / Corporation Docs', 'Business Bank Statements'],
            BorrowerDocType::REQUIRED_NAMES
        );
    }

    public static function requiredNames(): array
    {
        return [
            'straight apostrophe' => ["Driver's License"],
            'curly apostrophe, as seeded' => ['Driver’s License'],
            'voided check, any case' => ['Voided Business check'],
            'EIN docs' => ['EIN / Corporation Docs'],
            'bank statements, spaced' => ['  business bank statements '],
        ];
    }

    #[Test]
    #[DataProvider('requiredNames')]
    public function a_required_category_is_recognised(string $name): void
    {
        $this->assertTrue(BorrowerDocType::isRequiredName($name));
    }

    public static function optionalNames(): array
    {
        return [
            ['Equipment Invoice / Quote'],
            ['A/R Aging Report'],
            ['Business Plan'],
            ['Business Tax Returns'],
            ['Personal Tax Returns'],
            ['Miscellaneous Documents'],
            ['Others'],
            ['EIN Letter'],
        ];
    }

    #[Test]
    #[DataProvider('optionalNames')]
    public function every_other_category_is_optional(string $name): void
    {
        $this->assertFalse(BorrowerDocType::isRequiredName($name));
    }
}
