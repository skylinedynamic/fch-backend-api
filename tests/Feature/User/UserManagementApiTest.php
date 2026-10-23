<?php

namespace Tests\Feature\User;

use App\Http\Controllers\Api\V1\FCH\Common\User\UserController;
use App\Models\Common\AppConfiguration;
use App\Models\Common\Role;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The partner portal's Users screen: /common/users*. A partner manages the
 * logins of its own team - partner and employee accounts, never borrowers,
 * never another partner's - the same set the screen's own list shows.
 */
#[CoversClass(UserController::class)]
class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $partnerAdmin;
    private Role $salesRole;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->salesRole = Role::create(['name' => 'Sales', 'guard_name' => 'api', 'partner_id' => $this->partner->id, 'code' => 'sales']);
    }

    private function employeeOf(Partner $partner): Employee
    {
        return Employee::create([
            'partner_id' => $partner->id,
            'first_name' => 'New',
            'last_name' => 'Hire',
            'email' => 'hire-' . uniqid() . '@example.test',
        ]);
    }

    private function newUserPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Hire',
            'email' => 'login-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'role_id' => $this->salesRole->id,
            'employee_id' => $this->employeeOf($this->partner)->id,
        ], $overrides);
    }

    private function updatePayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => '555-' . random_int(1000000, 9999999),
            'role_id' => $this->salesRole->id,
            'status' => User::STATUS['active'],
        ], $overrides);
    }

    #[Test]
    public function the_user_routes_need_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/common/users')->assertUnauthorized();
        $this->postJson('/api/v1/common/users/fetch-users')->assertUnauthorized();
        $this->postJson('/api/v1/common/users')->assertUnauthorized();
    }

    /**
     * Defect: the datatable filters and sort take the column name from the
     * request, so a list could be filtered on any column - the password hash
     * included. With "startsWith" and the row count that answers yes/no per
     * character, enough to read every hash on the list out one character at a
     * time (and the same for ssn on the borrower lists).
     */
    #[Test]
    public function the_list_cannot_be_filtered_or_sorted_on_a_secret_column(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $probe = fn (string $prefix) => $this->postJson('/api/v1/common/users/fetch-users', [
            'filters' => ['password' => ['constraints' => [['value' => $prefix, 'matchMode' => 'startsWith']]]],
        ])->assertOk()->json('data');

        // A hash starts "$2y$"; "zz" matches nothing - the filter must not tell them apart.
        $this->assertCount(count($probe('zz')), $probe('$2y$'));

        $this->postJson('/api/v1/common/users/fetch-users', ['sort_field' => 'password', 'sort_order' => 'asc'])->assertOk();
    }

    #[Test]
    public function the_lists_hold_only_my_partners_team(): void
    {
        $staff = $this->makeStaffUser($this->partner);
        $this->makeStaffUser($this->host);
        $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $listed = collect($this->postJson('/api/v1/common/users/fetch-users')->assertOk()->json('data'))->pluck('id')->sort()->values();

        $this->assertSame([$this->partnerAdmin->id, $staff->id], $listed->all());
    }

    #[Test]
    public function creating_a_user_links_it_to_my_employee_with_my_role_and_my_user_type(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $payload = $this->newUserPayload();

        $id = $this->postJson('/api/v1/common/users', $payload)->assertOk()->json('data.id');

        $user = User::findOrFail($id);
        $this->assertSame($this->partner->id, $user->partner_id);
        $this->assertSame(Employee::class, $user->entity_type);
        $this->assertSame($payload['employee_id'], $user->entity_id);
        $this->assertSame(User::PARTNER, $user->user_type);
        $this->assertTrue($user->hasRole('Sales'));
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    #[Test]
    public function another_partners_employee_or_role_cannot_be_used(): void
    {
        $foreignRole = Role::create(['name' => 'Hosts', 'guard_name' => 'api', 'partner_id' => $this->host->id, 'code' => 'hosts']);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/users', $this->newUserPayload(['employee_id' => $this->employeeOf($this->host)->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id']);

        $this->postJson('/api/v1/common/users', $this->newUserPayload(['role_id' => $foreignRole->id]))->assertStatus(400);
    }

    #[Test]
    public function an_employee_gets_one_login_only(): void
    {
        $employee = $this->employeeOf($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/users', $this->newUserPayload(['employee_id' => $employee->id]))->assertOk();
        $this->postJson('/api/v1/common/users', $this->newUserPayload(['employee_id' => $employee->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id']);
    }

    #[Test]
    public function a_non_host_partner_without_seats_left_is_told_to_buy_more(): void
    {
        // three seats by default with no subscription: the partner's own login plus two
        $this->makeStaffUser($this->partner);
        $this->makeStaffUser($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/users', $this->newUserPayload())
            ->assertStatus(400)
            ->assertJsonPath('status', false);
    }

    #[Test]
    public function the_host_has_no_seat_limit(): void
    {
        $hostAdmin = $this->makePartnerAdmin($this->host);
        foreach (range(1, 3) as $_) {
            $this->makeStaffUser($this->host);
        }
        $hostRole = Role::create(['name' => 'Sales', 'guard_name' => 'api', 'partner_id' => $this->host->id, 'code' => 'sales']);
        // The host is never held to the white-label setup check (Controller::checkWhiteLabelConfiguration).
        AppConfiguration::create(['partner_id' => $this->host->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Host']);
        $this->actingAsUser($hostAdmin);

        $this->postJson('/api/v1/common/users', $this->newUserPayload([
            'role_id' => $hostRole->id,
            'employee_id' => $this->employeeOf($this->host)->id,
        ]))->assertOk();
    }

    #[Test]
    public function i_can_open_and_edit_my_teams_user(): void
    {
        $staff = $this->makeStaffUser($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/users/{$staff->id}")->assertOk()->assertJsonPath('data.id', $staff->id);
        $this->putJson("/api/v1/common/users/{$staff->id}", $this->updatePayload($staff, ['name' => 'Renamed']))->assertOk();

        $this->assertSame('Renamed', $staff->fresh()->name);
    }

    #[Test]
    public function a_phone_number_is_optional_on_add_and_edit(): void
    {
        $staff = $this->makeStaffUser($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/users', $this->newUserPayload())->assertOk();

        $this->putJson("/api/v1/common/users/{$staff->id}", $this->updatePayload($staff, ['phone_number' => null]))->assertOk();
        $this->putJson("/api/v1/common/users/{$staff->id}", $this->updatePayload($staff, ['phone_number' => '']))->assertOk();
        $this->assertNull($staff->fresh()->phone_number);

        $without = $this->updatePayload($staff);
        unset($without['phone_number']);
        $this->putJson("/api/v1/common/users/{$staff->id}", $without)->assertOk();
    }

    #[Test]
    public function a_password_is_only_changed_when_asked_to(): void
    {
        $staff = $this->makeStaffUser($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson("/api/v1/common/users/{$staff->id}", $this->updatePayload($staff, ['new_password' => 'Ignored123']))->assertOk();
        $this->assertTrue(Hash::check('password', $staff->fresh()->password));

        $this->putJson("/api/v1/common/users/{$staff->id}", $this->updatePayload($staff, [
            'change_password' => true,
            'new_password' => 'Changed123',
            'confirm_password' => 'Changed123',
        ]))->assertOk();
        $this->assertTrue(Hash::check('Changed123', $staff->fresh()->password));
    }

    #[Test]
    public function deactivating_a_user_signs_them_out_everywhere(): void
    {
        $this->createPersonalAccessClient();
        $staff = $this->makeStaffUser($this->partner);
        $staff->createToken('device');
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson("/api/v1/common/users/{$staff->id}", $this->updatePayload($staff, ['status' => User::STATUS['inactive']]))
            ->assertOk();

        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $staff->id)->where('revoked', false)->count());
    }

    /**
     * Defect: show and update bound the user straight from the URL, so a
     * partner - or a borrower, since /common does not turn borrowers away -
     * could read any account and set its password, then sign in as it.
     */
    #[Test]
    public function another_partners_user_cannot_be_read_or_changed(): void
    {
        $hostAdmin = $this->makePartnerAdmin($this->host);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/users/{$hostAdmin->id}")->assertNotFound();
        $this->putJson("/api/v1/common/users/{$hostAdmin->id}", $this->updatePayload($hostAdmin, [
            'change_password' => true,
            'new_password' => 'Owned1234',
            'confirm_password' => 'Owned1234',
        ]))->assertNotFound();

        $this->assertTrue(Hash::check('password', $hostAdmin->fresh()->password));
    }

    #[Test]
    public function a_borrower_cannot_change_their_partners_team(): void
    {
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->getJson("/api/v1/common/users/{$this->partnerAdmin->id}")->assertForbidden();
        $this->putJson("/api/v1/common/users/{$this->partnerAdmin->id}", $this->updatePayload($this->partnerAdmin, [
            'change_password' => true,
            'new_password' => 'Owned1234',
            'confirm_password' => 'Owned1234',
        ]))->assertForbidden();

        $this->assertTrue(Hash::check('password', $this->partnerAdmin->fresh()->password));
        // and behind the gate, the scope alone already gives a borrower no one
        $this->assertSame(0, User::manageableBy($this->makeBorrowerUser($this->partner))->count());
    }

    #[Test]
    public function a_borrowers_login_is_not_managed_from_the_users_screen(): void
    {
        $borrowerUser = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/users/{$borrowerUser->id}")->assertNotFound();
    }

    #[Test]
    public function deleting_and_the_deletable_check_reach_only_my_team(): void
    {
        $mine = $this->makeStaffUser($this->partner);
        $theirs = $this->makeStaffUser($this->host);
        $this->actingAsUser($this->partnerAdmin);

        $checked = collect($this->postJson('/api/v1/common/users/check-deletable', ['ids' => [$mine->id, $theirs->id]])
            ->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$mine->id], $checked->all());

        $this->postJson('/api/v1/common/users/multiple-delete', ['ids' => [$mine->id, $theirs->id]])->assertOk();

        $this->assertSoftDeleted($mine);
        $this->assertNotSoftDeleted($theirs);
    }

    #[Test]
    public function a_developer_account_is_hidden_from_everyone_but_developers(): void
    {
        $developerRole = Role::create(['name' => 'Developer', 'guard_name' => 'api', 'partner_id' => $this->partner->id, 'code' => 'developer']);
        $developer = $this->makeStaffUser($this->partner);
        $developer->assignRole($developerRole);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/users/{$developer->id}")->assertNotFound();
        $ids = collect($this->postJson('/api/v1/common/users/fetch-users')->json('data'))->pluck('id');
        $this->assertNotContains($developer->id, $ids->all());
    }

    #[Test]
    public function a_new_user_needs_a_name_email_password_role_and_employee(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/users', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'role_id', 'employee_id']);
    }
}
