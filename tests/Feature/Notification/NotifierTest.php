<?php

namespace Tests\Feature\Notification;

use App\Models\Borrower\Borrower;
use App\Models\Common\NotificationType;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\FCH\Common\Notification\Notifier;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;
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
}
