<?php

namespace Tests\Feature\Employee;

use App\Http\Controllers\Api\V1\FCH\Common\Department\DepartmentController;
use App\Models\Department\Department;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Department\DepartmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Employees > Add Employee > Department > Add New: the partner's department list.
 */
#[CoversClass(DepartmentController::class)]
#[CoversClass(DepartmentService::class)]
class DepartmentApiTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $partner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = $this->makeHostPartner();
        $this->admin = $this->makePartnerAdmin($this->partner);
    }

    private function department(string $name, ?Partner $partner = null): Department
    {
        return Department::create(['name' => $name, 'partner_id' => ($partner ?? $this->partner)->id]);
    }

    private function employeeIn(string $department, ?Partner $partner = null): Employee
    {
        return Employee::create([
            'partner_id' => ($partner ?? $this->partner)->id,
            'first_name' => 'Erin',
            'last_name' => 'Employee',
            'email' => 'erin-'.uniqid().'@example.test',
            'designation' => 'Analyst',
            'department' => $department,
            'job_status' => 'Active',
        ]);
    }

    #[Test]
    public function a_department_is_added(): void
    {
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/common/departments', ['name' => 'Sales'])->assertOk();

        $this->assertDatabaseHas('departments', ['name' => 'Sales', 'partner_id' => $this->partner->id]);
    }

    /** Defect: BUG-182 - "Sales" could be added a second time, so the dropdown showed it twice. */
    #[Test]
    public function a_department_name_that_already_exists_is_refused(): void
    {
        $this->department('Sales');
        $this->actingAsUser($this->admin);

        foreach (['Sales', ' sales '] as $name) {
            $this->postJson('/api/v1/common/departments', ['name' => $name])
                ->assertStatus(422)
                ->assertJsonPath('errors.name', 'Department already exists.');
        }

        $this->assertSame(1, Department::where('partner_id', $this->partner->id)->count());
    }

    #[Test]
    public function another_partners_department_name_does_not_block_this_one(): void
    {
        $this->department('Sales', $this->makeCertifiedPartner());
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/common/departments', ['name' => 'Sales'])->assertOk();
    }

    /**
     * Defect: BUG-183 - a department used by employees was deleted at once, and
     * those employees were left with a department the form and filter no longer offered.
     */
    #[Test]
    public function a_department_used_by_an_employee_is_not_deleted(): void
    {
        $department = $this->department('QA Dept');
        $this->employeeIn('QA Dept');
        $this->employeeIn('QA Dept');
        $this->actingAsUser($this->admin);

        $this->deleteJson("/api/v1/common/departments/{$department->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Department "QA Dept" is assigned to 2 employees and cannot be deleted. Move them to another department first.');

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    #[Test]
    public function a_department_used_only_by_another_partners_employee_or_a_deleted_one_is_deleted(): void
    {
        $department = $this->department('QA Dept');
        $this->employeeIn('QA Dept', $this->makeCertifiedPartner());
        $this->employeeIn('QA Dept')->delete();
        $this->actingAsUser($this->admin);

        $this->deleteJson("/api/v1/common/departments/{$department->id}")->assertOk();

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }

    #[Test]
    public function an_unexpected_error_on_delete_gives_a_plain_message_not_the_error(): void
    {
        $this->mock(DepartmentService::class)->shouldReceive('destroy')->andThrow(new RuntimeException('SQLSTATE[HY000] secret detail'));
        $this->actingAsUser($this->admin);

        $this->deleteJson('/api/v1/common/departments/1')
            ->assertStatus(400)
            ->assertJsonPath('message', 'Department not found.')
            ->assertDontSee('SQLSTATE');
    }

    #[Test]
    public function another_partners_department_cannot_be_deleted(): void
    {
        $department = $this->department('Theirs', $this->makeCertifiedPartner());
        $this->actingAsUser($this->admin);

        $this->deleteJson("/api/v1/common/departments/{$department->id}")->assertStatus(404);

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    /**
     * The Add New Department list says how many of the partner's employees use
     * each department, so the x can open "cannot be deleted" for one in use and
     * the confirmation only for one that is free - as for clients and blog tags.
     * Employees keep the department as text, matched case-insensitively, the
     * same way the delete check counts them.
     */
    #[Test]
    public function the_list_says_how_many_employees_use_each_department(): void
    {
        $sales = $this->department('Sales');
        $free = $this->department('Compliance');
        $this->employeeIn('Sales');
        $this->employeeIn('sales');
        // Another partner's employee in its own "Sales" does not count here.
        $other = $this->makeCertifiedPartner();
        $this->employeeIn('Sales', $other);
        $this->actingAsUser($this->admin);

        $rows = collect($this->postJson('/api/v1/common/departments/fetch-departments')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertSame(2, $rows[$sales->id]['employees_count']);
        $this->assertSame(0, $rows[$free->id]['employees_count']);
    }
}
