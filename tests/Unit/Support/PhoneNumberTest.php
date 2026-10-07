<?php

namespace Tests\Unit\Support;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phone numbers are stored as typed ("(555) 123-4567", "555.123.4567"...);
 * SMS providers need E.164. Normalised at send time, never rewritten in the
 * database. A country's dialling code and number length come from
 * config/locations.php.
 */
#[CoversClass(PhoneNumber::class)]
class PhoneNumberTest extends TestCase
{
    public static function usNumbers(): array
    {
        return [
            'formatted' => ['(555) 123-4567', '+15551234567'],
            'dashes' => ['555-123-4567', '+15551234567'],
            'dots' => ['555.123.4567', '+15551234567'],
            'plain ten digits' => ['5551234567', '+15551234567'],
            'leading country code' => ['1 555 123 4567', '+15551234567'],
            'already e164' => ['+15551234567', '+15551234567'],
            'e164 with spaces' => ['+1 (555) 123-4567', '+15551234567'],
            'other country in e164' => ['+44 20 7946 0958', '+442079460958'],
        ];
    }

    #[Test]
    #[DataProvider('usNumbers')]
    public function it_normalises_to_e164(string $raw, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::toE164($raw, 'USA'));
    }

    #[Test]
    public function canadian_numbers_normalise_the_same_way(): void
    {
        $this->assertSame('+14165550123', PhoneNumber::toE164('(416) 555-0123', 'CAN'));
        $this->assertSame('+14165550123', PhoneNumber::toE164('1-416-555-0123', 'CAN'));
    }

    public static function unusable(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'too short' => ['123-4567'],
            'too long for us' => ['55512345678'],
            'letters only' => ['call me'],
            'e164 too short' => ['+12345'],
        ];
    }

    #[Test]
    #[DataProvider('unusable')]
    public function an_unusable_number_gives_null(?string $raw): void
    {
        $this->assertNull(PhoneNumber::toE164($raw, 'USA'));
    }

    #[Test]
    public function an_unknown_country_only_accepts_a_number_already_in_e164(): void
    {
        $this->assertNull(PhoneNumber::toE164('5551234567', 'ZZZ'));
        $this->assertSame('+8801700000000', PhoneNumber::toE164('+880 1700-000000', 'ZZZ'));
    }

    #[Test]
    public function a_countrys_e164_pattern_accepts_only_its_own_numbers(): void
    {
        $pattern = PhoneNumber::e164Pattern('USA');

        $this->assertSame(1, preg_match($pattern, '+15551234567'));
        $this->assertSame(0, preg_match($pattern, '+8801700000000'));
        $this->assertSame(0, preg_match($pattern, '5551234567'));
        $this->assertNull(PhoneNumber::e164Pattern('ZZZ'));
    }

    #[Test]
    public function a_number_is_masked_to_its_last_four_digits_for_logs(): void
    {
        $this->assertSame('********4567', PhoneNumber::mask('+15551234567'));
        $this->assertSame('', PhoneNumber::mask(null));
    }
}
