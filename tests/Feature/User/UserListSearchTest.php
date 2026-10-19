<?php

namespace Tests\Feature\User;

use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The search box on the admin Users screen. Any search term used to answer
 * 500 "Failed to retrieve users": User::scopeSearch() also matched an
 * `end_date` column the users table does not have. Found by the admin
 * portal's live E2E (sidebar/users/users.live.spec.ts).
 */
class UserListSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Search Partner',
            'full_name' => 'Search Partner',
            'email' => 'search-partner@example.test',
        ]);

        $this->admin = User::create([
            'name' => 'Partner Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'partner_id' => $partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'status' => User::STATUS['active'],
        ]);

        Passport::actingAs($this->admin, [], 'api');
    }

    #[Test]
    public function a_search_that_matches_returns_the_user(): void
    {
        $this->postJson('/api/v1/common/users/fetch-users', ['page' => 1, 'per_page' => 10, 'search' => 'Partner Adm'])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.0.email', 'admin@example.test');
    }

    /**
     * SQLite reads an unknown double-quoted column as a string literal, so the
     * HTTP tests above cannot fail on a dropped column the way MySQL does.
     * Check the columns the search uses against the real users table instead.
     */
    #[Test]
    public function the_search_only_uses_columns_the_users_table_has(): void
    {
        $sql = User::query()->search('x')->toSql();
        preg_match_all('/"users"\."(\w+)"|(?<![\w."])"(\w+)" like/', $sql, $matches);
        $columns = array_unique(array_filter(array_merge($matches[1], $matches[2])));

        $this->assertNotEmpty($columns, $sql);
        foreach ($columns as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), "users.{$column} is searched but does not exist");
        }
    }

    #[Test]
    public function a_search_that_matches_nothing_returns_an_empty_list(): void
    {
        $this->postJson('/api/v1/common/users/fetch-users', ['page' => 1, 'per_page' => 10, 'search' => 'zz-no-such-user'])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(0, 'data');
    }
}
