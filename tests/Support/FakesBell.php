<?php

namespace Tests\Support;

use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\FCH\Common\Notification\RecipientResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * The bell side of the Notifier without a database, for unit tests.
 *
 * Each entity model resolves to one in-memory login User, so a test can assert
 * who was notified in-app as well as who was mailed. The overrides cache is
 * seeded empty so the type registry reads only config/notifications.php.
 */
trait FakesBell
{
    /** @var array<string, User> */
    private array $bellUsers = [];

    protected function fakeBell(): void
    {
        Notification::fake();
        Cache::forever('notification_types.overrides', []);

        $users = &$this->bellUsers;

        $this->app->instance(RecipientResolver::class, new class($users) extends RecipientResolver {
            public function __construct(private array &$users)
            {
            }

            public function resolve(mixed $recipients): Collection
            {
                return Collection::wrap($recipients instanceof Model ? [$recipients] : $recipients)
                    ->filter()
                    ->map(fn ($model) => $model instanceof User ? $model : $this->userFor($model))
                    ->values();
            }

            private function userFor(Model $model): User
            {
                $key = $model::class . '#' . $model->getKey();

                if (!isset($this->users[$key])) {
                    $user = new User(['email' => 'login-' . count($this->users) . '@example.test']);
                    $user->id = 1000 + count($this->users);
                    $this->users[$key] = $user;
                }

                return $this->users[$key];
            }
        });
    }

    /** The login user the fake resolves this entity to. */
    protected function bellUserFor(Model $model): User
    {
        return app(RecipientResolver::class)->resolve($model)->first();
    }

    protected function assertBelled(Model $recipient, string $typeKey, ?callable $data = null): void
    {
        $user = $this->bellUserFor($recipient);

        Notification::assertSentTo($user, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === $typeKey
            && ($data === null || $data($n->toArray($user))));
    }
}
