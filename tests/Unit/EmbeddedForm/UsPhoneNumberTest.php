<?php

namespace Tests\Unit\EmbeddedForm;

use App\Rules\UsPhoneNumber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The validation rule behind every phone field on the public form.
 *
 * It delegates to normalizePhoneE164(), so the rule and the stored value can
 * never disagree about what counts as valid - a number that passes validation
 * is by definition one that normalises.
 */
#[CoversClass(UsPhoneNumber::class)]
class UsPhoneNumberTest extends TestCase
{
    /**
     * Run the rule and return the failure message, or null when it passed.
     */
    private function check(mixed $value): ?string
    {
        $message = null;

        (new UsPhoneNumber())->validate(
            'phone',
            $value,
            function (string $failure) use (&$message) {
                $message = $failure;
            }
        );

        return $message;
    }

    public static function validNumbers(): array
    {
        return [
            'formatted' => ['(813) 391-3333'],
            'bare' => ['8133913333'],
            'with country code' => ['+1 813 391 3333'],
        ];
    }

    #[Test]
    #[DataProvider('validNumbers')]
    public function it_passes_a_valid_us_number(string $value): void
    {
        $this->assertNull($this->check($value));
    }

    public static function invalidNumbers(): array
    {
        return [
            'the number from the bug report' => ['1234567890'],
            'too short' => ['12345'],
            'letters' => ['call me maybe'],
            'exchange starts with 1' => ['8131913333'],
        ];
    }

    #[Test]
    #[DataProvider('invalidNumbers')]
    public function it_fails_anything_that_is_not_a_valid_us_number(string $value): void
    {
        $this->assertSame(__('embed_form.phone_invalid'), $this->check($value));
    }

    #[Test]
    public function an_empty_value_is_left_to_the_required_rule(): void
    {
        // Whether a phone number is mandatory is the field's business, not this
        // rule's - it only judges the ones that are present.
        $this->assertNull($this->check(null));
        $this->assertNull($this->check(''));
    }

    #[Test]
    public function it_accepts_a_number_passed_as_an_integer(): void
    {
        $this->assertNull($this->check(8133913333));
    }

    #[Test]
    public function it_rejects_a_value_that_is_not_stringable(): void
    {
        $this->assertSame(__('embed_form.phone_invalid'), $this->check(['813', '391']));
    }
}
