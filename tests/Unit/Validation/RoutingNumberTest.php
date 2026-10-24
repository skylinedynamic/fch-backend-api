<?php

namespace Tests\Unit\Validation;

use App\Rules\RoutingNumber;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A US bank routing (ABA) number: nine digits, as a partner's bank details
 * (Settings -> Payment Method) hand it to the borrowers paying by transfer.
 */
#[CoversClass(RoutingNumber::class)]
class RoutingNumberTest extends TestCase
{
    private function fails(mixed $value): bool
    {
        return Validator::make(
            ['routing_number' => $value],
            ['routing_number' => ['nullable', new RoutingNumber()]]
        )->fails();
    }

    public static function rejected(): array
    {
        return [
            'eight digits' => ['12100035'],
            'ten digits' => ['1210003580'],
            'contains a letter' => ['12100035A'],
            'all letters' => ['abcdefghi'],
            'hyphenated' => ['121-000-358'],
            'inner space' => ['121 000358'],
            'leading space' => [' 121000358'],
            'decimal' => ['12100035.8'],
            'negative' => ['-12100035'],
            'an array' => [['121000358']],
        ];
    }

    #[Test]
    #[DataProvider('rejected')]
    public function a_routing_number_must_be_exactly_nine_digits(mixed $value): void
    {
        $this->assertTrue($this->fails($value), json_encode($value) . ' should have been rejected');
    }

    #[Test]
    public function nine_digits_pass(): void
    {
        $this->assertFalse($this->fails('121000358'));
    }

    #[Test]
    public function a_leading_zero_survives(): void
    {
        // Routing numbers start with 0 or 1 (the Federal Reserve district), so
        // a number-typed check would lose the zero.
        $this->assertFalse($this->fails('021000021'));
    }

    #[Test]
    public function an_empty_value_is_left_to_required_or_nullable(): void
    {
        $this->assertFalse($this->fails(null));
        $this->assertFalse($this->fails(''));
    }

    #[Test]
    public function the_message_names_the_format(): void
    {
        $errors = Validator::make(['routing_number' => '123'], ['routing_number' => [new RoutingNumber()]])->errors();

        $this->assertSame(__('common.routing_number_invalid'), $errors->first('routing_number'));
        $this->assertStringContainsString('9-digit', __('common.routing_number_invalid'));
    }
}
