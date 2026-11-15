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
use App\Rules\DateOfBirth;
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
            'phone_number_1' => '555' . random_int(2000000, 9999999), // exchange must not start with 0 or 1
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
    public function an_employees_phone_numbers_must_be_real_us_numbers(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/employees', $this->newEmployeePayload([
            'phone_number_1' => '(124) 982-7457', 'phone_number_2' => '555-0100', 'emergency_phone' => '1234567890',
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'phone_number_1' => __('embed_form.phone_invalid'),
            'phone_number_2' => __('embed_form.phone_invalid'),
            'emergency_phone' => __('embed_form.phone_invalid'),
        ]);
    }

    #[Test]
    public function the_phone_number_given_to_an_employees_login_must_be_a_real_us_number(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/employees', $this->newEmployeePayload([
            'create_account' => true, 'phone_number' => '555-0100',
        ]))->assertStatus(422)->assertJsonValidationErrors(['phone_number' => __('embed_form.phone_invalid')]);

        $this->assertSame(0, Employee::count());
    }

    #[Test]
    public function a_paid_seat_is_bought_for_at_least_one_whole_month(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        // (int) of these is 0 or below: a seat invoice for nothing, or a negative one.
        foreach (['-3', '0', 'abc', '1.5'] as $bad) {
            $this->postJson('/api/v1/common/employees', $this->newEmployeePayload(['is_paid' => 1, 'months' => $bad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['months']);
        }

        $this->assertSame(0, Employee::count());
    }

    #[Test]
    public function the_seat_months_stay_optional(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/employees', $this->newEmployeePayload(['months' => null]))->assertCreated();
    }

    #[Test]
    public function an_employee_needs_only_a_name_an_email_and_a_country(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/employees', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'email', 'country'])
            ->assertJsonMissingValidationErrors([
                'last_name', 'designation', 'department', 'phone_number_1', 'city', 'state', 'address',
                'postal_code', 'job_status', 'joining_date', 'date_of_birth', 'gender',
            ]);
    }

    #[Test]
    public function an_employee_is_created_with_just_a_name_an_email_and_a_country(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $id = $this->postJson('/api/v1/common/employees', [
            'first_name' => 'Nina',
            'email' => 'nina-min@example.test',
            'country' => 'United States',
        ])->assertCreated()->json('data.employee.id');

        $employee = Employee::findOrFail($id);
        $this->assertNull($employee->designation);
        $this->assertNull($employee->phone_number_1);
        $this->assertNull($employee->address);
    }

    #[Test]
    public function the_optional_details_can_be_sent_empty(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/employees', [
            'first_name' => 'Nina',
            'email' => 'nina-empty@example.test',
            'country' => 'United States',
            'designation' => null,
            'department' => null,
            'phone_number_1' => null,
            'city' => null,
            'state' => null,
            'address' => null,
        ])->assertCreated();
    }

    #[Test]
    public function an_edit_cannot_clear_the_name_the_email_or_the_country(): void
    {
        $employee = $this->employeeOf($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson("/api/v1/common/employees/{$employee->id}", [
            'first_name' => '',
            'email' => '',
            'country' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['first_name', 'email', 'country']);
    }

    #[Test]
    public function an_edit_can_clear_the_optional_details(): void
    {
        $employee = $this->employeeOf($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson("/api/v1/common/employees/{$employee->id}", [
            'first_name' => 'Nina',
            'email' => $employee->email,
            'country' => 'United States',
            'designation' => null,
            'department' => null,
            'phone_number_1' => null,
            'city' => null,
            'state' => null,
            'address' => null,
        ])->assertOk();

        $fresh = $employee->fresh();
        $this->assertNull($fresh->designation);
        $this->assertNull($fresh->address);
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

    /**
     * Defect: R2 BUG-029 - an employee was stored with job status
     * "[object Object]" (the portal sent an object), shown as such in the list
     * and never matched by the Status filter. Only a known status is taken.
     */
    #[Test]
    public function the_job_status_must_be_active_inactive_or_suspended(): void
    {
        $employee = $this->employeeOf($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/employees', $this->newEmployeePayload(['job_status' => '[object Object]']))
            ->assertStatus(422)->assertJsonValidationErrors(['job_status' => __('employee.job_status_in')]);
        $this->putJson("/api/v1/common/employees/{$employee->id}", $this->newEmployeePayload(['email' => $employee->email, 'job_status' => 'active-ish']))
            ->assertStatus(422)->assertJsonValidationErrors(['job_status']);

        $this->putJson("/api/v1/common/employees/{$employee->id}", $this->newEmployeePayload(['email' => $employee->email, 'job_status' => Employee::STATUS_SUSPENDED]))
            ->assertOk();
        $this->assertSame(Employee::STATUS_SUSPENDED, $employee->fresh()->job_status);
    }

    /** R2 BUG-029: rows already stored as "[object Object]" are repaired on deploy. */
    #[Test]
    public function the_migration_repairs_an_unreadable_job_status(): void
    {
        $broken = $this->employeeOf($this->partner, ['job_status' => '[object Object]']);
        $suspended = $this->employeeOf($this->partner, ['job_status' => Employee::STATUS_SUSPENDED]);

        (require database_path('migrations/2026_10_28_100100_repair_unreadable_employee_job_status.php'))->up();

        $this->assertSame(Employee::STATUS_ACTIVE, $broken->fresh()->job_status);
        $this->assertSame(Employee::STATUS_SUSPENDED, $suspended->fresh()->job_status);
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

    /**
     * Defect: an employee's date of birth was only `before:today`, so a team
     * member could be saved as 10 years old.
     */
    #[Test]
    public function an_employee_must_be_at_least_the_platform_minimum_age(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/employees', $this->newEmployeePayload([
            'date_of_birth' => DateOfBirth::latestAllowedDate()->addDay()->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'date_of_birth' => __('common.date_of_birth_minimum_age', ['age' => 18]),
        ]);

        $this->postJson('/api/v1/common/employees', $this->newEmployeePayload([
            'date_of_birth' => DateOfBirth::latestAllowedDate()->toDateString(),
        ]))->assertCreated();
    }
}
