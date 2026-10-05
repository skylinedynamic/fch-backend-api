<?php

namespace App\Services\FCH\Common\Notification\Sms;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The number a user is texted at - notifications and 2FA codes alike.
 *
 * It comes from the record the user logs in as (entity_type), where the
 * phone and its country_code are kept, rather than from the users row:
 *
 *   Borrower  borrowers.country_code + borrowers.phone_number
 *   Partner   partners.country_code  + partners.phone_number
 *   Employee  employees.country_code + employees.phone_number_1
 *
 * A user with no such record, or whose record has no phone, is texted at
 * users.phone_number (the users table has no country_code). The number is
 * returned as stored; SmsSender normalises it to E.164 when sending.
 */
class SmsRecipient
{
    /** Entity class => its column holding the phone to text. */
    private const PHONE_COLUMNS = [
        Borrower::class => 'phone_number',
        Partner::class => 'phone_number',
        Employee::class => 'phone_number_1',
    ];

    private const COUNTRY_CODE_COLUMN = 'country_code';

    public function numberFor(User $user): ?string
    {
        return $this->entityNumber($user) ?? ($user->phone_number ?: null);
    }

    private function entityNumber(User $user): ?string
    {
        $column = self::PHONE_COLUMNS[$user->entity_type] ?? null;

        if ($column === null || !$user->entity_id) {
            return null;
        }

        /** @var class-string<Model> $class */
        $class = $user->entity_type;

        // A direct read rather than $user->entity: lazy loading is disabled
        // outside production, and only two columns are needed.
        $entity = $class::query()
            ->whereKey($user->entity_id)
            ->first([$column, self::COUNTRY_CODE_COLUMN]);

        $phone = trim((string) ($entity?->{$column} ?? ''));

        if ($phone === '') {
            return null;
        }

        // Already international - its own country code is in it.
        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        return trim((string) ($entity->{self::COUNTRY_CODE_COLUMN} ?? '')) . $phone;
    }
}
