<?php

namespace App\Console\Commands;

use App\Services\Maintenance\PurgePlan;
use App\Services\Maintenance\RecordPurgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Force-deletes a partner, a borrower or a user (see RecordPurgeService).
 *
 *  1. What to delete: partner, borrower or user.
 *  2. Their email(s), comma separated.
 *  3. A borrower only: full, or partial (just the deals - funding requests,
 *     applications, their activity, commissions and the commissions' payout
 *     invoices and payments; the borrower and its enrollment money stay).
 *     A partner or user is always a full delete; a partner's borrowers all go
 *     with it, fully.
 *  4. The list of everything that will go, then yes / no (no by default).
 *     No stops with nothing deleted.
 *
 * Every step can be given as an option instead (--type, --email, --mode);
 * --dry-run only lists. Irreversible - take a database backup first.
 */
class PurgeRecords extends Command
{
    public const QUESTION_TYPE = 'What do you want to delete?';
    public const QUESTION_EMAIL = 'Email(s), comma separated';
    public const QUESTION_MODE = 'Full delete, or partial (deals only: funding requests, applications, commissions)?';
    public const QUESTION_REASSIGN = 'Email of a kept user to take these rows over (empty to stop)';
    public const CONFIRMATION_QUESTION = 'Force-delete all of it? This cannot be undone.';

    protected $signature = 'records:purge
        {--type= : partner, borrower or user}
        {--email=* : Their emails (repeat, or comma separated)}
        {--mode= : full or partial (partial is for a borrower only)}
        {--keep-email=* : Borrower emails never deleted, even when their partner is}
        {--reassign-to= : Email of a kept user who takes over kept rows that name a deleted user}
        {--dry-run : Only list what would be deleted}';

    protected $description = 'Force-delete a partner, borrower or user with all their data, after listing it';

    public function handle(RecordPurgeService $purge): int
    {
        $type = $this->option('type') ?: $this->choice(self::QUESTION_TYPE, RecordPurgeService::TYPES);
        $emails = $this->option('email') ?: [(string) $this->ask(self::QUESTION_EMAIL)];
        $mode = $this->option('mode')
            ?: ($type === RecordPurgeService::TYPE_BORROWER
                ? $this->choice(self::QUESTION_MODE, RecordPurgeService::MODES, 0)
                : RecordPurgeService::MODE_FULL);

        $plan = $purge->plan($type, $emails, $mode, $this->option('keep-email'));

        $this->newLine();
        $this->line($this->option('dry-run') ? '<fg=yellow;options=bold>DRY RUN</> - nothing is deleted' : '<options=bold>What will be force-deleted</>');
        $this->line(sprintf('Database: %s | %s | %s delete', DB::connection()->getDatabaseName(), $plan->type, $plan->mode));

        foreach ($plan->errors as $error) {
            $this->error($error);
        }
        if ($plan->errors !== []) {
            return self::FAILURE;
        }

        $this->report($plan);

        if ($plan->isEmpty()) {
            $this->info('Nothing matched; nothing to delete.');

            return self::SUCCESS;
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if ($plan->hasHardBlocker) {
            $this->error('Stopped: fix the blockers above first. Nothing was deleted.');

            return self::FAILURE;
        }

        $reassignTo = null;
        if ($plan->reassignable !== []) {
            $email = strtolower(trim((string) $this->option('reassign-to')));
            $reassignTo = $email === '' ? null : DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->value('id');
            if ($reassignTo === null || in_array((int) $reassignTo, $plan->sets[RecordPurgeService::SET_USERS], true)) {
                $this->error('Stopped: pass --reassign-to=<email> of a user who is kept. Nothing was deleted.');

                return self::FAILURE;
            }
        }

        $this->warn($plan->mode === RecordPurgeService::MODE_PARTIAL
            ? sprintf('This will force-delete the deals of %d borrower(s) listed above.', $plan->subjectCount())
            : sprintf('This will force-delete %d %s(s) and everything listed above.', $plan->subjectCount(), $plan->type));
        if (! $this->confirm(self::CONFIRMATION_QUESTION, false)) {
            $this->info('Stopped. Nothing was deleted.');

            return self::SUCCESS;
        }

        try {
            $deleted = $purge->execute($plan, $reassignTo === null ? null : (int) $reassignTo);
        } catch (Throwable $e) {
            $this->error('Failed and rolled back: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Deleted %d rows across %d tables.', array_sum($deleted), count($deleted)));
        $this->line('Leftover references: none');
        $this->line('Report: ' . Storage::disk('local')->path(RecordPurgeService::REPORT_FOLDER));

        return self::SUCCESS;
    }

    private function report(PurgePlan $plan): void
    {
        if ($plan->partners !== []) {
            $this->newLine();
            $this->line('<options=bold>Partners</>');
            $this->table(['ID', 'Company', 'Email', 'Borrowers'], array_map(fn ($p) => [$p['id'], $p['company'], $p['email'], $p['clients']], $plan->partners));
        }

        if ($plan->clients !== []) {
            $heading = match (true) {
                $plan->type === RecordPurgeService::TYPE_PARTNER => 'These borrowers will also be fully deleted',
                $plan->mode === RecordPurgeService::MODE_PARTIAL => 'Borrowers whose deals will be deleted (the borrowers stay)',
                default => 'Borrowers',
            };
            $this->line("<options=bold>{$heading}</> (" . count($plan->clients) . ')');
            $this->table(['ID', 'Name', 'Company', 'Email', 'Partner ID'], array_map(fn ($c) => [$c['id'], $c['name'], $c['company'], $c['email'], $c['partner_id']], $plan->clients));
        }

        if ($plan->users !== []) {
            $this->line('<options=bold>Users</> (' . count($plan->users) . ')');
            $this->table(['ID', 'Name', 'Email', 'Login of', 'Partner ID'], array_map(fn ($u) => [$u['id'], $u['name'], $u['email'], $u['type'], $u['partner_id']], $plan->users));
        }

        foreach ($plan->notFound as $email) {
            $this->warn("Not found: {$email}");
        }

        if ($plan->counts !== []) {
            $this->line('<options=bold>Rows per table</> (delete order)');
            $this->table(['Table', 'Rows'], array_map(fn ($table, $n) => [$table, $n], array_keys($plan->counts), $plan->counts));
            $this->line(sprintf('Total: %d rows across %d tables', array_sum($plan->counts), count($plan->counts)));
        }

        $this->line(sprintf('Files: %d listed, plus %d folder(s)', count($plan->files), count($plan->folders)));

        foreach ($plan->blockers as $blocker) {
            $this->error($blocker);
        }

        $this->line($plan->leftovers === []
            ? 'Leftover references: none'
            : '<fg=red>Leftover references: ' . implode(', ', $plan->leftovers) . '</>');
    }
}
