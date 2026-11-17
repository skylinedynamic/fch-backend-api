<?php

namespace App\Services\Maintenance;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Employee\Employee;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Invoice\Invoice;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerDocument;
use App\Models\Payment\Payment;
use App\Models\User\User;
use App\Support\Files\Disk;
use App\Support\Files\Folder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Force-deletes partners and clients with everything that hangs off them
 * (records:purge). plan() works out what would go without touching anything;
 * execute() deletes exactly that in one transaction, then their files.
 *
 * Most of these tables have no foreign key to the record they belong to, and
 * several that do are RESTRICT, so the database cannot cascade this. Instead
 * every table is matched by one rule, the same for counting and deleting:
 *
 *  - a key column (partner_id, borrower_id, user_id, funding_application_id,
 *    funding_request_id, commission_id) holding a deleted id;
 *  - a polymorphic pair (entity_type/entity_id, subject_, causer_, notifiable_,
 *    model_) naming a deleted record;
 *  - a few links through a parent (EXTRA_RULES), e.g. chat messages by
 *    conversation.
 *
 * Clients, users and partners themselves go last, by id. Anything a rule would
 * still leave pointing at them is reported as a leftover, and execute() rolls
 * back if one remains.
 */
final class RecordPurgeService
{
    public const REPORT_FOLDER = 'purge-reports';

    public const TYPE_PARTNER = 'partner';
    public const TYPE_BORROWER = 'borrower';
    public const TYPE_USER = 'user';
    public const TYPES = [self::TYPE_PARTNER, self::TYPE_BORROWER, self::TYPE_USER];

    public const MODE_FULL = 'full';
    public const MODE_PARTIAL = 'partial';
    public const MODES = [self::MODE_FULL, self::MODE_PARTIAL];

    public const SET_PARTNERS = 'partners';
    public const SET_BORROWERS = 'borrowers';
    public const SET_USERS = 'users';
    private const SET_EMPLOYEES = 'employees';
    private const SET_LEADS = 'leads';
    private const SET_FUNDING_REQUESTS = 'funding_requests';
    private const SET_FUNDING_APPLICATIONS = 'funding_applications';
    private const SET_COMMISSIONS = 'commissions';
    private const SET_COMMISSION_PAYOUTS = 'commission_payouts';
    private const SET_PAYOUT_INVOICES = 'payout_invoices';
    private const SET_PAYOUT_PAYMENTS = 'payout_payments';
    private const SET_INVOICES = 'invoices';
    private const SET_PAYMENTS = 'payments';
    private const SET_CHATS = 'chat_conversations';
    private const SET_PREQUALS = 'pre_qualification_requests';
    private const SET_CREDIT_PROFILES = 'borrower_credit_profiles';
    private const SET_EMBED_SUBMISSIONS = 'embedded_form_submissions';
    private const SET_TOKENS = 'oauth_access_tokens';
    private const SET_APPOINTMENTS = 'appointments';
    private const SET_ROLES = 'roles';

    private const ALL_SETS = [
        self::SET_PARTNERS, self::SET_BORROWERS, self::SET_USERS, self::SET_EMPLOYEES, self::SET_LEADS,
        self::SET_FUNDING_REQUESTS, self::SET_FUNDING_APPLICATIONS, self::SET_COMMISSIONS, self::SET_COMMISSION_PAYOUTS,
        self::SET_PAYOUT_INVOICES, self::SET_PAYOUT_PAYMENTS, self::SET_INVOICES, self::SET_PAYMENTS, self::SET_CHATS,
        self::SET_PREQUALS, self::SET_CREDIT_PROFILES, self::SET_EMBED_SUBMISSIONS, self::SET_TOKENS,
        self::SET_APPOINTMENTS, self::SET_ROLES,
    ];

    /** Tables whose own rows are a set: a row whose id is in it goes (a partial delete names them directly). */
    private const OWN_ID_SETS = [
        'funding_requests' => self::SET_FUNDING_REQUESTS,
        'funding_applications' => self::SET_FUNDING_APPLICATIONS,
        'commissions' => self::SET_COMMISSIONS,
        'commission_payouts' => self::SET_COMMISSION_PAYOUTS,
        'invoices' => self::SET_INVOICES,
        'payments' => self::SET_PAYMENTS,
        'employees' => self::SET_EMPLOYEES,
    ];

