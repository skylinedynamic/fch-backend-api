<?php

namespace Tests\Unit\Validation;

use App\Rules\AdultDateOfBirth;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An owner's or co-borrower's date of birth: at least 18 years ago
 * (R2 BUG-031). Empty and unparseable values are left to required / date.
 */
#[CoversClass(AdultDateOfBirth::class)]
class AdultDateOfBirthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-21 23:30:00');
    }

    private function fails(mixed $value): bool
    {
        return Validator::make(['dob' => $value], ['dob' => [new AdultDateOfBirth()]])->fails();
    }

    public static function passing(): array
    {
        return [
            'eighteen today' => ['2008-10-21'],
            'well over' => ['1985-04-12'],
            'empty' => [''],
            'not a date (left to `date`)' => ['soon'],
            'not a string' => [null],
        ];
    }

    public static function failing(): array
    {
        return [
            'eighteen tomorrow' => ['2008-10-22'],
            'seventeen' => ['2009-10-15'],
            'born in the future' => ['2027-01-01'],
        ];
    }

    #[Test]
    #[DataProvider('passing')]
    public function an_adult_or_an_empty_value_passes(mixed $value): void
    {
        $this->assertFalse($this->fails($value));
    }

    #[Test]
    #[DataProvider('failing')]
    public function anyone_younger_than_18_fails(string $value): void
    {
        $this->assertTrue($this->fails($value));
    }

    #[Test]
    public function the_age_is_the_embedded_forms(): void
    {
        config(['funding_application.embed_form.minimum_age' => 21]);

        $this->assertTrue($this->fails('2008-10-21'));
        $this->assertSame('2005-10-21', AdultDateOfBirth::latestDate()->toDateString());
    }
}
