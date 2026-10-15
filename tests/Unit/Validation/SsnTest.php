<?php

namespace Tests\Unit\Validation;

use App\Rules\Ssn;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one definition of a US Social Security Number.
 *
 * Nine digits, written XXX-XX-XXXX. As with an EIN the hyphens are how it is
 * written down, not part of the number, so both writings are the same SSN.
 *
 * The credit profile request - the form that actually triggers a credit pull -
 * validated this field as `required|string|max:255`, which accepts "abc". That
 * is the one field on that form an FCRA pull is keyed on, and the only check it
 * had was that the borrower typed something. Elsewhere the same value was
 * `digits:9` in two places and `size:4` in two others, so no two surfaces agreed.
 *
 * The SSA's own exclusions are enforced, because they are what separates a real
 * SSN from nine digits: no area 000, 666 or 900-999, no group 00, no serial
 * 0000. 123-45-6789 is the textbook example and is deliberately still valid -
 * it is a well-formed SSN, and refusing it would be this rule inventing policy.
 */
#[CoversClass(Ssn::class)]
class SsnTest extends TestCase
{
    private function fails(mixed $value, array $extra = ['nullable']): bool
    {
        return Validator::make(
            ['ssn' => $value],
            ['ssn' => array_merge($extra, [new Ssn()])]
        )->fails();
    }

    // ── the same number, written either way ───────────────────────────

    #[Test]
    public function the_written_form_is_accepted(): void
    {
        $this->assertFalse($this->fails('123-45-6789'));
    }

    #[Test]
    public function nine_bare_digits_are_accepted(): void
    {
        $this->assertFalse($this->fails('123456789'));
    }

    #[Test]
    public function spacing_around_the_value_is_not_the_borrowers_problem(): void
    {
        $this->assertFalse($this->fails(' 123-45-6789 '));
    }

    // ── what is not an SSN at all ─────────────────────────────────────

    #[DataProvider('notNineDigits')]
    #[Test]
    public function it_refuses(mixed $value, string $why): void
    {
        $this->assertTrue($this->fails($value), $why);
    }

    public static function notNineDigits(): array
    {
        return [
            'eight digits' => ['12345678', 'one short'],
            'ten digits' => ['1234567890', 'one too many'],
            'letters' => ['abc-de-fghi', 'the value max:255 used to allow'],
            'partly letters' => ['123-45-678A', 'a typo, not a number'],
            'punctuation only' => ['---', 'no digits at all'],
        ];
    }

    // ── the SSA's own exclusions ──────────────────────────────────────

    #[DataProvider('neverIssued')]
    #[Test]
    public function it_refuses_a_combination_the_ssa_never_issues(string $ssn, string $why): void
    {
        $this->assertTrue($this->fails($ssn), $why);
    }

    public static function neverIssued(): array
    {
        return [
            'area 000' => ['000-45-6789', 'no SSN has area 000'],
            'area 666' => ['666-45-6789', 'the SSA skips 666 entirely'],
            'area 900' => ['900-45-6789', 'areas 900-999 are not issued'],
            'area 999' => ['999-45-6789', 'areas 900-999 are not issued'],
            'group 00' => ['123-00-6789', 'no SSN has group 00'],
            'serial 0000' => ['123-45-0000', 'no SSN has serial 0000'],
            'all zeroes' => ['000-00-0000', 'every part excluded at once'],
        ];
    }

    #[Test]
    public function the_first_and_last_valid_areas_are_not_swept_up(): void
    {
        // 899 is the last issued area and 001 the first; an off-by-one in the
        // 900-999 exclusion would quietly refuse real numbers.
        $this->assertFalse($this->fails('001-45-6789'));
        $this->assertFalse($this->fails('899-45-6789'));
    }

    // ── optionality is not this rule's job ────────────────────────────

    #[Test]
    public function an_empty_value_is_left_to_required_or_nullable(): void
    {
        $this->assertFalse($this->fails(''));
        $this->assertFalse($this->fails(null));
    }

    #[Test]
    public function a_required_field_still_refuses_an_empty_value(): void
    {
        $this->assertTrue($this->fails('', ['required']));
    }

    // ── what the rest of the system asks this rule ────────────────────

    #[Test]
    public function it_reports_how_many_digits_an_ssn_carries(): void
    {
        $this->assertSame(9, Ssn::digits());
    }

    #[Test]
    public function it_reports_how_long_a_written_ssn_is(): void
    {
        // Nine digits plus two hyphens, for input masks and maxLength.
        $this->assertSame(11, Ssn::length());
    }

    #[DataProvider('writings')]
    #[Test]
    public function it_reduces_any_writing_to_the_nine_digits(mixed $given, ?string $expected): void
    {
        $this->assertSame($expected, Ssn::normalize($given));
    }

    public static function writings(): array
    {
        return [
            'written' => ['123-45-6789', '123456789'],
            'bare' => ['123456789', '123456789'],
            'spaced' => [' 123-45-6789 ', '123456789'],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[Test]
    public function it_shows_only_the_last_four_when_asked(): void
    {
        // For anywhere an SSN is echoed back - a confirmation screen, a log
        // line - so the masking is not reinvented at each call site.
        $this->assertSame('***-**-6789', Ssn::mask('123-45-6789'));
        $this->assertSame('***-**-6789', Ssn::mask('123456789'));
        $this->assertNull(Ssn::mask(''));
    }

    /**
     * What a request hands the rule: a writing of an SSN becomes its nine
     * digits, so the column holds one form; anything else is left exactly as
     * sent, so the rule still sees - and refuses - the letters normalize()
     * would have stripped.
     */
    #[Test]
    #[DataProvider('canonicalForms')]
    public function it_reduces_only_a_writing_of_an_ssn_before_validation(mixed $given, mixed $expected): void
    {
        $this->assertSame($expected, Ssn::canonical($given));
    }

    public static function canonicalForms(): array
    {
        return [
            'written' => ['123-45-6789', '123456789'],
            'spaced' => [' 123 45 6789 ', '123456789'],
            'bare' => ['123456789', '123456789'],
            'letters are kept for the rule to refuse' => ['123-45-6789abc', '123-45-6789abc'],
            'empty' => ['', ''],
            'null' => [null, null],
            'not a string' => [['123456789'], ['123456789']],
        ];
    }

    #[Test]
    public function a_value_left_as_sent_is_still_refused(): void
    {
        $this->assertTrue($this->fails(Ssn::canonical('123-45-6789abc')));
    }
}