    /** A row whose column holds an id of the set belongs to what is deleted. */
    private const KEY_COLUMNS = [
        'partner_id' => self::SET_PARTNERS,
        'borrower_id' => self::SET_BORROWERS,
        'user_id' => self::SET_USERS,
        'funding_application_id' => self::SET_FUNDING_APPLICATIONS,
        'funding_request_id' => self::SET_FUNDING_REQUESTS,
        'commission_id' => self::SET_COMMISSIONS,
    ];

    /** Polymorphic pairs: type column => id column. */
    private const MORPH_PAIRS = [
        'entity_type' => 'entity_id',
        'subject_type' => 'subject_id',
        'causer_type' => 'causer_id',
        'notifiable_type' => 'notifiable_id',
        'model_type' => 'model_id',
    ];

    /** The record class a polymorphic type names => the set of its deleted ids. */
    private const MORPH_SETS = [
        Partner::class => self::SET_PARTNERS,
        Borrower::class => self::SET_BORROWERS,
        User::class => self::SET_USERS,
        Employee::class => self::SET_EMPLOYEES,
        Lead::class => self::SET_LEADS,
        FundingApplication::class => self::SET_FUNDING_APPLICATIONS,
        FundingRequest::class => self::SET_FUNDING_REQUESTS,
        Commission::class => self::SET_COMMISSIONS,
        Invoice::class => self::SET_INVOICES,
        Payment::class => self::SET_PAYMENTS,
    ];

    /** Rows reached through a parent rather than a key column: table => [column => set]. */
    private const EXTRA_RULES = [
        'chat_messages' => ['conversation_id' => self::SET_CHATS],
        'pre_qualification_gpt_results' => ['pre_qualification_request_id' => self::SET_PREQUALS],
        'borrower_credit_inquiries' => ['borrower_credit_profile_id' => self::SET_CREDIT_PROFILES],
        'borrower_credit_trades' => ['borrower_credit_profile_id' => self::SET_CREDIT_PROFILES],
        'borrower_credit_addresses' => ['borrower_credit_profile_id' => self::SET_CREDIT_PROFILES],
        'embedded_form_attachments' => ['submission_id' => self::SET_EMBED_SUBMISSIONS],
        'embedded_form_co_borrowers' => ['submission_id' => self::SET_EMBED_SUBMISSIONS],
        'oauth_refresh_tokens' => ['access_token_id' => self::SET_TOKENS],
        'appointments' => ['scheduled_by' => self::SET_USERS, 'assigned_for' => self::SET_USERS],
        'appointment_details' => ['appointment_id' => self::SET_APPOINTMENTS, 'scheduled_by' => self::SET_USERS, 'assigned_for' => self::SET_USERS],
        'role_has_permissions' => ['role_id' => self::SET_ROLES],
        'model_has_roles' => ['role_id' => self::SET_ROLES],
        // A commission paid out on a deleted deal: its invoice and payment.
        'invoices' => ['id' => self::SET_PAYOUT_INVOICES],
        'payments' => ['id' => self::SET_PAYOUT_PAYMENTS, 'invoice_id' => self::SET_INVOICES],
        // site_settings.partner_id points at users, not partners.
        'site_settings' => ['partner_id' => self::SET_USERS],
    ];

    /** Columns named like a key column that mean something else (see EXTRA_RULES). */
    private const NOT_KEY_COLUMNS = ['site_settings.partner_id'];

    /** Matched by id only and deleted last, in this order: everything else points at them. */
    private const PARENT_TABLES = ['borrowers' => self::SET_BORROWERS, 'users' => self::SET_USERS, 'partners' => self::SET_PARTNERS];

    /** Deleted before the rest: their parents are matched by the rules, and some point at them. */
    private const CHILD_TABLES_FIRST = [
        'chat_messages', 'pre_qualification_gpt_results', 'borrower_credit_inquiries', 'borrower_credit_trades',
        'borrower_credit_addresses', 'commission_payouts', 'embedded_form_attachments', 'embedded_form_co_borrowers',
        'oauth_refresh_tokens', 'appointment_details', 'role_has_permissions', 'model_has_roles', 'model_has_permissions',
        'funding_application_co_borrowers', 'funding_application_documents', 'agreement_signs',
    ];

