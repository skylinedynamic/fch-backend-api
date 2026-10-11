<?php

namespace Tests\Unit\EmbeddedForm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * normalizePhoneE164() and formatPhoneDisplay().
 *
 * The public form used to accept any string as a phone number, so "1234567890"
 * was stored as one. These two functions are the whole of the fix: one decides
 * what a valid US number is and what it is stored as, the other decides how it
 * is shown back.
 *
 * Extends PHPUnit's TestCase rather than the Laravel one - these are plain
 * functions from the composer-autoloaded helpers file and need no framework.
 */
class PhoneHelpersTest extends TestCase
{
    public static function acceptedFormats(): array
    {
        return [
            'formatted' => ['(813) 391-3333'],
            'dotted' => ['813.391.3333'],
            'hyphenated' => ['813-391-3333'],
            'spaced' => ['813 391 3333'],
            'bare ten digits' => ['8133913333'],
            'leading country code' => ['1-813-391-3333'],
            'already E.164' => ['+18133913333'],
        ];
    }

    #[Test]
    #[DataProvider('acceptedFormats')]
    public function every_way_of_writing_one_number_normalises_to_the_same_value(string $input): void
    {
        $this->assertSame('+18133913333', normalizePhoneE164($input));
    }

    public static function rejectedNumbers(): array
    {
        return [
            // The exact value the bug report says was wrongly accepted: NANP
            // area codes may not begin with 0 or 1.
            'area code starts with 1' => ['1234567890'],
            'area code starts with 0' => ['0134567890'],
            // Nor may the exchange.
            'exchange starts with 1' => ['8131913333'],
            'exchange starts with 0' => ['8130913333'],
            'too short' => ['12345'],
            'too long' => ['81339133331234'],
            'no digits at all' => ['not a phone'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('rejectedNumbers')]
    public function it_rejects_anything_that_is_not_a_valid_us_number(string $input): void
    {
        $this->assertNull(normalizePhoneE164($input));
    }

    #[Test]
    public function null_in_null_out(): void
    {
        $this->assertNull(normalizePhoneE164(null));
        $this->assertNull(formatPhoneDisplay(null));
    }

    #[Test]
    public function a_non_us_country_code_keeps_its_digits_behind_that_prefix(): void
    {
        // No national numbering plan is modelled for these, so the digits are
        // kept as given rather than validated against a US shape.
        $this->assertSame('+442071234567', normalizePhoneE164('020 7123 4567', '+44'));
        $this->assertSame('+442071234567', normalizePhoneE164('+44 20 7123 4567', '+44'));
    }

    #[Test]
    public function it_renders_a_stored_number_the_way_people_read_it(): void
    {
        $this->assertSame('(813) 391-3333', formatPhoneDisplay('+18133913333'));
        $this->assertSame('(813) 391-3333', formatPhoneDisplay('8133913333'));
        $this->assertSame('(813) 391-3333', formatPhoneDisplay('18133913333'));
    }

    #[Test]
    public function it_leaves_a_number_it_cannot_read_untouched_rather_than_mangling_it(): void
    {
        // A non-US number must not be forced into a US shape.
        $this->assertSame('+442071234567', formatPhoneDisplay('+442071234567'));
        $this->assertSame('12345', formatPhoneDisplay('12345'));
    }

    #[Test]
    public function normalising_is_idempotent(): void
    {
        $once = normalizePhoneE164('(813) 391-3333');
        $this->assertSame($once, normalizePhoneE164($once));
    }
}
