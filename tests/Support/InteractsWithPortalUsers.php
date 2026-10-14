<?php

namespace Tests\Support;

use App\Models\Borrower\Borrower;
use App\Models\Common\Permission;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * One way to build each kind of account the portals serve, so an endpoint test
 * says which user type it is about rather than re-deriving the columns that
 * make one:
 *
 *   host partner admin   user_type Admin,   entity Partner (is_host)
 *   partner admin        user_type Partner, entity Partner (white-label / certified)
 *   staff user           user_type Admin (host) / Partner (others), entity Employee
 *   borrower             user_type Borrower, entity Borrower, under a partner
 *
 * The admin routes (`admin` middleware) only accept user_type Admin, so a
 * white-label or certified partner admin is refused there; host-only screens
 * additionally check the partner's is_host flag.
 */
trait InteractsWithPortalUsers
{
    protected function makeHostPartner(array $attributes = []): Partner
    {
        return $this->makePartner(array_merge(['is_host' => true, 'company_name' => 'Host Co'], $attributes));
    }

    protected function makeWhiteLabelPartner(array $attributes = []): Partner
    {
        return $this->makePartner(array_merge(['partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL], $attributes));
    }

    protected function makeCertifiedPartner(array $attributes = []): Partner
    {
        return $this->makePartner(array_merge(['partner_type' => Partner::PARTNER_TYPE_CERTIFIED], $attributes));
    }

    protected function makePartner(array $attributes = []): Partner
    {
        return Partner::create(array_merge([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Partner Co ' . uniqid(),
            'full_name' => 'Partner Owner',
            'email' => 'partner-' . uniqid() . '@example.test',
        ], $attributes));
    }

    /** The partner's own login: Admin for the host, Partner for everyone else. */
    protected function makePartnerAdmin(Partner $partner, array $permissions = [], array $attributes = []): User
    {
        $user = $this->makeUser(array_merge([
            'user_type' => $partner->is_host ? User::ADMIN : User::PARTNER,
            'partner_id' => $partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
        ], $attributes));

        return $this->grant($user, $permissions);
    }

    /**
     * An employee's login under a partner, holding only the given permissions.
     * Like UserService/EmployeeService, it takes its creator's user_type: Admin
     * under the host, Partner under anyone else.
     */
    protected function makeStaffUser(Partner $partner, array $permissions = [], array $attributes = []): User
    {
        $employee = Employee::create([
            'partner_id' => $partner->id,
            'first_name' => 'Staff',
            'last_name' => 'Member',
            'email' => 'staff-' . uniqid() . '@example.test',
        ]);

        $user = $this->makeUser(array_merge([
            'user_type' => $partner->is_host ? User::ADMIN : User::PARTNER,
            'partner_id' => $partner->id,
            'entity_type' => Employee::class,
            'entity_id' => $employee->id,
        ], $attributes));

        return $this->grant($user, $permissions);
    }

    protected function makeBorrower(Partner $partner, array $attributes = []): Borrower
    {
        return Borrower::create(array_merge([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ], $attributes));
    }

    /** A borrower and the login attached to it. */
    protected function makeBorrowerUser(Partner $partner, array $borrowerAttributes = [], array $userAttributes = []): User
    {
        $borrower = $this->makeBorrower($partner, $borrowerAttributes);

        return $this->makeUser(array_merge([
            'name' => $borrower->first_name . ' ' . $borrower->last_name,
            'email' => $borrower->email,
            'user_type' => User::BORROWER,
            'partner_id' => $partner->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
        ], $userAttributes));
    }

    protected function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => 'user-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => User::STATUS['active'],
            'user_type' => User::ADMIN,
            'email_verified_at' => now(),
        ], $attributes));
    }

    /** Gives the user these api-guard permissions, creating any that do not exist yet. */
    protected function grant(User $user, array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api'], ['module' => 'tests']);
        }

        if ($permissions) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }

    protected function actingAsUser(User $user): User
    {
        Passport::actingAs($user);

        return $user;
    }

    /** Real logins mint a personal access token, which needs this client to exist. */
    protected function createPersonalAccessClient(): void
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test Personal Access Client', 'users');
    }
}
