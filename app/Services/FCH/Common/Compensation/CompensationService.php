<?php

namespace App\Services\FCH\Common\Compensation;

use App\Models\Compensation\CompensationSetting;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes compensation ("Paid By") setups. Entity-agnostic on
 * purpose — compensation_settings is polymorphic, so the same service backs
 * employees today and any other paid entity (e.g. partner-side salesmen) later.
 */
class CompensationService
{
    /**
     * @return Collection<int, CompensationSetting>
     */
    public function getFor(Model $entity): Collection
    {
        return $this->rowsFor($this->resolveOwner($entity))->get();
    }

    /**
     * Compensation belongs to the person, not to whichever record was opened:
     * an employee and the login account linked to them are one person, so both
     * resolve to the same owner and therefore the same rows. Without this, the
     * same person could hold two conflicting setups — one reachable only from
     * the employee screen, the other only from the user screen.
     *
     * Mirrors how a User's subscription and invoices already resolve against
     * entity_type/entity_id, falling back to the User itself when it stands
     * alone (a plain portal user with no linked record).
     */
    private function resolveOwner(Model $entity): Model
    {
        if ($entity instanceof User && $entity->entity_type && $entity->entity_id) {
            return $entity->entity ?? $entity;
        }

        return $entity;
    }

    private function rowsFor(Model $owner)
    {
        return CompensationSetting::query()
            ->where('entity_type', $owner->getMorphClass())
            ->where('entity_id', $owner->getKey())
            ->orderBy('id');
    }

    /**
     * Write an entity's full compensation setup: one row for Hourly/Salary,
     * one row per applicable service for Per Deal, no rows for None.
     *
     * The rows are replaced rather than diffed — the set changes shape
     * whenever the selected services change, so rewriting it is both simpler
     * and the only way rows from a previous selection can't survive.
     *
     * @return Collection<int, CompensationSetting>
     */
    public function sync(Model $entity, ?string $paidBy, array $rows): Collection
    {
        $owner = $this->resolveOwner($entity);

        return DB::transaction(function () use ($owner, $paidBy, $rows) {
            $this->rowsFor($owner)->delete();

            $paidBy = $paidBy ?: null;

            if ($paidBy && $paidBy !== CompensationSetting::PAID_BY_NONE) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    CompensationSetting::create([
                        'partner_id' => $owner->partner_id,
                        'entity_type' => $owner->getMorphClass(),
                        'entity_id' => $owner->getKey(),
                        'paid_by' => $paidBy,
                        'service' => $row['service'] ?? null,
                        'payout_type' => $row['payout_type'] ?? null,
                        'amount' => ($row['amount'] ?? '') === '' ? null : $row['amount'],
                    ]);
                }
            }

            return $this->rowsFor($owner)->get();
        });
    }
}
