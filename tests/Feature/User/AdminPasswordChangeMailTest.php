<?php

namespace Tests\Feature\User;

use App\Models\Common\Role;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\Global\Notification\Mail\UserCreateNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-200: Users > Change Account Password sent the new-account welcome
 * ("Your account has been successfully created ... Temporary Password") under
 * the subject "Your Password has been updated", and the bell said "Welcome
 * aboard". The new password stays in the email (06-Nov decision); the email
 * and the bell now say the password was changed by an administrator.
 */
class AdminPasswordChangeMailTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $admin;
    private User $staff;
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->admin = $this->makePartnerAdmin($this->partner);
        $this->staff = $this->makeStaffUser($this->partner, [], ['name' => 'Host Staff Two']);
        $this->role = Role::create(['name' => 'Sales', 'guard_name' => 'api', 'partner_id' => $this->partner->id, 'code' => 'sales']);
    }

    private function changePassword(string $password): void
    {
        $this->actingAsUser($this->admin);
        $this->putJson("/api/v1/common/users/{$this->staff->id}", [
            'name' => $this->staff->name,
            'email' => $this->staff->email,
            'role_id' => $this->role->id,
            'status' => User::STATUS['active'],
            'change_password' => true,
            'new_password' => $password,
            'confirm_password' => $password,
        ])->assertOk();
    }

    private function sentMail(): UserCreateNotificationMail
    {
        $mails = Mail::sent(UserCreateNotificationMail::class)->merge(Mail::queued(UserCreateNotificationMail::class))
            ->filter(fn ($mail) => $mail->hasTo($this->staff->email))->values();
        $this->assertCount(1, $mails, 'one email to the user');

        return $mails->first();
    }

    #[Test]
    public function the_email_says_the_password_was_changed_and_carries_the_new_one(): void
    {
        $this->changePassword('Changed#2026');

        $mail = $this->sentMail();
        $html = $mail->render();

        $this->assertSame(__('common.password_updated_subject'), $mail->envelope()->subject);
        $this->assertStringContainsString('Changed#2026', $html, 'the new password stays in the email');
        $this->assertStringContainsString('password for your account was changed by an administrator', $html);
        $this->assertStringContainsString('New Password', $html);
        $this->assertStringNotContainsString('successfully created', $html);
        $this->assertStringNotContainsString('Temporary Password', $html);
        $this->assertStringNotContainsString('Welcome to', $html);
    }

    #[Test]
    public function the_bell_says_the_sign_in_details_changed_not_welcome(): void
    {
        $this->changePassword('Changed#2026');

        Notification::assertSentTo($this->staff, DynamicNotification::class, fn ($n) => $n->typeKey === 'user.account.login_updated');
        Notification::assertNotSentTo($this->staff, DynamicNotification::class, fn ($n) => $n->typeKey === 'user.account.created');
    }

    #[Test]
    public function a_new_user_still_gets_the_welcome_with_a_temporary_password(): void
    {
        $employee = \App\Models\Employee\Employee::create([
            'partner_id' => $this->partner->id, 'first_name' => 'Brand', 'last_name' => 'New', 'email' => 'brand.new@example.test',
        ]);
        $this->actingAsUser($this->admin);
        $this->postJson('/api/v1/common/users', [
            'name' => 'Brand New',
            'email' => 'brand.new@example.test',
            'password' => 'Welcome#2026',
            'role_id' => $this->role->id,
            'employee_id' => $employee->id,
        ])->assertSuccessful();

        $mail = Mail::sent(UserCreateNotificationMail::class)->merge(Mail::queued(UserCreateNotificationMail::class))
            ->first(fn ($m) => $m->hasTo('brand.new@example.test'));
        $this->assertNotNull($mail);
        $html = $mail->render();
        $this->assertStringContainsString('successfully created', $html);
        $this->assertStringContainsString('Temporary Password', $html);
    }
}
