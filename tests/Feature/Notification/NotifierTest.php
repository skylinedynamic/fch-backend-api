<?php

namespace Tests\Feature\Notification;

use App\Mail\Contracts\SendsImmediately;
use App\Models\Borrower\Borrower;
use App\Models\Common\NotificationType;
use App\Models\Partner\Partner;
use App\Models\Setting\SmsConfiguration;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\FCH\Common\Notification\Notifier;
use App\Services\FCH\Common\Notification\RecipientResolver;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Notifier is the single entry point every module uses to notify users:
 * a type key + recipients + placeholder data.
 */
class NotifierTest extends TestCase
{
    use RefreshDatabase;

    private int $partnerId;
    private int $borrowerId;

    protected function setUp(): void
    {
        parent::setUp();

        // Type keys contain dots, so register it as a flat key exactly like
        // config/notifications.php does (a dotted config()->set would nest it).
        config()->set('notifications.types', config('notifications.types') + ['test.funding' => [
            'name' => 'Test funding',
            'category' => 'funding',
            'icon' => 'ri-hand-coin-line',
            'channels' => ['database', 'broadcast'],
            'title' => 'Funding request {{status}}',
            'body' => 'Request #{{id}} is now {{status}}.',
            'url' => '/funding-requests/{{id}}',
        ]]);

        $this->partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            // White-label: texts its users from its own SMS account.
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'email' => 'partner-' . uniqid() . '@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->borrowerId = DB::table('borrowers')->insertGetId([
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'email' => 'borrower-' . uniqid() . '@example.com',
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(string $entityType, int $entityId, string $userType): User
    {
        return User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'User ' . uniqid(),
            'email' => 'user-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => $userType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    #[Test]
    public function it_sends_a_rendered_notification_to_a_user(): void
    {
        Notification::fake();
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);

        $sent = app(Notifier::class)->send('test.funding', $user, ['id' => 7, 'status' => 'Approved']);

        $this->assertSame(1, $sent);
        Notification::assertSentTo($user, DynamicNotification::class, function (DynamicNotification $n, array $channels) use ($user) {
            return $channels === ['database', 'broadcast']
                && $n->typeKey === 'test.funding'
                && $n->toArray($user) === [
                    'title' => 'Funding request Approved',
                    'body' => 'Request #7 is now Approved.',
                    'url' => '/funding-requests/7',
                    'icon' => 'ri-hand-coin-line',
                    'category' => 'funding',
                ];
        });
    }

    #[Test]
    public function an_entity_resolves_to_every_login_user_of_that_entity(): void
    {
        Notification::fake();
        $first = $this->makeUser(Partner::class, $this->partnerId, User::PARTNER);
        $second = $this->makeUser(Partner::class, $this->partnerId, User::PARTNER);
        $borrowerUser = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);

        $sent = app(Notifier::class)->send('test.funding', Partner::findOrFail($this->partnerId), ['id' => 1, 'status' => 'x']);

        $this->assertSame(2, $sent);
        Notification::assertSentTo([$first, $second], DynamicNotification::class);
        Notification::assertNotSentTo($borrowerUser, DynamicNotification::class);
    }

    #[Test]
    public function a_user_listed_twice_is_notified_once(): void
    {
        Notification::fake();
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);

        $sent = app(Notifier::class)->send('test.funding', [$user, Borrower::findOrFail($this->borrowerId), null], ['id' => 1, 'status' => 'x']);

