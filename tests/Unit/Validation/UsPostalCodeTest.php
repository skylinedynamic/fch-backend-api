<?php

namespace Tests\Unit\Validation;

use App\Rules\UsPostalCode;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one definition of a zip code, shared by every address form in both
 * portals and by the public embedded form.
 *
 * It lives outside tests/Unit/EmbeddedForm because the rule is no longer an
 * embedded-form concern: leads, clients, partners, contacts, employees, the
 * funding application and the borrower profile all validate against it.
 */
#[CoversClass(UsPostalCode::class)]
class UsPostalCodeTest extends TestCase
{
    private function fails(mixed $value, array $extra = ['nullable']): bool
    {
        return Validator::make(
            ['zip' => $value],
            ['zip' => array_merge($extra, [new UsPostalCode()])]
        )->fails();
    }

    public static function rejected(): array
    {
        return [
            'four digits' => ['1000'],
            'six digits' => ['100011'],
            'contains a letter' => ['1000A'],
            'all letters' => ['abcde'],
            'ZIP+4' => ['75201-1234'],
            'leading space' => [' 12345'],
            'trailing space' => ['12345 '],
            'inner space' => ['12 45'],
            'UK postcode' => ['N1 2AB'],
            'Canadian postcode' => ['K1A 0B1'],
            'decimal' => ['12345.0'],
            'negative' => ['-1234'],
        ];
    }

    #[Test]
    #[DataProvider('rejected')]
    public function a_zip_code_must_be_exactly_five_digits(string $value): void
    {
        $this->assertTrue($this->fails($value), "'{$value}' should have been rejected");
    }

    #[Test]
    public function five_digits_pass(): void
    {
        $this->assertFalse($this->fails('75201'));
    }

    #[Test]
    public function a_leading_zero_survives(): void
    {
        // 01234 is a real ZIP code. Anything that treats the value as a number
        // loses the zero and turns it into the four-digit 1234.
        $this->assertFalse($this->fails('01234'));
        $this->assertFalse($this->fails('00501'));
    }

    #[Test]
    public function an_absent_value_is_left_to_required_or_nullable(): void
    {
        // The rule only ever judges the format. Whether the field may be blank
        // is decided by the rule sitting next to it, so an optional zip stays
        // optional and a required one stays required.
        $this->assertFalse($this->fails(''), 'blank should pass a nullable zip');
        $this->assertFalse($this->fails(null), 'null should pass a nullable zip');
        $this->assertTrue($this->fails('', ['required']), 'blank should fail a required zip');
    }

    #[Test]
    public function a_non_scalar_is_rejected_rather_than_crashing(): void
    {
        $this->assertTrue($this->fails(['12345']));
    }

    #[Test]
    public function the_length_comes_from_config(): void
    {
        $this->assertSame(5, UsPostalCode::length());

        config(['funding_application.postal_code_length' => 4]);
        $this->assertSame(4, UsPostalCode::length());
        $this->assertFalse($this->fails('1234'));
        $this->assertTrue($this->fails('12345'));
    }

    #[Test]
    public function the_failure_message_says_what_is_expected(): void
    {
        $validator = Validator::make(['zip' => 'abc'], ['zip' => [new UsPostalCode()]]);

        $this->assertSame(__('common.postal_code_invalid'), $validator->errors()->first('zip'));
    }
}