    /** Framework bookkeeping, never touched. */
    private const SKIP_TABLES = ['migrations', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks', 'password_reset_tokens'];

    /** Kept rows that may still name a deleted user where the database forbids it (RESTRICT). */
    private const USER_BLOCKER_COLUMNS = [
        ['borrowers', 'assign_id'],
        ['commissions', 'created_by'],
        ['commissions', 'updated_by'],
        ['payments', 'created_by'],
    ];

    /** @var array<string, list<string>> */
    private array $columns = [];

    // ------------------------------------------------------------------ plan

    /**
     * What a run would force-delete, worked out without touching anything.
     *
     *  - partner, full:     the partners, every one of their clients (but the
     *                       kept ones) and all of it - logins, deals, money, files.
     *  - borrower, full:    the clients and all of theirs.
     *  - borrower, partial: only the clients' deals - funding requests, funding
     *                       applications (their documents, co-borrowers, audit
     *                       trail and activity), commissions and the commissions'
     *                       payout invoices and payments. The client, its login,
     *                       its enrollment invoices and payments and its documents stay.
     *  - user, full:        the logins and what is theirs (tokens, roles,
     *                       notifications, appointments, a staff member's employee record).
     *
     * @param  list<string>  $emails
     * @param  list<string>  $keepEmails  clients never deleted, even when their partner is
     */
    public function plan(string $type, array $emails, string $mode = self::MODE_FULL, array $keepEmails = []): PurgePlan
    {
        $plan = new PurgePlan();
        $plan->type = $type;
        $plan->mode = $mode;
        $emails = $this->normalise($emails);
        $keepEmails = $this->normalise($keepEmails);

        if (! in_array($type, self::TYPES, true)) {
            $plan->errors[] = "Unknown type \"{$type}\": choose " . implode(', ', self::TYPES) . '.';
        }
        if (! in_array($mode, self::MODES, true)) {
            $plan->errors[] = "Unknown mode \"{$mode}\": choose " . implode(', ', self::MODES) . '.';
        }
        if ($mode === self::MODE_PARTIAL && $type !== self::TYPE_BORROWER) {
            $plan->errors[] = 'A partial delete is only for a borrower.';
        }
        if ($plan->errors !== []) {
            return $plan;
        }

        $sets = array_fill_keys(self::ALL_SETS, []);
        $keepIds = $this->borrowerIdsByEmail($keepEmails);

        if ($type === self::TYPE_PARTNER) {
            $partners = $this->partnersByEmail($emails, $plan);
            $host = Partner::host();
            foreach ($partners as $partner) {
                if ($partner->is_host || ($host && (int) $partner->id === (int) $host->id)) {
                    $plan->errors[] = "Refused: {$partner->email} is the host partner.";
                }
            }
            if ($plan->errors !== []) {
                return $plan;
            }
            $sets[self::SET_PARTNERS] = array_map(fn ($p) => (int) $p->id, $partners);
            $partnerIds = $sets[self::SET_PARTNERS] ?: [0];
            $sets[self::SET_BORROWERS] = array_values(array_diff(
                $this->ints(DB::table('borrowers')->whereIn('partner_id', $partnerIds)->pluck('id')),
                $keepIds
            ));
            foreach (DB::table('borrowers')->whereIn('id', $keepIds ?: [0])->whereIn('partner_id', $partnerIds)->get(['email']) as $kept) {
                $plan->blockers[] = "Blocker: kept client {$kept->email} belongs to a partner being deleted - move it to another partner first.";
                $plan->hasHardBlocker = true;
            }
            $plan->partners = array_map(fn ($p) => [
                'id' => (int) $p->id, 'company' => $p->company_name, 'email' => $p->email,
                'clients' => DB::table('borrowers')->where('partner_id', $p->id)->count(),
            ], $partners);
        } elseif ($type === self::TYPE_BORROWER) {
            $matched = array_values(array_diff($this->borrowerIdsByEmail($emails, $plan), $keepIds));
            if ($mode === self::MODE_FULL) {
                $sets[self::SET_BORROWERS] = $matched;
            } else {
                $this->planDeals($sets, $matched);
            }
            $plan->clients = $this->clientRows($matched);
        } else {
            $users = DB::table('users')->where(function (Builder $q) use ($emails) {
                foreach ($emails ?: [''] as $email) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })->get(['id', 'name', 'email', 'entity_type', 'entity_id', 'partner_id']);
            $found = $users->map(fn ($u) => strtolower((string) $u->email))->all();
            $plan->notFound = array_values(array_diff($emails, $found));
            $host = Partner::host();
            foreach ($users as $user) {
                if ($host && $user->entity_type === Partner::class && (int) $user->entity_id === (int) $host->id) {
                    $plan->errors[] = "Refused: {$user->email} is the host partner's own login.";
                }
            }
            if ($plan->errors !== []) {
                return $plan;
            }
            $sets[self::SET_USERS] = $this->ints($users->pluck('id'));
            $sets[self::SET_EMPLOYEES] = $this->ints($users->where('entity_type', Employee::class)->pluck('entity_id'));
            $plan->users = $users->map(fn ($u) => [
                'id' => (int) $u->id, 'name' => $u->name, 'email' => $u->email,
                'type' => class_basename((string) $u->entity_type), 'partner_id' => $u->partner_id ? (int) $u->partner_id : null,
            ])->values()->all();
        }

        if ($mode === self::MODE_FULL) {
            $this->planEverything($sets, $keepIds);
            if ($type === self::TYPE_PARTNER) {
                $plan->clients = $this->clientRows($sets[self::SET_BORROWERS]);
            }
        }
        $plan->sets = $sets;

        foreach ($this->deleteOrder() as $table) {
            $count = $this->ruleQuery($table, $sets)?->count() ?? 0;
            if ($count > 0) {
                $plan->counts[$table] = $count;
            }
        }

        $this->findUserBlockers($plan);
        $this->collectFiles($plan);
        $plan->leftovers = $this->leftovers($sets);

        return $plan;
    }

    /** A full delete: the logins of the partners and clients, then every set from its table's rule. */
    private function planEverything(array &$sets, array $keepIds): void
    {
        $borrowerIds = $sets[self::SET_BORROWERS];
        $partnerIds = $sets[self::SET_PARTNERS];
        if ($borrowerIds !== [] || $partnerIds !== []) {
            $logins = DB::table('users')
                ->where(function (Builder $q) use ($borrowerIds, $partnerIds) {
                    $q->where(fn ($b) => $b->where('entity_type', Borrower::class)->whereIn('entity_id', $borrowerIds ?: [0]))
                        ->orWhereIn('partner_id', $partnerIds ?: [0])
                        ->orWhere(fn ($p) => $p->where('entity_type', Partner::class)->whereIn('entity_id', $partnerIds ?: [0]));
                })
                // A kept client's login stays, even under a deleted partner (that is a blocker).
                ->whereNot(fn ($k) => $k->where('entity_type', Borrower::class)->whereIn('entity_id', $keepIds ?: [0]))
                ->pluck('id');
            $sets[self::SET_USERS] = array_values(array_unique([...$sets[self::SET_USERS], ...$this->ints($logins)]));
        }

        // Each set from its table's rule, in an order where every set a rule needs is known.
        foreach ([
            self::SET_EMPLOYEES => 'employees', self::SET_LEADS => 'leads',
            self::SET_FUNDING_REQUESTS => 'funding_requests', self::SET_FUNDING_APPLICATIONS => 'funding_applications',
            self::SET_COMMISSIONS => 'commissions', self::SET_COMMISSION_PAYOUTS => 'commission_payouts',
        ] as $set => $table) {
            $sets[$set] = array_values(array_unique([...$sets[$set], ...$this->idsByRule($table, $sets)]));
        }
        $this->planPayouts($sets);
        foreach ([
            self::SET_INVOICES => 'invoices', self::SET_PAYMENTS => 'payments', self::SET_CHATS => 'chat_conversations',
            self::SET_PREQUALS => 'pre_qualification_requests', self::SET_CREDIT_PROFILES => 'borrower_credit_profiles',
            self::SET_EMBED_SUBMISSIONS => 'embedded_form_submissions', self::SET_TOKENS => 'oauth_access_tokens',
            self::SET_APPOINTMENTS => 'appointments', self::SET_ROLES => 'roles',
        ] as $set => $table) {
            $sets[$set] = array_values(array_unique([...$sets[$set], ...$this->idsByRule($table, $sets)]));
        }
    }

    /**
     * A partial delete: only the clients' deals. Their funding requests and
     * applications, the commissions on them and the commissions' payout
     * invoices and payments - never the client's enrollment invoices or payments.
     */
    private function planDeals(array &$sets, array $borrowerIds): void
    {
        $borrowerIds = $borrowerIds ?: [0];
        $sets[self::SET_FUNDING_REQUESTS] = $this->ints(DB::table('funding_requests')->whereIn('borrower_id', $borrowerIds)->pluck('id'));
        $requests = $sets[self::SET_FUNDING_REQUESTS] ?: [0];
        $sets[self::SET_FUNDING_APPLICATIONS] = $this->ints(DB::table('funding_applications')
            ->where(fn (Builder $q) => $q->whereIn('borrower_id', $borrowerIds)->orWhereIn('funding_request_id', $requests))
            ->pluck('id'));
        $applications = $sets[self::SET_FUNDING_APPLICATIONS] ?: [0];
        $sets[self::SET_COMMISSIONS] = $this->ints(DB::table('commissions')
            ->where(fn (Builder $q) => $q->whereIn('borrower_id', $borrowerIds)->orWhereIn('funding_application_id', $applications))
            ->pluck('id'));
        $sets[self::SET_COMMISSION_PAYOUTS] = $this->ints(DB::table('commission_payouts')->whereIn('commission_id', $sets[self::SET_COMMISSIONS] ?: [0])->pluck('id'));
        $this->planPayouts($sets);
        $sets[self::SET_INVOICES] = $sets[self::SET_PAYOUT_INVOICES];
        $sets[self::SET_PAYMENTS] = array_values(array_unique([
            ...$sets[self::SET_PAYOUT_PAYMENTS],
            ...$this->ints(DB::table('payments')->whereIn('invoice_id', $sets[self::SET_INVOICES] ?: [0])->pluck('id')),
        ]));
    }

    /** The invoices and payments of the commission payouts being deleted. */
    private function planPayouts(array &$sets): void
    {
        $payouts = DB::table('commission_payouts')->whereIn('id', $sets[self::SET_COMMISSION_PAYOUTS] ?: [0]);
        $sets[self::SET_PAYOUT_INVOICES] = $this->ints((clone $payouts)->whereNotNull('invoice_id')->pluck('invoice_id'));
        $sets[self::SET_PAYOUT_PAYMENTS] = $this->ints((clone $payouts)->whereNotNull('payment_id')->pluck('payment_id'));
    }

    /** @return list<array{id: int, name: string, company: ?string, email: ?string, partner_id: ?int}> */
    private function clientRows(array $ids): array
    {
        return DB::table('borrowers')->whereIn('id', $ids ?: [0])->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'business_name', 'email', 'partner_id'])
            ->map(fn ($b) => [
                'id' => (int) $b->id, 'name' => trim("{$b->first_name} {$b->last_name}"),
                'company' => $b->business_name, 'email' => $b->email, 'partner_id' => $b->partner_id ? (int) $b->partner_id : null,
            ])->all();
    }

    // --------------------------------------------------------------- execute

    /**
     * Deletes what the plan found, in one transaction, then its files. A kept
     * row still naming a deleted user is first handed to $reassignTo.
     *
     * @return array<string, int> rows deleted per table
     */
    public function execute(PurgePlan $plan, ?int $reassignTo = null): array
    {
        if ($plan->errors !== [] || $plan->hasHardBlocker) {
            throw new RuntimeException('The plan has errors or blockers; nothing was deleted.');
        }
        if ($plan->reassignable !== [] && $reassignTo === null) {
            throw new RuntimeException('Kept rows still name a user being deleted; pass a user to reassign them to.');
        }

        $deleted = DB::transaction(function () use ($plan, $reassignTo) {
            foreach ($plan->reassignable as $row) {
                DB::table($row['table'])->whereIn('id', $row['ids'])->update([$row['column'] => $reassignTo]);
            }

            $deleted = [];
            foreach ($this->deleteOrder() as $table) {
                $count = $this->ruleQuery($table, $plan->sets)?->delete() ?? 0;
                if ($count > 0) {
                    $deleted[$table] = $count;
                }
            }

            $left = $this->leftovers($plan->sets);
            if ($left !== []) {
                throw new RuntimeException('References remain after the delete, rolled back: ' . implode(', ', $left));
            }

            return $deleted;
        });

        $filesDeleted = $this->deleteFiles($plan);
        $this->writeReport($plan, $deleted, $filesDeleted);

        return $deleted;
    }

    // ----------------------------------------------------------------- rules

    /** Every table of this database, children first and the parent records last. */
    private function deleteOrder(): array
    {
        $tables = array_diff(
            Schema::getTableListing(Schema::getCurrentSchemaName(), false),
            self::SKIP_TABLES,
            array_keys(self::PARENT_TABLES),
        );
        sort($tables);
        $first = array_values(array_intersect(self::CHILD_TABLES_FIRST, $tables));

        return [...$first, ...array_values(array_diff($tables, $first)), ...array_keys(self::PARENT_TABLES)];
    }

    /**
     * The rows of $table that belong to what is deleted, or null when nothing
     * in the table can (no key column, polymorphic pair or extra rule).
     */
    private function ruleQuery(string $table, array $sets): ?Builder
    {
        $conditions = $this->conditions($table, $sets);
        if ($conditions === []) {
            return null;
        }

        return DB::table($table)->where(function (Builder $q) use ($conditions) {
            foreach ($conditions as $condition) {
                $q->orWhere($condition);
            }
        });
    }

    /** @return list<\Closure> */
    private function conditions(string $table, array $sets): array
    {
        $columns = $this->columnsOf($table);
        $conditions = [];
        $in = fn (string $column, array $ids) => fn (Builder $q) => $this->whereIds($q, $column, $ids);

        if (isset(self::PARENT_TABLES[$table])) {
            $ids = $sets[self::PARENT_TABLES[$table]] ?? [];

            return $ids === [] ? [] : [$in('id', $ids)];
        }

        foreach (self::KEY_COLUMNS as $column => $set) {
            if (in_array($column, $columns, true) && ! in_array("{$table}.{$column}", self::NOT_KEY_COLUMNS, true) && ! empty($sets[$set])) {
                $conditions[] = $in($column, $sets[$set]);
            }
        }

        foreach (self::MORPH_PAIRS as $typeColumn => $idColumn) {
            if (! in_array($typeColumn, $columns, true) || ! in_array($idColumn, $columns, true)) {
                continue;
            }
            foreach (self::MORPH_SETS as $class => $set) {
                if (! empty($sets[$set])) {
                    $conditions[] = fn (Builder $q) => $q->where($typeColumn, $class)->where(fn ($w) => $this->whereIds($w, $idColumn, $sets[$set]));
                }
            }
        }

        if (isset(self::OWN_ID_SETS[$table]) && ! empty($sets[self::OWN_ID_SETS[$table]])) {
            $conditions[] = $in('id', $sets[self::OWN_ID_SETS[$table]]);
        }

        foreach (self::EXTRA_RULES[$table] ?? [] as $column => $set) {
            if (in_array($column, $columns, true) && ! empty($sets[$set])) {
                $conditions[] = $in($column, $sets[$set]);
            }
        }

        return $conditions;
    }

    /** @return list<int|string> */
    private function idsByRule(string $table, array $sets): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }
        $ids = $this->ruleQuery($table, $sets)?->pluck('id')->all() ?? [];

        return array_values(array_map(fn ($id) => is_numeric($id) ? (int) $id : $id, $ids));
    }

    /**
     * References to a deleted partner, client, user, application, request or
     * commission that the rules would not delete: "table.column (n)".
     *
     * @return list<string>
     */
    private function leftovers(array $sets): array
    {
        $left = [];
        foreach (Schema::getTableListing(Schema::getCurrentSchemaName(), false) as $table) {
            if (in_array($table, self::SKIP_TABLES, true)) {
                continue;
            }
            foreach (self::KEY_COLUMNS as $column => $set) {
                if (empty($sets[$set]) || ! in_array($column, $this->columnsOf($table), true) || in_array("{$table}.{$column}", self::NOT_KEY_COLUMNS, true)) {
                    continue;
                }
                $query = DB::table($table)->where(fn ($q) => $this->whereIds($q, $column, $sets[$set]));
                $conditions = $this->conditions($table, $sets);
                if ($conditions !== []) {
                    $query->whereNot(function (Builder $q) use ($conditions) {
                        foreach ($conditions as $condition) {
                            $q->orWhere($condition);
                        }
                    });
                }
                $count = $query->count();
                if ($count > 0) {
                    $left[] = "{$table}.{$column} ({$count})";
                }
            }
        }

        return $left;
    }

    /** Kept rows naming a deleted user where the database forbids it. */
    private function findUserBlockers(PurgePlan $plan): void
    {
        $users = $plan->sets[self::SET_USERS] ?? [];
        if ($users === []) {
            return;
        }
        foreach (self::USER_BLOCKER_COLUMNS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! in_array($column, $this->columnsOf($table), true)) {
                continue;
            }
            $query = DB::table($table)->whereIn($column, $users);
            $conditions = $this->conditions($table, $plan->sets);
            if ($conditions !== []) {
                $query->whereNot(function (Builder $q) use ($conditions) {
                    foreach ($conditions as $condition) {
                        $q->orWhere($condition);
                    }
                });
            }
            $ids = $this->ints($query->pluck('id'));
            if ($ids !== []) {
                $plan->reassignable[] = ['table' => $table, 'column' => $column, 'ids' => $ids];
                $plan->blockers[] = sprintf('Blocker: %d kept row(s) in %s.%s name a user being deleted - pass --reassign-to.', count($ids), $table, $column);
            }
        }
    }

    // ----------------------------------------------------------------- files

    private function collectFiles(PurgePlan $plan): void
    {
        $sets = $plan->sets;
        $borrowers = $sets[self::SET_BORROWERS] ?: [0];
        $partners = $sets[self::SET_PARTNERS] ?: [0];
        $applications = $sets[self::SET_FUNDING_APPLICATIONS] ?: [0];

        foreach (DB::table('borrower_documents')->whereIn('borrower_id', $borrowers)->whereNotNull('file_path')->get(['borrower_id', 'file_path']) as $row) {
            $plan->files[] = ['disk' => Disk::UPLOADS, 'path' => BorrowerDocument::storagePathFor($row->borrower_id, $row->file_path)];
        }
        foreach (['funding_application_documents', 'documents'] as $table) {
            $query = DB::table($table)->whereNotNull('path')->where(function (Builder $q) use ($table, $borrowers, $applications) {
                $q->whereIn('borrower_id', $borrowers);
                if ($table === 'funding_application_documents') {
                    $q->orWhereIn('funding_application_id', $applications);
                }
            });
            foreach ($query->get(['path', 'disk']) as $row) {
                $plan->files[] = ['disk' => $row->disk ?: Disk::UPLOADS, 'path' => $row->path];
            }
        }
        foreach (DB::table('partner_documents')->whereIn('partner_id', $partners)->whereNotNull('file_path')->get(['partner_id', 'file_path']) as $row) {
            $plan->files[] = ['disk' => Disk::UPLOADS, 'path' => PartnerDocument::storagePathFor($row->partner_id, $row->file_path)];
        }
        $submissions = $sets[self::SET_EMBED_SUBMISSIONS] ?: [0];
        $attachments = DB::table('embedded_form_attachments')->whereNotNull('file_path')
            ->where(fn (Builder $q) => $q->whereIn('partner_id', $partners)->orWhereIn('submission_id', $submissions));
        foreach ($attachments->get(['file_path']) as $row) {
            $plan->files[] = ['disk' => config('filesystems.default'), 'path' => $row->file_path];
        }

        // Signed copies are content-addressed: only when no kept audit event names the same file.
        $auditQuery = $this->ruleQuery('funding_application_audit_events', $sets);
        if ($auditQuery) {
            foreach ((clone $auditQuery)->whereNotNull('file_path')->distinct()->pluck('file_path') as $path) {
                $sharedWithKept = DB::table('funding_application_audit_events')->where('file_path', $path)
                    ->whereNot(fn ($q) => $q->whereIn('id', (clone $auditQuery)->select('id')))->exists();
                if (! $sharedWithKept) {
                    $plan->files[] = ['disk' => Disk::UPLOADS, 'path' => $path];
                }
            }
        }

        foreach ($sets[self::SET_BORROWERS] as $id) {
            $plan->folders[] = ['disk' => Disk::UPLOADS, 'path' => Folder::BORROWERS . "/{$id}"];
            $plan->folders[] = ['disk' => Disk::UPLOADS, 'path' => Folder::CREDIT_REPORTS . "/{$id}"];
        }
        foreach ($sets[self::SET_PARTNERS] as $id) {
            $plan->folders[] = ['disk' => Disk::UPLOADS, 'path' => Folder::PARTNERS . "/{$id}"];
        }

        $plan->files = array_values(array_unique($plan->files, SORT_REGULAR));
    }

    /** @return int files that were there and are gone */
    private function deleteFiles(PurgePlan $plan): int
    {
        $deleted = 0;
        foreach ($plan->files as $file) {
            try {
                $disk = Storage::disk($file['disk']);
                if ($disk->exists($file['path']) && $disk->delete($file['path'])) {
                    $deleted++;
                }
            } catch (Throwable) {
                // A missing disk or file must not undo a committed delete; the report counts what went.
            }
        }
        foreach ($plan->folders as $folder) {
            try {
                $disk = Storage::disk($folder['disk']);
                $count = count($disk->allFiles($folder['path']));
                if ($count > 0 && $disk->deleteDirectory($folder['path'])) {
                    $deleted += $count;
                }
            } catch (Throwable) {
            }
        }

        return $deleted;
    }

    /** IDs and counts only - no names or emails. */
    private function writeReport(PurgePlan $plan, array $deleted, int $filesDeleted): void
    {
        Storage::disk(Disk::LOCAL)->put(
            self::REPORT_FOLDER . '/purge-' . now()->format('Ymd-His') . '.json',
            json_encode([
                'ran_at' => now()->toIso8601String(),
                'partner_ids' => $plan->sets[self::SET_PARTNERS],
                'borrower_ids' => $plan->sets[self::SET_BORROWERS],
                'user_ids' => $plan->sets[self::SET_USERS],
                'funding_application_ids' => $plan->sets[self::SET_FUNDING_APPLICATIONS],
                'funding_request_ids' => $plan->sets[self::SET_FUNDING_REQUESTS],
                'commission_ids' => $plan->sets[self::SET_COMMISSIONS],
                'rows_deleted' => $deleted,
                'files_deleted' => $filesDeleted,
            ], JSON_PRETTY_PRINT)
        );
    }

    // --------------------------------------------------------------- helpers

    /** @return list<object> partners matched by their own email or their login's */
    private function partnersByEmail(array $emails, PurgePlan $plan): array
    {
        $found = [];
        foreach ($emails as $email) {
            $id = DB::table('partners')->whereRaw('LOWER(email) = ?', [$email])->value('id')
                ?? DB::table('users')->where('entity_type', Partner::class)->whereRaw('LOWER(email) = ?', [$email])->value('entity_id');
            if ($id === null) {
                $plan->notFound[] = $email;
                continue;
            }
            $found[(int) $id] = DB::table('partners')->where('id', $id)->first(['id', 'company_name', 'email', 'is_host']);
        }

        return array_values(array_filter($found));
    }

    /** @return list<int> clients matched by their own email or their login's */
    private function borrowerIdsByEmail(array $emails, ?PurgePlan $plan = null): array
    {
        $ids = [];
        foreach ($emails as $email) {
            $id = DB::table('borrowers')->whereRaw('LOWER(email) = ?', [$email])->value('id')
                ?? DB::table('users')->where('entity_type', Borrower::class)->whereRaw('LOWER(email) = ?', [$email])->value('entity_id');
            if ($id === null) {
                if ($plan !== null) {
                    $plan->notFound[] = $email;
                }
                continue;
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    private function whereIds(Builder $q, string $column, array $ids): Builder
    {
        $allInts = array_filter($ids, fn ($id) => ! is_int($id)) === [];

        return $allInts ? $q->whereIntegerInRaw($column, $ids) : $q->whereIn($column, $ids);
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        return $this->columns[$table] ??= Schema::getColumnListing($table);
    }

    /** @return list<int> */
    private function ints(iterable $values): array
    {
        $ints = [];
        foreach ($values as $value) {
            $ints[] = (int) $value;
        }

        return array_values(array_unique($ints));
    }

    /** @return list<string> */
    private function normalise(array $emails): array
    {
        $flat = [];
        foreach ($emails as $value) {
            foreach (explode(',', (string) $value) as $email) {
                $email = strtolower(trim($email));
                if ($email !== '') {
                    $flat[] = $email;
                }
            }
        }

        return array_values(array_unique($flat));
    }
}
