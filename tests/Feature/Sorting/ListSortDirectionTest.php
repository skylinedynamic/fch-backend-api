<?php

namespace Tests\Feature\Sorting;

use App\Models\CreditBuilder\CapitalAccess;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Services\FCH\Admin\CreditBuilder\CreditAccountService;
use App\Services\FCH\Common\CreditBuilder\CapitalAccessService;
use App\Services\FCH\Common\Employee\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-010: Capital Access, Credit Accounts and Employees flipped the requested
 * sort direction, so a header's first click (ascending) listed Z-A. The order
 * sent is the order returned.
 */
#[CoversClass(CapitalAccessService::class)]
#[CoversClass(CreditAccountService::class)]
#[CoversClass(EmployeeService::class)]
class ListSortDirectionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->actingAsUser($this->makePartnerAdmin($this->host));
    }

    private function names($response, string $key): array
    {
        return collect($response->getData(true)['data'])->pluck($key)->all();
    }

    #[Test]
    public function capital_access_sorts_in_the_direction_asked(): void
    {
        foreach (['Relationship Stack', 'Zero-Cost Stack', 'Startup Stack'] as $name) {
            CapitalAccess::create(['name' => $name, 'status' => CapitalAccess::STATUS_ACTIVE]);
        }
        $service = app(CapitalAccessService::class);

        $this->assertSame(['Relationship Stack', 'Startup Stack', 'Zero-Cost Stack'], $this->names($service->list(['sort_field' => 'name', 'sort_order' => 'asc']), 'name'));
        $this->assertSame(['Zero-Cost Stack', 'Startup Stack', 'Relationship Stack'], $this->names($service->list(['sort_field' => 'name', 'sort_order' => 'desc']), 'name'));
    }

    #[Test]
    public function credit_accounts_sort_in_the_direction_asked(): void
    {
        foreach (['Voice Card', 'Wyndham Card', 'Amex Card'] as $name) {
            DB::table('credit_accounts')->insert(['card_name' => $name, 'issuer' => 'Bank', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $service = app(CreditAccountService::class);

        $this->assertSame(['Amex Card', 'Voice Card', 'Wyndham Card'], $this->names($service->list(['sort_field' => 'card_name', 'sort_order' => 'asc']), 'card_name'));
        $this->assertSame(['Wyndham Card', 'Voice Card', 'Amex Card'], $this->names($service->list(['sort_field' => 'card_name', 'sort_order' => 'desc']), 'card_name'));
    }

    #[Test]
    public function employees_sort_in_the_direction_asked(): void
    {
        foreach (['Mia', 'Zoe', 'Adam'] as $name) {
            Employee::create(['partner_id' => $this->host->id, 'first_name' => $name, 'email' => strtolower($name) . '@host.test']);
        }
        $service = app(EmployeeService::class);
        $list = fn (string $order) => $this->names($service->list($this->host->id, ['sort_field' => 'full_name', 'sort_order' => $order]), 'first_name');

        $this->assertSame(['Adam', 'Mia', 'Zoe'], $list('asc'));
        $this->assertSame(['Zoe', 'Mia', 'Adam'], $list('desc'));
    }

    #[Test]
    public function with_no_order_given_the_newest_rows_come_first(): void
    {
        $first = CapitalAccess::create(['name' => 'Older', 'status' => CapitalAccess::STATUS_ACTIVE]);
        $second = CapitalAccess::create(['name' => 'Newer', 'status' => CapitalAccess::STATUS_ACTIVE]);

        $this->assertSame([$second->id, $first->id], $this->names(app(CapitalAccessService::class)->list([]), 'id'));
    }
}
