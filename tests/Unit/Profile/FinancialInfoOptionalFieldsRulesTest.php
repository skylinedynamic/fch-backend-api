<?php

namespace Tests\Unit\Profile;

use App\Http\Requests\Api\V1\Borrower\Profile\UpdateFinancialInfoRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The borrower's Financial tab: Annual Personal Income and Average Monthly
 * Deposits are optional. Left out or empty is fine; when given, they are
 * still checked as amounts. Monthly and Annual Revenue are required: empty
 * (null) is refused, but zero is a real amount and is accepted.
 */
#[CoversClass(UpdateFinancialInfoRequest::class)]
class FinancialInfoOptionalFieldsRulesTest extends TestCase
{
    private const REQUIRED = [
        'currency' => 'USD',
        'monthly_revenue' => 10000,
        'annual_revenue' => 120000,
    ];

    private function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new UpdateFinancialInfoRequest();

        return Validator::make($data, $request->rules(), $request->messages());
    }

    public static function optionalFields(): array
    {
        return [['annual_personal_income'], ['average_monthly_deposits']];
    }

    #[Test]
    public function the_tab_saves_without_either_field(): void
    {
        $this->assertTrue($this->validate(self::REQUIRED)->passes());
    }

    #[Test]
    #[DataProvider('optionalFields')]
    public function an_empty_value_is_allowed(string $field): void
    {
        $this->assertTrue($this->validate([...self::REQUIRED, $field => null])->passes());
    }

    #[Test]
    #[DataProvider('optionalFields')]
    public function a_given_value_must_still_be_an_amount(string $field): void
    {
        $this->assertTrue($this->validate([...self::REQUIRED, $field => -1])->errors()->has($field));
        $this->assertTrue($this->validate([...self::REQUIRED, $field => 'lots'])->errors()->has($field));
        $this->assertTrue($this->validate([...self::REQUIRED, $field => 85000])->passes());
    }

    #[Test]
    public function monthly_and_annual_revenue_are_still_required(): void
    {
        $errors = $this->validate(['currency' => 'USD'])->errors();

        $this->assertTrue($errors->has('monthly_revenue'));
        $this->assertTrue($errors->has('annual_revenue'));
    }

    public static function revenueFields(): array
    {
        return [['monthly_revenue'], ['annual_revenue']];
    }

    public static function emptyValues(): array
    {
        return ['null' => [null], 'empty string' => ['']];
    }

    #[Test]
    #[DataProvider('revenueFields')]
    public function revenue_cannot_be_saved_empty(string $field): void
    {
        foreach ([null, '', '   '] as $empty) {
            $this->assertTrue(
                $this->validate([...self::REQUIRED, $field => $empty])->errors()->has($field),
                "{$field} accepted " . var_export($empty, true)
            );
        }
    }

    #[Test]
    #[DataProvider('revenueFields')]
    public function revenue_can_be_zero(string $field): void
    {
        foreach ([0, '0', '0.00'] as $zero) {
            $this->assertTrue(
                $this->validate([...self::REQUIRED, $field => $zero])->passes(),
                "{$field} refused " . var_export($zero, true)
            );
        }
    }
}
