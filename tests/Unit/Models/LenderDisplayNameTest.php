<?php

namespace Tests\Unit\Models;

use App\Models\Lender\Lender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * R2 BUG-008: two lenders could share a name ("Kabbage" twice) and nothing on
 * screen told them apart. A lender is now shown as "Lender Name - Legal Name";
 * the legal name is unique.
 */
#[CoversClass(Lender::class)]
class LenderDisplayNameTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, string}> */
    public static function names(): iterable
    {
        yield 'both' => ['Kabbage', 'Kabbage Inc.', 'Kabbage - Kabbage Inc.'];
        yield 'spaces trimmed' => ['  Kabbage ', ' Kabbage Inc. ', 'Kabbage - Kabbage Inc.'];
        yield 'no legal name (older rows)' => ['Kabbage', null, 'Kabbage'];
        yield 'blank legal name' => ['Kabbage', '  ', 'Kabbage'];
        yield 'no name' => [null, 'Kabbage Inc.', 'Kabbage Inc.'];
    }

    #[Test]
    #[DataProvider('names')]
    public function a_lender_is_shown_as_its_name_and_legal_name(?string $name, ?string $legalName, string $shown): void
    {
        $lender = new Lender(['name' => $name, 'legal_name' => $legalName]);

        $this->assertSame($shown, $lender->displayName());
        $this->assertSame($shown, $lender->display_name);
    }

    #[Test]
    public function legal_names_are_compared_without_case_or_extra_spaces(): void
    {
        $this->assertSame('kabbage inc.', Lender::normalizeLegalName("  KABBAGE \t  Inc. "));
        $this->assertSame('', Lender::normalizeLegalName(null));
    }

    /** A blank legal name is left to `required`: never "taken". */
    #[Test]
    public function a_blank_legal_name_is_never_taken(): void
    {
        $this->assertFalse(Lender::legalNameTaken('   '));
        $this->assertFalse(Lender::legalNameTaken(null));
    }

    #[Test]
    public function the_label_goes_out_with_the_lender(): void
    {
        $lender = new Lender(['name' => 'Kabbage', 'legal_name' => 'Kabbage Inc.']);

        $this->assertSame('Kabbage - Kabbage Inc.', $lender->toArray()['display_name']);
    }
}
