<?php

namespace Tests\Unit\Services;

use App\Models\Borrower\Borrower;
use App\Models\User\User;
use App\Services\Global\Datatable\QueryFilter;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The datatable filter/sort translator behind every list screen: PrimeReact's
 * filter payload in, where/orderBy clauses out. Only the SQL is inspected - no
 * rows are needed.
 */
#[CoversClass(QueryFilter::class)]
class QueryFilterTest extends TestCase
{
    private function sql(Builder $query): string
    {
        return $query->toRawSql();
    }

    private function constraint(string $field, string $mode, string $value): array
    {
        return [$field => ['constraints' => [['value' => $value, 'matchMode' => $mode]]]];
    }

    public static function modes(): array
    {
        return [
            'contains' => ['contains', "\"email\" like '%ann%'"],
            'startsWith' => ['startsWith', "\"email\" like 'ann%'"],
            'endsWith' => ['endsWith', "\"email\" like '%ann'"],
            'equals' => ['equals', "\"email\" = 'ann'"],
            'notEquals' => ['notEquals', "\"email\" != 'ann'"],
            'lt' => ['lt', "\"email\" < 'ann'"],
            'gte' => ['gte', "\"email\" >= 'ann'"],
            'an unknown mode falls back to contains' => ['fuzzy', "\"email\" like '%ann%'"],
        ];
    }

    #[Test]
    #[DataProvider('modes')]
    public function each_match_mode_becomes_its_where_clause(string $mode, string $expected): void
    {
        $sql = $this->sql(QueryFilter::apply(User::query(), 'id', 'asc', $this->constraint('email', $mode, 'ann')));

        $this->assertStringContainsString($expected, $sql);
    }

    #[Test]
    public function in_takes_a_comma_list(): void
    {
        $sql = $this->sql(QueryFilter::apply(User::query(), 'id', 'asc', $this->constraint('status', 'in', 'Active,Inactive')));

        $this->assertStringContainsString("\"status\" in ('Active', 'Inactive')", $sql);
    }

    #[Test]
    public function sorting_and_simple_filters_apply(): void
    {
        $sql = $this->sql(QueryFilter::apply(User::query(), 'name', 'desc', ['name' => ['value' => ' Ann ']]));

        $this->assertStringContainsString("\"name\" like '%Ann%'", $sql);
        $this->assertStringContainsString('order by "name" desc', $sql);
    }

    #[Test]
    public function blank_values_and_empty_filters_add_nothing(): void
    {
        $plain = $this->sql(QueryFilter::apply(User::query(), 'id', 'asc', []));

        $this->assertSame($plain, $this->sql(QueryFilter::apply(User::query(), 'id', 'asc', $this->constraint('email', 'contains', ''))));
        $this->assertSame($plain, $this->sql(QueryFilter::apply(User::query(), 'id', 'asc', ['email' => ['value' => '  ']])));
        $this->assertSame($plain, $this->sql(QueryFilter::apply(User::query(), 'id', 'asc', 'not json')));
    }

    #[Test]
    public function filters_may_arrive_as_a_json_string(): void
    {
        $sql = $this->sql(QueryFilter::apply(User::query(), 'id', 'asc', json_encode($this->constraint('email', 'equals', 'ann'))));

        $this->assertStringContainsString("\"email\" = 'ann'", $sql);
    }

    #[Test]
    public function an_alias_points_a_screen_field_at_its_column(): void
    {
        $sql = $this->sql(QueryFilter::apply(User::query(), 'id', 'asc', $this->constraint('phone', 'contains', '555'), [
            'aliases' => ['phone' => 'phone_number'],
        ]));

        $this->assertStringContainsString("\"phone_number\" like '%555%'", $sql);
    }

    #[Test]
    public function a_full_name_searches_the_joined_name_columns(): void
    {
        $sql = $this->sql(QueryFilter::apply(Borrower::query(), 'id', 'asc', $this->constraint('full_name', 'startsWith', 'Ann C'), [
            'full_name_field' => 'full_name',
        ]));

        $this->assertStringContainsString("CONCAT_WS(' ', first_name, last_name) LIKE 'Ann C%'", $sql);
    }

    public static function secretColumns(): array
    {
        return [
            'a password hash' => [User::class, 'password'],
            'a remember token' => [User::class, 'remember_token'],
            'a social security number' => [Borrower::class, 'ssn'],
            'a table-qualified secret' => [User::class, 'users.password'],
            'not a column name at all' => [User::class, 'email") or 1=1 --'],
        ];
    }

    /**
     * Defect: the field names come from the request, so any column could be
     * filtered or sorted on - a password hash or an SSN read out one
     * character at a time through "startsWith" (see UserManagementApiTest).
     */
    #[Test]
    #[DataProvider('secretColumns')]
    public function a_secret_or_malformed_column_is_neither_filtered_nor_sorted_on(string $model, string $column): void
    {
        $plain = $this->sql(QueryFilter::apply($model::query(), 'id', 'asc', []));

        $this->assertSame($plain, $this->sql(QueryFilter::apply($model::query(), 'id', 'asc', $this->constraint($column, 'startsWith', '$2y$'))));
        $this->assertSame($plain, $this->sql(QueryFilter::apply($model::query(), 'id', 'asc', [$column => ['value' => '123']])));

        $sorted = $this->sql(QueryFilter::apply($model::query(), $column, 'asc', []));
        $this->assertStringNotContainsString(explode('.', $column)[1] ?? $column, str_replace('"id"', '', $sorted));
    }
}
