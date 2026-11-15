<?php

namespace Tests\Feature\EmailChange;

use App\Mail\Account\EmailChangeConfirmationMail;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Borrower\BorrowerService;
use App\Services\FCH\Common\ChangeRequest\ChangeRequestService;
use App\Services\FCH\Common\Employee\EmployeeService;
use App\Services\FCH\Common\User\UserService;
use App\Services\FCH\Partner\PartnerService;
use App\Services\Global\Account\EmailChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The edit screens for partners, clients, employees and users all used to
 * switch the sign-in email the moment the form was saved, reset the password
 * and email the new one in plain text. Each now hands the change to
 * EmailChangeService and leaves the email alone until it's confirmed.
 */
#[CoversClass(EmailChangeService::class)]
class EmailChangeThroughEditFormsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        Passport::actingAs($this->login('admin@example.test', ['user_type' => User::ADMIN]));
    }

    private function login(string $email, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Pat Doe',
            'email' => $email,
            'password' => Hash::make('secret-password'),
            'status' => User::STATUS['active'],
            'user_type' => User::ADMIN,
            'partner_id' => $this->host->id,
        ], $attributes));
    }

    // ── Users ─────────────────────────────────────────────────────────────

    #[Test]
    public function editing_a_users_email_holds_it_pending_until_confirmed(): void
    {
        $user = $this->login('old@example.test');

        app(UserService::class)->update($user, ['email' => 'new@example.test']);

        $user->refresh();
        $this->assertSame('old@example.test', $user->email);
        $this->assertSame('new@example.test', $user->pending_email);
        Mail::assertSent(EmailChangeConfirmationMail::class, fn ($m) => $m->hasTo('new@example.test'));
    }

    #[Test]
    public function editing_a_users_email_no_longer_resets_their_password(): void
    {
        $user = $this->login('old@example.test');

        app(UserService::class)->update($user, ['email' => 'new@example.test']);

        $this->assertTrue(Hash::check('secret-password', $user->refresh()->password));
    }

    #[Test]
    public function saving_a_user_without_touching_the_email_keeps_a_pending_change(): void
    {
        // Every save of the form resubmits the email it shows - the pending one
        // while a change waits. That mustn't silently cancel the change.
        $user = $this->login('old@example.test');
        app(UserService::class)->update($user, ['email' => 'new@example.test']);

        app(UserService::class)->update($user->refresh(), ['email' => 'new@example.test', 'name' => 'Pat Renamed']);

        $user->refresh();
        $this->assertSame('Pat Renamed', $user->name);
        $this->assertSame('new@example.test', $user->pending_email);
    }

    #[Test]
    public function setting_a_users_email_back_to_the_current_one_withdraws_a_pending_change(): void
    {
        $user = $this->login('old@example.test');
        app(UserService::class)->update($user, ['email' => 'new@example.test']);

        app(UserService::class)->update($user->refresh(), ['email' => 'OLD@example.test', 'name' => 'Pat Renamed']);

        $user->refresh();
        $this->assertSame('Pat Renamed', $user->name);
        $this->assertSame('old@example.test', $user->email);
        $this->assertNull($user->pending_email);
    }

    #[Test]
    public function setting_an_employees_email_back_withdraws_a_pending_change(): void
    {
        $employee = Employee::create(['partner_id' => $this->host->id, 'first_name' => 'Pat Doe', 'email' => 'old@example.test']);
        $user = $this->login('old@example.test', ['entity_type' => Employee::class, 'entity_id' => $employee->id, 'user_type' => User::EMPLOYEE]);
        $service = app(EmployeeService::class);
        $service->updateWithUserSync($employee, ['email' => 'new@example.test'], false, null, $this->host);

        $service->updateWithUserSync($employee->refresh(), ['email' => 'new@example.test'], false, null, $this->host);
        $this->assertSame('new@example.test', $user->refresh()->pending_email, 'resaving the pending email keeps it');

        $service->updateWithUserSync($employee->refresh(), ['email' => 'old@example.test'], false, null, $this->host);
        $this->assertSame('old@example.test', $user->refresh()->email);
        $this->assertNull($user->pending_email);
    }

    #[Test]
    public function setting_a_partners_email_back_withdraws_a_pending_change(): void
    {
        $partner = Partner::create([
            'status' => Partner::STATUS_ACTIVE, 'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Acme LLC', 'full_name' => 'Acme Owner', 'email' => 'old@example.test',
        ]);
        $user = $this->login('old@example.test', [
            'partner_id' => $partner->id, 'entity_type' => Partner::class, 'entity_id' => $partner->id, 'user_type' => User::PARTNER,
        ]);
        $service = app(PartnerService::class);
        $service->update($partner, ['email' => 'new@example.test']);

        $service->update($partner->refresh(), ['email' => 'new@example.test', 'full_name' => 'Acme Renamed']);
        $this->assertSame('new@example.test', $user->refresh()->pending_email, 'resaving the pending email keeps it');
        $this->assertSame('old@example.test', $partner->refresh()->email);

        $service->update($partner->refresh(), ['email' => 'old@example.test']);
        $this->assertSame('old@example.test', $user->refresh()->email);
        $this->assertSame('old@example.test', $partner->refresh()->email);
        $this->assertNull($user->pending_email);
    }

    #[Test]
    public function setting_a_clients_email_back_withdraws_a_pending_change(): void
    {
        $borrower = Borrower::create([
            'partner_id' => $this->host->id, 'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Pat', 'last_name' => 'Doe', 'email' => 'old@example.test',
        ]);
        $user = $this->login('old@example.test', [
            'entity_type' => Borrower::class, 'entity_id' => $borrower->id, 'user_type' => User::BORROWER,
        ]);
        $service = app(BorrowerService::class);
        $service->update($borrower, ['email' => 'new@example.test']);

        $service->update($borrower->refresh(), ['email' => 'new@example.test', 'first_name' => 'Patricia']);
        $this->assertSame('new@example.test', $user->refresh()->pending_email, 'resaving the pending email keeps it');
        $this->assertSame('old@example.test', $borrower->refresh()->email);

        $service->update($borrower->refresh(), ['email' => 'old@example.test']);
        $this->assertSame('old@example.test', $user->refresh()->email);
        $this->assertSame('old@example.test', $borrower->refresh()->email);
        $this->assertNull($user->pending_email);
    }

    // ── Employees ─────────────────────────────────────────────────────────

    #[Test]
    public function editing_an_employees_email_holds_it_pending_on_their_login(): void
    {
        $employee = Employee::create(['partner_id' => $this->host->id, 'first_name' => 'Pat Doe', 'email' => 'old@example.test']);
        $user = $this->login('old@example.test', ['entity_type' => Employee::class, 'entity_id' => $employee->id, 'user_type' => User::EMPLOYEE]);

        app(EmployeeService::class)->updateWithUserSync($employee, ['email' => 'new@example.test'], false, null, $this->host);

        $this->assertSame('old@example.test', $employee->refresh()->email);
        $this->assertSame('old@example.test', $user->refresh()->email);
        $this->assertSame('new@example.test', $user->pending_email);
    }

    #[Test]
    public function an_employee_without_a_login_changes_email_straight_away(): void
    {
        // No sign-in to protect, so there's nothing to confirm.
        $employee = Employee::create(['partner_id' => $this->host->id, 'first_name' => 'Pat Doe', 'email' => 'old@example.test']);

        app(EmployeeService::class)->updateWithUserSync($employee, ['email' => 'new@example.test'], false, null, $this->host);

        $this->assertSame('new@example.test', $employee->refresh()->email);
        Mail::assertNotSent(EmailChangeConfirmationMail::class);
    }

    // ── Partners ──────────────────────────────────────────────────────────

    #[Test]
    public function editing_a_partners_email_holds_it_pending_on_their_login(): void
    {
        $partner = Partner::create([
            'status' => Partner::STATUS_ACTIVE, 'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Acme LLC', 'full_name' => 'Acme Owner', 'email' => 'old@example.test',
        ]);
        $user = $this->login('old@example.test', [
            'partner_id' => $partner->id, 'entity_type' => Partner::class, 'entity_id' => $partner->id, 'user_type' => User::PARTNER,
        ]);

        app(PartnerService::class)->update($partner, ['email' => 'new@example.test']);

        $this->assertSame('old@example.test', $partner->refresh()->email);
        $this->assertSame('old@example.test', $user->refresh()->email);
        $this->assertSame('new@example.test', $user->pending_email);
    }

    #[Test]
    public function a_partner_without_a_login_never_takes_over_another_account_with_that_email(): void
    {
        // Defect: with no login of its own, the partner edit looked one up by
        // the submitted email and overwrote whichever account it found.
        $partner = Partner::create([
            'status' => Partner::STATUS_ACTIVE, 'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Acme LLC', 'full_name' => 'Acme Owner', 'email' => 'acme@example.test',
        ]);
        $bystander = $this->login('bystander@example.test', ['name' => 'Someone Else', 'partner_id' => $partner->id]);

        app(PartnerService::class)->update($partner, ['email' => 'bystander@example.test', 'full_name' => 'Acme Renamed']);

        $bystander->refresh();
        $this->assertSame('Someone Else', $bystander->name);
        $this->assertTrue(Hash::check('secret-password', $bystander->password));
    }

    // ── Clients ───────────────────────────────────────────────────────────

    #[Test]
    public function editing_a_clients_email_holds_it_pending_on_their_login(): void
    {
        $borrower = Borrower::create([
            'partner_id' => $this->host->id, 'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Pat', 'last_name' => 'Doe', 'email' => 'old@example.test',
        ]);
        $user = $this->login('old@example.test', [
            'entity_type' => Borrower::class, 'entity_id' => $borrower->id, 'user_type' => User::BORROWER,
        ]);

        app(BorrowerService::class)->update($borrower, ['email' => 'new@example.test']);

        $this->assertSame('old@example.test', $borrower->refresh()->email);
        $this->assertSame('old@example.test', $user->refresh()->email);
        $this->assertSame('new@example.test', $user->pending_email);
    }

    // ── A client's own request, approved by an admin ─────────────────────

    private function clientEmailChangeRequest(string $newEmail): array
    {
        $borrower = Borrower::create([
            'partner_id' => $this->host->id, 'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Pat', 'last_name' => 'Doe', 'email' => 'old@example.test',
        ]);
        $user = $this->login('old@example.test', [
            'entity_type' => Borrower::class, 'entity_id' => $borrower->id, 'user_type' => User::BORROWER,
        ]);
        $request = BorrowerChangeRequest::create([
            'partner_id' => $this->host->id, 'borrower_id' => $borrower->id,
            'field_name' => 'email', 'field_type' => 'string', 'visible_field_name' => 'Email Address',
            'db_field_name' => 'email', 'entity_type' => Borrower::class, 'entity_id' => $borrower->id,
            'old_value' => 'old@example.test', 'new_value' => $newEmail,
            'status' => BorrowerChangeRequest::STATUS_PENDING,
        ]);

        return [$borrower, $user, $request];
    }

    #[Test]
    public function approving_a_clients_email_change_request_holds_it_pending_on_their_login(): void
    {
        // Defect: approval wrote borrowers.email and never the login, so the
        // client saw the new address but still had to sign in with the old one.
        [$borrower, $user, $request] = $this->clientEmailChangeRequest('new@example.test');

        app(ChangeRequestService::class)->updateStatus($request->id, ['status' => BorrowerChangeRequest::STATUS_APPROVED]);

        $this->assertSame('old@example.test', $borrower->refresh()->email);
        $this->assertSame('old@example.test', $user->refresh()->email);
        $this->assertSame('new@example.test', $user->pending_email);
    }

    #[Test]
    public function a_client_email_change_request_for_a_taken_email_cannot_be_approved(): void
    {
        [, $user, $request] = $this->clientEmailChangeRequest('taken@example.test');
        $this->login('taken@example.test');

        $response = app(ChangeRequestService::class)->updateStatus($request->id, ['status' => BorrowerChangeRequest::STATUS_APPROVED]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(BorrowerChangeRequest::STATUS_PENDING, $request->refresh()->status);
        $this->assertNull($user->refresh()->pending_email);
    }
}
