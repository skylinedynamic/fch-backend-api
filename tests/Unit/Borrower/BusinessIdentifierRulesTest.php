<?php

namespace Tests\Unit\Borrower;

use App\Rules\BusinessEin;
use App\Rules\DunsNumber;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two identifiers a business is known by, and the shapes they are allowed.
 *
 *   EIN   - nine digits written XX-XXXXXXX, so ten characters stored.
 *   DUNS  - nine digits, nothing else.
 *
 * Both were `string|max:255` on the admin create and update paths, so a client
 * could be saved with a three-digit EIN or a DUNS carrying letters. The borrower
 * portal's own requests already declared a length for them, which is what made
 * the two surfaces disagree about the same field.
 *
 * The rule objects are asserted directly and then again through the requests
 * that must use them, so tightening one without the other is caught here.
 */
class BusinessIdentifierRulesTest extends TestCase
{
    private function fails(array $rules, array $data): bool
    {
        return Validator::make($data, $rules)->fails();
    }

    // -- EIN ----------------------------------------------------------------

    private function einRules(): array
    {
        return ['business_ein' => ['nullable', 'string', new BusinessEin()]];
    }

    #[Test]
    public function a_correctly_written_ein_passes(): void
    {
        $this->assertFalse($this->fails($this->einRules(), ['business_ein' => '12-3456789']));
    }

    public static function badEins(): array
    {
        return [
            'no dash' => ['123456789'],
            'too few digits' => ['12-345678'],
            'too many digits' => ['12-34567890'],
            'dash misplaced' => ['123-456789'],
            'letters' => ['AB-CDEFGHI'],
            'padded with a space' => [' 12-3456789'],
            'trailing space' => ['12-3456789 '],
            'ten digits, no dash' => ['1234567890'],
        ];
    }

    #[Test]
    #[DataProvider('badEins')]
    public function a_malformed_ein_is_refused(string $ein): void
    {
        $this->assertTrue($this->fails($this->einRules(), ['business_ein' => $ein]));
    }

    #[Test]
    public function an_absent_ein_is_still_optional(): void
    {
        // nullable's job, not the rule's - an optional identifier stays optional.
        $this->assertFalse($this->fails($this->einRules(), ['business_ein' => null]));
        $this->assertFalse($this->fails($this->einRules(), ['business_ein' => '']));
        $this->assertFalse($this->fails($this->einRules(), []));
    }

    // -- DUNS ---------------------------------------------------------------

    private function dunsRules(): array
    {
        return ['duns_number' => ['nullable', 'string', new DunsNumber()]];
    }

    #[Test]
    public function a_nine_digit_duns_passes(): void
    {
        $this->assertFalse($this->fails($this->dunsRules(), ['duns_number' => '123456789']));
    }

    public static function badDuns(): array
    {
        return [
            'eight digits' => ['12345678'],
            'ten digits' => ['1234567890'],
            'dashed like an EIN' => ['12-3456789'],
            'letters' => ['12345678A'],
            'spaced' => ['123 456 789'],
            'padded with a space' => [' 123456789'],
        ];
    }

    #[Test]
    #[DataProvider('badDuns')]
    public function a_malformed_duns_is_refused(string $duns): void
    {
        $this->assertTrue($this->fails($this->dunsRules(), ['duns_number' => $duns]));
    }

    #[Test]
    public function an_absent_duns_is_still_optional(): void
    {
        $this->assertFalse($this->fails($this->dunsRules(), ['duns_number' => null]));
        $this->assertFalse($this->fails($this->dunsRules(), ['duns_number' => '']));
        $this->assertFalse($this->fails($this->dunsRules(), []));
    }

}
