<?php

namespace Tests\Feature\Employee;

use App\Http\Controllers\Api\V1\FCH\Common\Employee\EmployeeController;
use App\Models\Common\Role;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The partner portal's Employees screen: /common/employees*. Employee records
 * hold personal details (date of birth, address, emergency contacts), so each
 * partner reaches its own employees only.
 */
#[CoversClass(EmployeeController::class)]
class EmployeeApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private Partner $otherPartner;
    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->otherPartner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
    }

    private function employeeOf(Partner $partner, array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'partner_id' => $partner->id,
            'first_name' => 'Erin',
            'last_name' => 'Employee',
            'email' => 'erin-' . uniqid() . '@example.test',
            'designation' => 'Analyst',
            'department' => 'Finance',
            'job_status' => 'Active',
        ], $attributes));
    }

    private function newEmployeePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Nina',
            'last_name' => 'New',
            'email' => 'nina-' . uniqid() . '@example.test',
            'phone_number_1' => '555' . random_int(1000000, 9999999),
            'designation' => 'Coordinator',
            'department' => 'Operations',
            'city' => 'Austin',
            'state' => 'Texas',
            'country' => 'United States',
            'address' => '1 Main St',
        ], $overrides);
    }

    #[Test]
    public function the_employee_routes_need_a_signed_in_partner_user(): void
    {
        $this->getJson('/api/v1/common/employees')->assertUnauthorized();

        $this->actingAsUser($this->makeBorrowerUser($this->partner));
        $this->getJson('/api/v1/common/employees')->assertForbidden();
    }

    #[Test]
    public function the_lists_hold_only_my_employees(): void
    {
        $mine = $this->employeeOf($this->partner);
        $this->employeeOf($this->otherPartner);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson('/api/v1/common/employees')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->postJson('/api/v1/common/employees/fetch-employees')->assertOk()->assertJsonPath('data.0.id', $mine->id);
    }

    #[Test]
    public function creating_an_employee_files_it_under_my_partner(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $id = $this->postJson('/api/v1/common/employees', $this->newEmployeePayload())
            ->assertCreated()
            ->json('data.employee.id');

        $this->assertSame($this->partner->id, Employee::findOrFail($id)->partner_id);
    }

    #[Test]
    public function creating_an_employee_with_an_account_gives_them_a_login_with_the_role(): void
    {
        $role = Role::create(['name' => 'Sales', 'guard_name' => 'api', 'partner_id' => $this->partner->id, 'code' => 'sales']);
        $this->actingAsUser($this->partnerAdmin);

        $response = $this->postJson('/api/v1/common/employees', $this->newEmployeePayload([
            'create_account' => true,
            'role_id' => $role->id,
        ]))->assertCreated()->assertJsonMissingPath('data.user.password');

        $user = User::findOrFail($response->json('data.user.id'));
        $this->assertSame(Employee::class, $user->entity_type);
        $this->assertSame($response->json('data.employee.id'), $user->entity_id);
        $this->assertSame(User::PARTNER, $user->user_type);
    }

    #[Test]
    public function an_employee_needs_a_name_role_details_and_address(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/employees', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'designation', 'department', 'city', 'state', 'country', 'address']);
    }

    #[Test]
    public function an_email_is_unique_within_my_partner_only(): void
    {
        $this->employeeOf($this->partner, ['email' => 'same@example.test']);
        $this->employeeOf($this->otherPartner, ['email' => 'elsewhere@example.test']);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/employees', $this->newEmployeePayload(['email' => 'same@example.test']))->assertStatus(422);
        $this->postJson('/api/v1/common/employees', $this->newEmployeePayload(['email' => 'elsewhere@example.test']))->assertCreated();
    }

    #[Test]
    public function i_open_and_edit_my_employee(): void
    {
        $employee = $this->employeeOf($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/employees/{$employee->id}")->assertOk()->assertJsonPath('data.id', $employee->id);
        $this->putJson("/api/v1/common/employees/{$employee->id}", $this->newEmployeePayload([
            'email' => $employee->email,
            'designation' => 'Lead Analyst',
        ]))->assertOk();

        $this->assertSame('Lead Analyst', $employee->fresh()->designation);
    }

    /**
     * Defect: show looked the employee up by id alone, so any partner user
     * could read another partner's staff records - date of birth, address,
     * emergency contacts.
     */
    #[Test]
    public function another_partners_employee_cannot_be_opened(): void
    {
        $theirs = $this->employeeOf($this->otherPartner, ['date_of_birth' => '1980-01-01']);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/employees/{$theirs->id}")
            ->assertNotFound()
            ->assertJsonMissingPath('data.date_of_birth');
    }

    #[Test]
    public function another_partners_employee_cannot_be_edited_or_deleted(): void
    {
        $theirs = $this->employeeOf($this->otherPartner, ['designation' => 'Theirs']);
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson("/api/v1/common/employees/{$theirs->id}", $this->newEmployeePayload(['designation' => 'Hijacked']))
            ->assertForbidden();
        $this->deleteJson("/api/v1/common/employees/{$theirs->id}")->assertForbidden();

        $this->assertSame('Theirs', $theirs->fresh()->designation);
    }

    #[Test]
    public function deleting_an_employee_also_removes_their_login_by_default(): void
    {
        $employee = $this->employeeOf($this->partner);
        $login = $this->makeUser(['partner_id' => $this->partner->id, 'entity_type' => Employee::class, 'entity_id' => $employee->id]);
        $this->actingAsUser($this->partnerAdmin);

        $this->deleteJson("/api/v1/common/employees/{$employee->id}")->assertOk();

        $this->assertSoftDeleted($employee);
        $this->assertSoftDeleted($login);
    }

    /**
     * Defect: the records were scoped, but the image files were deleted for
     * every id sent - another partner's staff photos included.
     */
    #[Test]
    public function multiple_delete_touches_only_my_employees_and_their_files(): void
    {
        Storage::disk('public')->put('employees/mine.jpg', 'x');
        Storage::disk('public')->put('employees/theirs.jpg', 'x');
        $mine = $this->employeeOf($this->partner, ['image' => 'employees/mine.jpg']);
        $theirs = $this->employeeOf($this->otherPartner, ['image' => 'employees/theirs.jpg']);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/employees/multiple-delete', ['ids' => [$mine->id, $theirs->id]])->assertOk();

        $this->assertSoftDeleted($mine);
        $this->assertNotSoftDeleted($theirs);
        Storage::disk('public')->assertExists('employees/theirs.jpg');
    }

    #[Test]
    public function the_department_and_status_filters_are_listed_for_my_partner(): void
    {
        $this->employeeOf($this->partner, ['department' => 'Finance']);
        $this->employeeOf($this->otherPartner, ['department' => 'Secret Dept']);
        $this->actingAsUser($this->partnerAdmin);

        $departments = $this->postJson('/api/v1/common/employees/fetch-department-status', ['type' => 'department'])
            ->assertOk()->json('data');

        $this->assertStringNotContainsString('Secret Dept', json_encode($departments));
        $this->postJson('/api/v1/common/employees/fetch-department-status', ['type' => 'salary'])->assertStatus(400);
    }

    #[Test]
    public function employees_without_a_login_are_offered_for_a_new_user(): void
    {
        $free = $this->employeeOf($this->partner);
        $taken = $this->employeeOf($this->partner);
        $this->makeUser(['partner_id' => $this->partner->id, 'entity_type' => Employee::class, 'entity_id' => $taken->id]);
        $this->employeeOf($this->otherPartner);
        $this->actingAsUser($this->partnerAdmin);

        $ids = collect($this->getJson('/api/v1/common/employees-available-for-user')->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$free->id], $ids->all());
    }

    #[Test]
    public function the_next_employee_id_is_offered(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson('/api/v1/common/employees-next-id')->assertOk()->assertJsonStructure(['data' => ['next_id']]);
    }
}
