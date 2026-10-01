<?php

namespace Tests\Feature\Notification;

use App\Models\User\User;
use App\Notifications\DynamicNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `php artisan notifications:test {user}` - a QA check that the bell receives
 * a real-time notification end to end (queue worker + Reverb + portal).
 */
class SendTestNotificationCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_sends_the_test_notification_to_the_given_user(): void
    {
        Notification::fake();
        $user = User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'QA User',
            'email' => 'qa-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => User::USER,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->artisan('notifications:test', ['user' => $user->id])->assertSuccessful();

        Notification::assertSentTo($user, DynamicNotification::class, fn ($n) => $n->typeKey === 'system.test');
    }

    #[Test]
    public function it_fails_for_an_unknown_user(): void
    {
        $this->artisan('notifications:test', ['user' => 999999])->assertFailed();
    }
}
