<?php

namespace App\Services\Maintenance;

/**
 * What a records:purge run would delete, worked out by RecordPurgeService::plan()
 * before anything is touched. The dry run prints it; execute() deletes exactly it.
 */
final class PurgePlan
{
    /** RecordPurgeService::TYPE_* */
    public string $type = RecordPurgeService::TYPE_PARTNER;

    /** RecordPurgeService::MODE_* */
    public string $mode = RecordPurgeService::MODE_FULL;

    /** @var list<array{id: int, name: ?string, email: ?string, type: string, partner_id: ?int}> */
    public array $users = [];

    /** @var list<array{id: int, company: ?string, email: ?string, clients: int}> */
    public array $partners = [];

    /** @var list<array{id: int, name: string, company: ?string, email: ?string, partner_id: ?int}> */
    public array $clients = [];

    /** @var list<string> emails that matched nothing */
    public array $notFound = [];

    /** @var list<string> reasons the run must not go ahead at all */
    public array $errors = [];

    /** @var list<string> kept rows that stand in the way; some can be reassigned */
    public array $blockers = [];

    /** @var list<array{table: string, column: string, ids: list<int>}> kept rows pointing at a deleted user */
    public array $reassignable = [];

    /** @var bool whether any blocker cannot be fixed by reassigning */
    public bool $hasHardBlocker = false;

    /** @var array<string, list<int|string>> ids per set (partners, borrowers, users, ...) */
    public array $sets = [];

    /** @var array<string, int> rows per table, in delete order */
    public array $counts = [];

    /** @var list<array{disk: string, path: string}> */
    public array $files = [];

    /** @var list<array{disk: string, path: string}> whole folders that belong to a deleted record */
    public array $folders = [];

    /** @var list<string> "table.column (n)" references the rules would leave behind */
    public array $leftovers = [];

    public function clientCount(): int
    {
        return count($this->sets[RecordPurgeService::SET_BORROWERS] ?? []);
    }

    /** How many partners, borrowers or users were asked for and matched. */
    public function subjectCount(): int
    {
        return match ($this->type) {
            RecordPurgeService::TYPE_PARTNER => count($this->partners),
            RecordPurgeService::TYPE_USER => count($this->users),
            default => count($this->clients),
        };
    }

    public function isEmpty(): bool
    {
        return $this->subjectCount() === 0;
    }
}
