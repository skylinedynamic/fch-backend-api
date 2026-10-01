<?php

namespace App\Services\FCH\Common\Notification;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Turns "who to notify" into login users. Accepts a User, any entity model
 * users log in as (Borrower, Partner, Employee... - every User whose
 * entity_type/entity_id point at it, since one entity can have several
 * logins), or any mix of those in an array/collection. Duplicates and nulls
 * are dropped.
 */
class RecipientResolver
{
    /**
     * @return Collection<int, User>
     */
    public function resolve(mixed $recipients): Collection
    {
        return Collection::wrap($recipients instanceof Model ? [$recipients] : $recipients)
            ->flatMap(fn ($recipient) => $this->usersFor($recipient))
            ->unique(fn (User $user) => $user->getKey())
            ->values();
    }

    /**
     * @return iterable<User>
     */
    private function usersFor(mixed $recipient): iterable
    {
        if ($recipient instanceof User) {
            return [$recipient];
        }

        if ($recipient instanceof Model) {
            return User::where('entity_type', $recipient->getMorphClass())
                ->where('entity_id', $recipient->getKey())
                ->get();
        }

        if (is_iterable($recipient)) {
            return $this->resolve($recipient);
        }

        return [];
    }
}
