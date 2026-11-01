<?php

namespace Tests\Unit\Validation;

use App\Rules\BusinessEin;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one definition of a US Employer Identification Number.
 *
 * An EIN is NINE DIGITS. The hyphen after the first two is how it is written
 * down, not part of the number - the same way a phone number's brackets are not
 * part of the phone number. Three MicroBilt strategies already say so out loud,
 * each stripping to digits before sending the value on:
 *
 *     preg_replace('/[^0-9]/', '', $borrower->business_ein ?? '')
 *
 * The rule used to insist on the written form, `^\d{2}-\d{7}$`, and the borrower
 * profile went further and used a bare `size:10` in place of this rule at all.
 * So the portal, which strips the hyphen before posting exactly as the data
 * model says it should, could never save a business EIN: it sent nine
 * characters to an endpoint demanding ten. The same field's change-request modal
 * does not strip, which is why that one path worked and made the bug look
 * intermittent.
 *
 * Both written forms are the same number, so both are accepted, and what gets
 * stored is the nine digits.
 */
#[CoversClass(BusinessEin::class)]
class BusinessEinTest extends TestCase
{
    private function fails(mixed $value, array $extra = ['nullable']): bool
    {
        return Validator::make(
            ['business_ein' => $value],
            ['business_ein' => array_merge($extra, [new BusinessEin()])]
        )->fails();
    }

    // ── the same number, written either way ───────────────────────────

    #[Test]
    public function the_written_form_is_accepted(): void
    {
        $this->assertFalse($this->fails('12-3456789'));
    }

    #[Test]
    public function nine_bare_digits_are_accepted(): void
    {
        // What the borrower portal posts, and what the data model calls an EIN.
        $this->assertFalse($this->fails('123456789'));
    }

    #[Test]
    public function a_numeric_value_is_accepted(): void
    {
        // JSON can carry it unquoted; a leading zero would be lost, but that is
        // the caller's problem to have, not a reason to reject nine digits.
        $this->assertFalse($this->fails(123456789));
    }

    // ── what is not an EIN ────────────────────────────────────────────

    #[DataProvider('notAnEin')]
    #[Test]
    public function it_rejects(mixed $value, string $why): void
    {
        $this->assertTrue($this->fails($value), $why);
    }

    public static function notAnEin(): array
    {
        return [
            'eight digits' => ['12-345678', 'one digit short'],
            'ten digits' => ['12-34567890', 'one digit too many'],
            'eight bare digits' => ['12345678', 'one digit short, unwritten'],
            'ten bare digits' => ['1234567890', 'one digit too many, unwritten'],
            'letters' => ['AB-CDEFGHI', 'an EIN carries no letters'],
            'mixed' => ['12-34567AB', 'partly letters'],
            'nothing but punctuation' => ['--', 'no digits at all'],
        ];
    }

    #[Test]
    public function a_misplaced_hyphen_is_still_the_same_nine_digits(): void
    {
        // The hyphen is presentation. Nine digits arriving with it in an odd
        // place is a typo in the writing, not a different number, and the
        // stored value is identical either way.
        $this->assertFalse($this->fails('1-23456789'));
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
    public function it_reports_how_many_digits_an_ein_carries(): void
    {
        $this->assertSame(9, BusinessEin::digits());
    }

    #[Test]
    public function it_reports_how_long_a_written_ein_is(): void
    {
        // Nine digits plus the hyphen, for input masks and maxLength.
        $this->assertSame(10, BusinessEin::length());
    }

    // ── one stored form ───────────────────────────────────────────────

    #[DataProvider('writings')]
    #[Test]
    public function it_reduces_any_writing_to_the_nine_digits(mixed $given, ?string $expected): void
    {
        $this->assertSame($expected, BusinessEin::normalize($given));
    }

    public static function writings(): array
    {
        return [
            'written' => ['12-3456789', '123456789'],
            'bare' => ['123456789', '123456789'],
            'spaced' => [' 12-3456789 ', '123456789'],
            'numeric' => [123456789, '123456789'],
            'empty' => ['', null],
            'null' => [null, null],
            'no digits' => ['--', null],
        ];
    }

    /**
     * What a request hands the rule: a writing of an EIN reduced to its nine
     * digits, so the column holds one form - and anything else exactly as sent,
     * so the rule still sees the letters normalize() would strip.
     */
    #[Test]
    public function canonical_reduces_a_writing_and_leaves_anything_else_for_the_rule(): void
    {
        $this->assertSame('534534534', BusinessEin::canonical('53-4534534'));
        $this->assertSame('534534534', BusinessEin::canonical('534534534'));
        $this->assertSame('534534534', BusinessEin::canonical('53 4534534'));
        $this->assertSame('AB-CDEFGHI', BusinessEin::canonical('AB-CDEFGHI'));
        $this->assertNull(BusinessEin::canonical(null));
        $this->assertSame(123456789, BusinessEin::canonical(123456789));
    }
}
