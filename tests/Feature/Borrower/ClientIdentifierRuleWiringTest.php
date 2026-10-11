<?php

namespace Tests\Feature\Borrower;

use App\Http\Requests\Api\V1\FCH\Common\Borrower\StoreBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateBorrowerRequest;
use App\Rules\BusinessEin;
use App\Rules\DunsNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * That the admin's client create and update paths actually validate against the
 * shared identifier rules.
 *
 * BusinessIdentifierRulesTest covers what the rules accept; this covers that
 * these two requests are the ones using them, because both fields were
 * `string|max:255` here while the borrower portal enforced a length - the same
 * field, two surfaces, two answers.
 *
 * A Feature test rather than a Unit one because UpdateBorrowerRequest::rules()
 * reads the borrower from the database to decide its free-trial rules, so it
 * cannot be built without a connection.
 */
class ClientIdentifierRuleWiringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function clientWriteRequests(): iterable
    {
        yield 'create' => [StoreBorrowerRequest::class];
        yield 'update' => [UpdateBorrowerRequest::class];
    }

    #[Test]
    #[DataProvider('clientWriteRequests')]
    public function it_validates_both_identifiers_with_the_shared_rules(string $requestClass): void
    {
        $rules = (new $requestClass())->rules();

        $this->assertTrue(
            collect($rules['business_ein'])->contains(fn ($rule) => $rule instanceof BusinessEin),
            "{$requestClass} must validate business_ein with the shared BusinessEin rule"
        );

        $this->assertTrue(
            collect($rules['duns_number'])->contains(fn ($rule) => $rule instanceof DunsNumber),
            "{$requestClass} must validate duns_number with the shared DunsNumber rule"
        );
    }

    #[Test]
    #[DataProvider('clientWriteRequests')]
    public function it_keeps_both_identifiers_optional(string $requestClass): void
    {
        // Neither is known when a client is first entered, so demanding one here
        // would block creating the record at all.
        $rules = (new $requestClass())->rules();

        $this->assertContains('nullable', $rules['business_ein']);
        $this->assertContains('nullable', $rules['duns_number']);
    }
}
