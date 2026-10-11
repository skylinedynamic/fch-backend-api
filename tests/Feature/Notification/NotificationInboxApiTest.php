<?php

namespace Tests\Feature\Notification;

use App\Events\NotificationsReadEvent;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The bell + notifications page API shared by the borrower and partner portals.
 */
class NotificationInboxApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/common/notifications';

    private User $user;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner-' . uniqid() . '@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = $this->makeUser($partnerId);
        $this->otherUser = $this->makeUser($partnerId);

        Passport::actingAs($this->user, [], 'api');
    }

    private function makeUser(int $partnerId): User
    {
        return User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'User ' . uniqid(),
            'email' => 'user-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'entity_type' => Partner::class,
            'entity_id' => $partnerId,
            'partner_id' => $partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function addNotification(User $user, array $attributes = []): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(array_merge([
            'id' => $id,
            'type' => 'partner.funding_request.status_changed',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'title' => 'Funding request Approved',
                'body' => 'Request #1 is now Approved.',
                'url' => '/intake-review/funding-request/1',
                'icon' => 'ri-hand-coin-line',
                'category' => 'funding',
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return $id;
    }

    #[Test]
    public function it_lists_only_the_current_users_notifications_newest_first_with_the_unread_count(): void
    {
        $old = $this->addNotification($this->user, ['created_at' => now()->subDay()]);
        $new = $this->addNotification($this->user);
        $read = $this->addNotification($this->user, ['read_at' => now(), 'created_at' => now()->subDays(2)]);
        $this->addNotification($this->otherUser);

        $response = $this->getJson(self::BASE . '?per_page=10')->assertOk();

        $this->assertSame([$new, $old, $read], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.unread_count', 2)
            ->assertJsonPath('data.0.title', 'Funding request Approved')
            ->assertJsonPath('data.0.body', 'Request #1 is now Approved.')
            ->assertJsonPath('data.0.url', '/intake-review/funding-request/1')
            ->assertJsonPath('data.0.icon', 'ri-hand-coin-line')
            ->assertJsonPath('data.0.category', 'funding')
            ->assertJsonPath('data.0.type', 'partner.funding_request.status_changed')
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('data.0.read_at', null);
        $this->assertNotEmpty($response->json('data.0.created_at'));
    }

    #[Test]
    public function it_can_filter_to_unread_and_caps_the_page_size(): void
    {
        $this->addNotification($this->user);
        $this->addNotification($this->user, ['read_at' => now()]);

        $this->getJson(self::BASE . '?status=unread')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::BASE . '?status=read')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::BASE . '?per_page=500')->assertStatus(422);
        $this->getJson(self::BASE . '?status=bogus')->assertStatus(422);
    }

    #[Test]
    public function it_returns_the_unread_count(): void
    {
        $this->addNotification($this->user);
        $this->addNotification($this->user);
        $this->addNotification($this->otherUser);

        $this->getJson(self::BASE . '/unread-count')->assertOk()->assertJsonPath('data.unread_count', 2);
    }

    #[Test]
    public function marking_one_read_updates_it_and_tells_the_users_other_tabs(): void
    {
        Event::fake([NotificationsReadEvent::class]);
        $id = $this->addNotification($this->user);
        $this->addNotification($this->user);

        $this->postJson(self::BASE . "/{$id}/read")
            ->assertOk()
            ->assertJsonPath('data.notification.id', $id)
            ->assertJsonPath('data.notification.is_read', true)
            ->assertJsonPath('data.unread_count', 1);

        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        Event::assertDispatched(NotificationsReadEvent::class, fn ($e) => $e->userId === $this->user->id
            && $e->ids === [$id]
            && $e->unreadCount === 1);
    }

    #[Test]
    public function another_users_notification_cannot_be_read_or_deleted(): void
    {
        $id = $this->addNotification($this->otherUser);

        $this->postJson(self::BASE . "/{$id}/read")->assertNotFound();
        $this->deleteJson(self::BASE . "/{$id}")->assertNotFound();
        $this->postJson(self::BASE . '/not-a-uuid/read')->assertNotFound();

        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->assertDatabaseHas('notifications', ['id' => $id]);
    }

    #[Test]
    public function mark_all_read_only_touches_the_current_user(): void
    {
        Event::fake([NotificationsReadEvent::class]);
        $this->addNotification($this->user);
        $this->addNotification($this->user);
        $theirs = $this->addNotification($this->otherUser);

        $this->postJson(self::BASE . '/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertNull(DB::table('notifications')->where('id', $theirs)->value('read_at'));
        Event::assertDispatched(NotificationsReadEvent::class, fn ($e) => $e->ids === null && $e->unreadCount === 0);
    }

    #[Test]
    public function a_notification_can_be_deleted(): void
    {
        Event::fake([NotificationsReadEvent::class]);
        $id = $this->addNotification($this->user);
        $this->addNotification($this->user);

        $this->deleteJson(self::BASE . "/{$id}")->assertOk()->assertJsonPath('data.unread_count', 1);

        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    #[Test]
    public function the_read_event_is_broadcast_privately_to_that_user(): void
    {
        $event = new NotificationsReadEvent($this->user->id, null, 0);

        $this->assertSame('private-App.Models.User.' . $this->user->id, $event->broadcastOn()[0]->name);
        $this->assertSame('notification.read', $event->broadcastAs());
        $this->assertSame(['ids' => null, 'unread_count' => 0], $event->broadcastWith());
    }

    #[Test]
    public function guests_are_rejected(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(self::BASE)->assertUnauthorized();
    }
}