        $this->assertSame(1, $sent);
        Notification::assertSentToTimes($user, DynamicNotification::class, 1);
    }

    #[Test]
    public function an_unknown_type_is_skipped_without_breaking_the_caller(): void
    {
        Notification::fake();
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);

        $this->assertSame(0, app(Notifier::class)->send('does.not.exist', $user));
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_database_override_can_change_templates_and_disable_a_type(): void
    {
        Notification::fake();
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);

        NotificationType::create(['key' => 'test.funding', 'title_template' => 'Update: {{status}}']);
        app(Notifier::class)->send('test.funding', $user, ['id' => 1, 'status' => 'Approved']);
        Notification::assertSentTo($user, DynamicNotification::class, fn ($n) => $n->toArray($user)['title'] === 'Update: Approved'
            && $n->toArray($user)['body'] === 'Request #1 is now Approved.');

        NotificationType::where('key', 'test.funding')->update(['is_active' => false]);
        NotificationType::first()->save(); // model event clears the registry cache
        $this->assertSame(0, app(Notifier::class)->send('test.funding', $user, ['id' => 1, 'status' => 'x']));
    }

    #[Test]
    public function the_notification_is_stored_with_its_type_key_and_broadcast_on_the_users_private_channel(): void
    {
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);

        app(Notifier::class)->send('test.funding', $user, ['id' => 7, 'status' => 'Approved']);

        $stored = $user->notifications()->first();
        $this->assertSame('test.funding', $stored->type);
        $this->assertSame('Funding request Approved', $stored->data['title']);

        $notification = new DynamicNotification('test.funding', app(\App\Services\FCH\Common\Notification\TemplateRenderer::class)
            ->render(app(\App\Services\FCH\Common\Notification\NotificationTypeRegistry::class)->find('test.funding'), ['id' => 7, 'status' => 'Approved']), ['broadcast']);
        $notification->id = 'abc';
        $event = new BroadcastNotificationCreated($user, $notification, $notification->toBroadcast($user)->data);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-App.Models.User.' . $user->id, $channels[0]->name);

        $payload = $event->broadcastWith();
        $this->assertSame('abc', $payload['id']);
        $this->assertSame('test.funding', $payload['type']);
        $this->assertSame('Funding request Approved', $payload['title']);
        $this->assertNull($payload['read_at']);
        $this->assertArrayHasKey('created_at', $payload);
    }

    // ── Mail ───────────────────────────────────────────────────────────
    //
    // A type's email is the Mailable the caller hands over, addressed and
    // branded exactly as before - recipients via ->to(), sending mailbox via
    // the Mailable's own MailConfig. The Notifier decides only whether it goes
    // out (the type's channels) and how (queued or inline, per the Mailable).

    private function registerType(string $key, array $channels, bool $active = true): void
    {
        config()->set('notifications.types', config('notifications.types') + [$key => [
            'name' => $key,
            'category' => 'system',
            'channels' => $channels,
            'title' => 'Title {{id}}',
            'body' => 'Body {{id}}',
            'url' => null,
            'is_active' => $active,
        ]]);
    }

    #[Test]
    public function the_types_mail_goes_to_the_mailables_own_recipients_alongside_the_bell(): void
    {
        Mail::fake();
        Notification::fake();
        $this->registerType('test.mail', ['database', 'broadcast', 'mail']);
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);

        $sent = app(Notifier::class)->send('test.mail', $user, ['id' => 1],
            mail: (new NotifierTestQueuedMail())->to('someone-else@example.com'));

        $this->assertSame(1, $sent);
        Notification::assertSentTo($user, DynamicNotification::class);
        Mail::assertQueued(NotifierTestQueuedMail::class, fn ($mail) => $mail->hasTo('someone-else@example.com')
            && !$mail->hasTo($user->email));
    }

    #[Test]
    public function a_queued_mail_waits_for_the_surrounding_transaction_to_commit(): void
    {
        // Several callers send from inside a transaction (lead capture, payouts).
        // Queued before the commit, a worker could pick the job up before the
        // row it describes exists - or after it was rolled back.
        Mail::fake();
        $this->registerType('test.mail', ['mail']);

        app(Notifier::class)->send('test.mail', null, [], mail: (new NotifierTestQueuedMail())->to('a@example.com'));

        $this->assertTrue(Mail::queued(NotifierTestQueuedMail::class)->first()->afterCommit);
    }

    #[Test]
    public function an_immediate_mail_is_sent_inline_rather_than_queued(): void
    {
        Mail::fake();
        $this->registerType('test.mail', ['mail']);

        app(Notifier::class)->send('test.mail', null, [], mail: (new NotifierTestImmediateMail())->to('a@example.com'));

        Mail::assertSent(NotifierTestImmediateMail::class, fn ($mail) => $mail->hasTo('a@example.com'));
        Mail::assertNothingQueued();
    }

    #[Test]
    public function a_mail_only_type_needs_no_one_to_notify_in_app(): void
    {
        // Operational alerts and messages to outside addresses (a prospect, the
        // documents inbox) have no login behind them.
        Mail::fake();
        Notification::fake();
        $this->registerType('test.mail', ['mail']);

        $sent = app(Notifier::class)->send('test.mail', null, [], mail: (new NotifierTestQueuedMail())->to('ops@example.com'));

        $this->assertSame(0, $sent);
        Mail::assertQueued(NotifierTestQueuedMail::class);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_type_without_the_mail_channel_does_not_send_its_mail(): void
    {
        Mail::fake();
        Notification::fake();
        $this->registerType('test.mail', ['database', 'broadcast']);
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);

        app(Notifier::class)->send('test.mail', $user, [], mail: (new NotifierTestQueuedMail())->to('a@example.com'));

        Mail::assertNothingOutgoing();
        Notification::assertSentTo($user, DynamicNotification::class);
    }

    #[Test]
    public function a_database_override_can_switch_a_types_mail_off(): void
    {
        Mail::fake();
        $this->registerType('test.mail', ['database', 'mail']);

        NotificationType::create(['key' => 'test.mail', 'channels' => ['database']]);
        app(Notifier::class)->send('test.mail', null, [], mail: (new NotifierTestQueuedMail())->to('a@example.com'));
        Mail::assertNothingOutgoing();

        NotificationType::where('key', 'test.mail')->first()->update(['channels' => null, 'is_active' => false]);
        app(Notifier::class)->send('test.mail', null, [], mail: (new NotifierTestQueuedMail())->to('a@example.com'));
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function an_unknown_type_still_delivers_its_mail(): void
    {
        // A mistyped key costs the bell, which is logged - never the email
        // that went out before the Notifier existed.
        Mail::fake();
        Notification::fake();
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);

        $sent = app(Notifier::class)->send('does.not.exist', $user, [], mail: (new NotifierTestQueuedMail())->to('a@example.com'));

        $this->assertSame(0, $sent);
        Mail::assertQueued(NotifierTestQueuedMail::class);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_failing_bell_does_not_stop_the_mail(): void
    {
        Mail::fake();
        $this->registerType('test.mail', ['database', 'mail']);
        $this->app->instance(RecipientResolver::class, new class extends RecipientResolver {
            public function resolve(mixed $recipients): \Illuminate\Support\Collection
            {
                throw new \RuntimeException('simulated lookup failure');
            }
        });

        $sent = app(Notifier::class)->send('test.mail', Borrower::findOrFail($this->borrowerId), [],
            mail: (new NotifierTestQueuedMail())->to('a@example.com'));

        $this->assertSame(0, $sent);
        Mail::assertQueued(NotifierTestQueuedMail::class);
    }

    #[Test]
    public function an_sms_type_texts_the_borrowers_phone_from_the_partners_twilio_account(): void
    {
        $this->enableSmsFor($this->partnerId, true);
        $this->registerType('test.sms', ['database', 'sms']);
        DB::table('borrowers')->where('id', $this->borrowerId)->update(['phone_number' => '(546) 456-5465', 'country_code' => '+1']);
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);
        $user->forceFill(['phone_number' => '+15551234567'])->save();

        app(Notifier::class)->send('test.sms', $user, ['id' => 9]);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Accounts/AC-partner/')
            && $request['To'] === '+15464565465'
            && $request['Body'] === 'Title 9: Body 9');
        $this->assertSame(1, $user->notifications()->count());
    }

    #[Test]
    public function no_sms_goes_out_while_the_partners_sms_configuration_is_disabled(): void
    {
        $this->enableSmsFor($this->partnerId, false);
        $this->registerType('test.sms', ['database', 'sms']);
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);
        $user->forceFill(['phone_number' => '+15551234567', 'phone_verified_at' => now()])->save();

        app(Notifier::class)->send('test.sms', $user, ['id' => 9]);

        Http::assertNothingSent();
        $this->assertSame(1, $user->notifications()->count());
    }

    #[Test]
    public function the_stored_number_is_normalised_and_need_not_be_verified(): void
    {
        $this->enableSmsFor($this->partnerId, true);
        $this->registerType('test.sms', ['database', 'sms']);
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);
        $user->forceFill(['phone_number' => '(555) 123-4567', 'phone_verified_at' => null])->save();

        app(Notifier::class)->send('test.sms', $user, ['id' => 9]);

        Http::assertSent(fn ($request) => $request['To'] === '+15551234567');
    }

    #[Test]
    public function a_rejected_sms_is_logged_and_everything_else_carries_on(): void
    {
        SmsConfiguration::create([
            'partner_id' => $this->partnerId,
            'country' => 'USA',
            'provider' => 'twilio',
            'is_enabled' => true,
            'from_number' => '+15005550006',
            'credentials' => ['account_sid' => 'AC-partner', 'auth_token' => 'wrong'],
        ]);
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'Authenticate'], 401)]);
        $this->registerType('test.sms', ['database', 'sms']);
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);
        $user->forceFill(['phone_number' => '5551234567'])->save();

        $sent = app(Notifier::class)->send('test.sms', $user, ['id' => 9]);

        $this->assertSame(1, $sent);
        $this->assertSame(1, $user->notifications()->count());
    }

    #[Test]
    public function a_type_without_the_sms_channel_never_texts(): void
    {
        $this->enableSmsFor($this->partnerId, true);
        $this->registerType('test.bell', ['database']);
        $user = $this->makeUser(Borrower::class, $this->borrowerId, User::BORROWER);
        $user->forceFill(['phone_number' => '+15551234567', 'phone_verified_at' => now()])->save();

        app(Notifier::class)->send('test.bell', $user, ['id' => 9]);

        Http::assertNothingSent();
    }

    private function enableSmsFor(int $partnerId, bool $enabled): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        SmsConfiguration::create([
            'partner_id' => $partnerId,
            'country' => 'USA',
            'provider' => 'twilio',
            'is_enabled' => $enabled,
            'from_number' => '+15005550006',
            'credentials' => ['account_sid' => 'AC-partner', 'auth_token' => 'tok'],
        ]);
    }

    #[Test]
    public function a_mail_with_no_recipient_is_dropped_rather_than_thrown(): void
    {
        Mail::fake();
        $this->registerType('test.mail', ['mail']);

        app(Notifier::class)->send('test.mail', null, [], mail: new NotifierTestQueuedMail());

        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function a_mail_failure_reaches_the_caller_exactly_as_a_direct_send_would(): void
    {
        // Every caller already wraps its send in its own try/catch with its own
        // log line, and some act on the outcome. The Notifier keeps that
        // contract rather than silently changing what those callers see.
        $this->registerType('test.mail', ['mail']);
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('simulated transport failure'));

        $this->expectExceptionMessage('simulated transport failure');

        app(Notifier::class)->send('test.mail', null, [], mail: (new NotifierTestImmediateMail())->to('a@example.com'));
    }
}

class NotifierTestQueuedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function build()
    {
        return $this->subject('queued')->html('<p>queued</p>');
    }
}

class NotifierTestImmediateMail extends Mailable implements SendsImmediately
{
    use Queueable;

    public function build()
    {
        return $this->subject('immediate')->html('<p>immediate</p>');
    }
}
