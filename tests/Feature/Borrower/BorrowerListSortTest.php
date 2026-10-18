<?php

namespace Tests\Feature\Borrower;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Borrower\BorrowerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-004: clicking the FULL NAME sort on the Clients list (owner and partner
 * portals) answered 500 and emptied the table. full_name is an accessor on
 * Borrower, not a column, so ORDER BY full_name failed in SQL.
 */
#[CoversClass(BorrowerService::class)]
class BorrowerListSortTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->admin = User::create([
            'name' => 'Host Admin',
            'email' => 'admin@host.test',
            'password' => Hash::make('secret-password'),
            'status' => User::STATUS['active'],
            'user_type' => User::ADMIN,
            'partner_id' => $host->id,
        ]);

        foreach ([['Zia', 'Fuentes'], ['Kim', 'Saunders'], ['Orli', 'Hayes']] as [$first, $last]) {
            Borrower::create([
                'partner_id' => $host->id,
                'status' => Borrower::STATUS_ACTIVE,
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower($first) . '@example.test',
            ]);
        }
    }

    private function names(string $order): array
    {
        Passport::actingAs($this->admin);

        $response = $this->postJson('/api/v1/common/borrowers/fetch-all-borrowers', [
            'page' => 1,
            'per_page' => 10,
            'sort_field' => 'full_name',
            'sort_order' => $order,
        ]);

        $response->assertOk();

        return collect($response->json('data'))->pluck('email')->all();
    }

    #[Test]
    public function sorting_by_full_name_ascending_orders_a_to_z(): void
    {
        $this->assertSame(['kim@example.test', 'orli@example.test', 'zia@example.test'], $this->names('asc'));
    }

    #[Test]
    public function sorting_by_full_name_descending_orders_z_to_a(): void
    {
        $this->assertSame(['zia@example.test', 'orli@example.test', 'kim@example.test'], $this->names('desc'));
    }
}
