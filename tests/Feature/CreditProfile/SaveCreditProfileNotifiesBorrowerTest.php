<?php

namespace Tests\Feature\CreditProfile;

use App\Mail\Borrower\CreditProfileUpdatedMail;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\FCH\Common\Notification\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * When the host saves a client's iSoftPull credit report (Client -> Credit
 * Profile), the client is told: in their bell, pushed in realtime, and by email.
 *
 * Nothing told them before - they asked for their credit profile and only found
 * it by checking back. The notice is sent after the save has committed and can
 * never undo or fail it.
 */
class SaveCreditProfileNotifiesBorrowerTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;
    private User $borrowerLogin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $host->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'email' => 'michael@example.test',
        ]);

        $this->borrowerLogin = User::create([
            'name' => 'Michael Chen',
            'email' => 'michael@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::BORROWER,
            'partner_id' => $host->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]);

        Passport::actingAs(User::create([
            'name' => 'Host Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::ADMIN,
            'partner_id' => $host->id,
            'status' => User::STATUS['active'],
        ]), [], 'api');
    }

    private function save()
    {
        return $this->postJson('/api/v1/common/borrowers/save-credit-profile', [
            'borrower_id' => $this->borrower->id,
            'metrics' => ['fico_score' => 712, 'paydex_score' => 80],
            'personal_info' => ['name' => 'Michael Chen'],
        ]);
    }

    #[Test]
    public function the_borrower_is_emailed_that_their_credit_profile_was_updated(): void
    {
        $this->save()->assertOk();

        Mail::assertQueued(CreditProfileUpdatedMail::class, fn (CreditProfileUpdatedMail $mail) => $mail->hasTo('michael@example.test')
            && $mail->borrowerName === 'Michael Chen'
            && $mail->borrowerPartnerId === $this->borrower->partner_id);
    }

    #[Test]
    public function the_email_links_to_the_borrowers_credit_profile_tab(): void
    {
        $html = (new CreditProfileUpdatedMail('Michael Chen', $this->borrower->partner_id))->render();

        $this->assertStringContainsString('Michael Chen', $html);
        $this->assertStringContainsString('profile?tab=3', $html);
        $this->assertStringContainsString(__('credit_profile_request.updated_mail_action'), $html);
    }

    #[Test]
    public function the_borrower_is_told_in_their_bell_and_by_realtime_push(): void
    {
        Notification::fake();

        $this->save()->assertOk();

        Notification::assertSentTo($this->borrowerLogin, DynamicNotification::class, function (DynamicNotification $n) {
            $payload = $n->toArray($this->borrowerLogin);

            return $n->typeKey === 'borrower.credit_profile.updated'
                && in_array('database', $n->via($this->borrowerLogin), true)
                && in_array('broadcast', $n->via($this->borrowerLogin), true)
                && $payload['title'] === 'Your credit profile has been updated'
                && $payload['url'] === '/profile?tab=3';
        });
    }

    #[Test]
    public function a_failing_notification_does_not_fail_the_save(): void
    {
        $this->mock(Notifier::class)->shouldReceive('send')->andThrow(new \RuntimeException('mail server down'));

        $this->save()->assertOk();

        $this->assertSame(712, (int) $this->borrower->fresh()->fico_score);
    }

    #[Test]
    public function nothing_is_sent_when_the_save_is_refused(): void
    {
        $this->postJson('/api/v1/common/borrowers/save-credit-profile', ['borrower_id' => 999999])->assertStatus(422);

        Mail::assertNothingQueued();
    }
}
