<?php

namespace Tests\Unit\Validation;

use App\Rules\DisjointFrom;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A list that may not share a value with its sibling list - a lender rule's
 * restricted states against its allowed states (R2 BUG-012).
 */
#[CoversClass(DisjointFrom::class)]
class DisjointFromTest extends TestCase
{
    private function errors(array $row): array
    {
        return Validator::make(
            ['rules' => [$row]],
            ['rules.*.restricted' => ['nullable', 'array', new DisjointFrom('allowed', 'funding_sequencing.state_allowed_and_restricted')]]
        )->errors()->get('rules.0.restricted');
    }

    public static function disjointRows(): array
    {
        return [
            'no overlap' => [['allowed' => ['CA'], 'restricted' => ['TX']]],
            'no restricted list' => [['allowed' => ['TX']]],
            'empty restricted list' => [['allowed' => ['TX'], 'restricted' => []]],
            'no allowed list' => [['restricted' => ['TX']]],
            'allowed is not a list' => [['allowed' => 'TX', 'restricted' => ['TX']]],
        ];
    }

    #[Test]
    #[DataProvider('disjointRows')]
    public function lists_without_a_shared_value_pass(array $row): void
    {
        $this->assertSame([], $this->errors($row));
    }

    #[Test]
    public function a_shared_value_fails_and_is_named_once(): void
    {
        $this->assertSame(
            [__('funding_sequencing.state_allowed_and_restricted', ['values' => 'TX, NY'])],
            $this->errors(['allowed' => ['TX', 'NY', 'CA'], 'restricted' => ['TX', 'NY', 'TX', 'WA']])
        );
    }

    #[Test]
    public function the_sibling_is_read_from_the_same_row_only(): void
    {
        $errors = Validator::make(
            ['rules' => [['allowed' => ['TX']], ['restricted' => ['TX']]]],
            ['rules.*.restricted' => [new DisjointFrom('allowed', 'funding_sequencing.state_allowed_and_restricted')]]
        )->errors();

        $this->assertFalse($errors->has('rules.1.restricted'));
    }
}
