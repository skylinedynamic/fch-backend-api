<?php

namespace Tests\Feature\Console;

use App\Console\Commands\PurgeRecords;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\User\User;
use App\Services\Maintenance\RecordPurgeService;
use App\Support\Files\Disk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * records:purge force-deletes test partners and clients with everything that
 * hangs off them, after a dry run. Storage is faked: no real file is touched.
 */
#[CoversClass(PurgeRecords::class)]
#[CoversClass(RecordPurgeService::class)]
class PurgeRecordsCommandTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $host;
    private User $hostOwner;
    private Partner $target;      // the partner to delete, with its clients
    private Partner $otherPartner; // kept; one of its clients is listed
    private Borrower $kept;       // a host client that must survive (Michael Chen)
    private Borrower $listed;     // a listed client under the kept partner
    /** @var list<Borrower> */
    private array $targetClients = [];
    private User $targetStaff;
    /** @var array<string, int> application id by client email */
    private array $applicationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Disk::UPLOADS);
        Storage::fake(Disk::LOCAL);
        Storage::fake(config('filesystems.default'));
        $this->createPersonalAccessClient();

        $this->host = $this->makeHostPartner(['email' => 'host@example.test']);
        $this->hostOwner = $this->makePartnerAdmin($this->host, [], ['email' => 'owner@example.test']);

        $this->target = $this->makeWhiteLabelPartner(['company_name' => 'Demo Co Name', 'email' => 'demo@example.test']);
        $this->makePartnerAdmin($this->target, [], ['email' => 'demo@example.test']);
        $this->targetStaff = $this->makeStaffUser($this->target, [], ['email' => 'demo-staff@example.test']);
        $this->otherPartner = $this->makeWhiteLabelPartner(['company_name' => 'Kept Partner', 'email' => 'kept-partner@example.test']);

        $this->kept = $this->clientWithDeal($this->host, 'Michael', 'michael@example.test');
        $this->listed = $this->clientWithDeal($this->otherPartner, 'Sandy', 'sandy@example.test');
        $this->targetClients = [
            $this->clientWithDeal($this->target, 'Big', 'big@example.test'),
            $this->clientWithDeal($this->target, 'Active', 'active@example.test'),
        ];

        // The partner's own book and settings.
        $invoice = $this->invoice(['partner_id' => $this->target->id, 'entity_type' => Partner::class, 'entity_id' => $this->target->id]);
        $this->payment($invoice);
        DB::table('subscriptions')->insert($this->stamps(['entity_type' => Partner::class, 'entity_id' => $this->target->id, 'status' => 'active']));
        DB::table('leads')->insert($this->stamps(['partner_id' => $this->target->id, 'company_name' => 'Lead Co', 'email' => 'lead@example.test']));
        DB::table('app_configurations')->insert($this->stamps(['partner_id' => $this->target->id, 'key' => 'k', 'module' => 'm', 'value' => 'v']));
        $roleId = DB::table('roles')->insertGetId($this->stamps(['partner_id' => $this->target->id, 'name' => 'Demo Sales ' . Str::random(4), 'guard_name' => 'api']));
        DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $this->targetStaff->id]);
        DB::table('activity_log')->insert($this->stamps(['log_name' => 'default', 'description' => 'partner updated', 'subject_type' => Partner::class, 'subject_id' => $this->target->id, 'causer_type' => User::class, 'causer_id' => $this->targetStaff->id]));
        Storage::disk(Disk::UPLOADS)->put("partners/{$this->target->id}/agreement.pdf", 'pdf');
        DB::table('partner_documents')->insert($this->stamps(['partner_id' => $this->target->id, 'document_name' => 'Agreement', 'file_path' => 'agreement.pdf']));
        $this->targetStaff->createToken('test');
    }

    // ----------------------------------------------------------------- setup

    /** @param array<string, mixed> $row */
    private function stamps(array $row): array
    {
        return $row + ['created_at' => now(), 'updated_at' => now()];
    }

    private function invoice(array $attributes): Invoice
    {
        return Invoice::forceCreate(array_merge([
            'invoice_id' => 'INV-' . Str::random(8),
            'currency' => 'USD',
            'currency_symbol' => '$',
            'payment_amount' => 100,
            'status' => Invoice::STATUS_PAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ], $attributes));
    }

    private function payment(Invoice $invoice, array $attributes = []): Payment
    {
        return Payment::forceCreate(array_merge([
            'partner_id' => $invoice->partner_id,
            'borrower_id' => $invoice->borrower_id,
            'invoice_id' => $invoice->id,
            'entity_type' => $invoice->entity_type,
            'entity_id' => $invoice->entity_id,
            'transaction_id' => 'TX_' . Str::random(8),
            'amount' => 100,
            'status' => Payment::STATUS_SUCCEEDED,
            'transaction_date' => now(),
        ], $attributes));
    }

    /** A client with a login, a funding request, an application, a commission paid out, invoices, documents and files. */
    private function clientWithDeal(Partner $partner, string $name, string $email): Borrower
    {
        $user = $this->makeBorrowerUser($partner, ['first_name' => $name, 'last_name' => 'Test', 'email' => $email], ['email' => $email]);
        $borrower = Borrower::findOrFail($user->entity_id);

        $request = FundingRequest::create([
            'partner_id' => $partner->id,
            'funding_sequence_id' => DB::table('funding_sequencings')->insertGetId($this->stamps(['name' => 'Seq ' . Str::random(4), 'status' => 'Active'])),
            'borrower_id' => $borrower->id,
            'funding_type' => 'Revenue Based Financing',
            'requested_amount' => 40000,
            'use_of_funds' => 'Working Capital',
            'status' => FundingRequest::STATUS_APPROVED,
        ]);
        $application = FundingApplication::forceCreate([
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'funding_request_id' => $request->id,
            'full_name' => "{$name} Test",
            'status' => FundingApplication::CLOSED_COMPLETED,
            'requested_amount' => 40000,
        ]);
        $this->applicationIds[$email] = $application->id;
        DB::table('funding_application_co_borrowers')->insert($this->stamps(['funding_application_id' => $application->id, 'first_name' => 'Co', 'last_name' => 'Borrower']));

        $commission = Commission::create([
            'funding_application_id' => $application->id,
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'total_commission' => 10,
            'admin_commission' => 50,
            'partner_commission' => 50,
            'total_commission_amount' => 4000,
            'admin_commission_amount' => 2000,
            'partner_commission_amount' => 2000,
            'commission_status' => Commission::STATUS_COMPLETED,
            'payment_status' => Commission::PAYMENT_STATUS_PAID,
        ]);
        $payoutInvoice = $this->invoice(['partner_id' => $partner->id, 'entity_type' => Partner::class, 'entity_id' => $partner->id, 'type' => Invoice::INVOICE_TYPE_COMMISSION_PAYOUT]);
        $payoutPayment = $this->payment($payoutInvoice);
        DB::table('commission_payouts')->insert($this->stamps([
            'commission_id' => $commission->id, 'entity_type' => Partner::class, 'entity_id' => $partner->id,
            'name' => 'Payee', 'email' => 'payee@example.test', 'payout_type' => 'partner',
            'invoice_id' => $payoutInvoice->id, 'payment_id' => $payoutPayment->id, 'currency' => 'USD', 'paid_at' => now(),
        ]));

        $invoice = $this->invoice(['partner_id' => $partner->id, 'borrower_id' => $borrower->id, 'entity_type' => Borrower::class, 'entity_id' => $borrower->id]);
        $this->payment($invoice);

        Storage::disk(Disk::UPLOADS)->put("borrowers/{$borrower->id}/license.pdf", 'pdf');
        DB::table('borrower_documents')->insert($this->stamps(['borrower_id' => $borrower->id, 'file_name' => 'license.pdf', 'file_path' => 'license.pdf']));
        Storage::disk(Disk::UPLOADS)->put("funding-applications/{$application->id}/bank.pdf", 'pdf');
        DB::table('funding_application_documents')->insert($this->stamps([
            'funding_application_id' => $application->id, 'borrower_id' => $borrower->id, 'name' => 'Bank',
            'path' => "funding-applications/{$application->id}/bank.pdf", 'disk' => Disk::UPLOADS,
        ]));
        // The signed copy is content-addressed (audit/files/{hash}.pdf) and named by its audit event.
        $hash = hash('sha256', $email);
        Storage::disk(Disk::UPLOADS)->put("audit/files/{$hash}.pdf", 'signed');
        DB::table('funding_application_audit_events')->insert([
            'funding_application_id' => $application->id, 'partner_id' => $partner->id, 'borrower_id' => $borrower->id,
            'event' => 'signed', 'occurred_at' => now(), 'hash' => Str::random(64),
            'file_hash' => $hash, 'file_path' => "audit/files/{$hash}.pdf",
        ]);

        DB::table('notifications')->insert($this->stamps(['id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => '{}']));
        DB::table('activity_log')->insert($this->stamps(['log_name' => 'default', 'description' => 'client created', 'subject_type' => Borrower::class, 'subject_id' => $borrower->id]));
        DB::table('activity_log')->insert($this->stamps(['log_name' => 'default', 'description' => 'application submitted', 'subject_type' => FundingApplication::class, 'subject_id' => $application->id]));

        return $borrower;
    }

    private function purge(string $type, array $emails, array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('records:purge', array_merge(['--type' => $type, '--email' => $emails], $options));
    }

    /** @return list<int> */
    private function targetClientIds(): array
    {
        return array_map(fn (Borrower $b) => (int) $b->id, $this->targetClients);
    }

    private function assertPartnerGone(): void
    {
        $gone = $this->targetClientIds();

        $this->assertNull(Partner::withTrashed()->find($this->target->id));
        $this->assertSame(0, Borrower::withTrashed()->whereIn('id', $gone)->count());
        $this->assertSame(0, User::where('partner_id', $this->target->id)->count());
        $this->assertSame(0, FundingApplication::withTrashed()->whereIn('borrower_id', $gone)->count());
        $this->assertSame(0, FundingRequest::withTrashed()->whereIn('borrower_id', $gone)->count());
        $this->assertSame(0, Commission::withTrashed()->whereIn('borrower_id', $gone)->count());
        $this->assertSame(0, Invoice::withTrashed()->where('partner_id', $this->target->id)->count());
        $this->assertSame(0, Payment::withTrashed()->where('partner_id', $this->target->id)->count());
        $this->assertSame(0, DB::table('roles')->where('partner_id', $this->target->id)->count());
        $this->assertSame(0, DB::table('leads')->where('partner_id', $this->target->id)->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $this->targetStaff->id)->count());
        $this->assertSame(0, DB::table('activity_log')->where('subject_type', Partner::class)->where('subject_id', $this->target->id)->count());
        Storage::disk(Disk::UPLOADS)->assertMissing("partners/{$this->target->id}/agreement.pdf");
        Storage::disk(Disk::UPLOADS)->assertMissing('audit/files/' . hash('sha256', 'big@example.test') . '.pdf');
    }

    private function assertKeptIntact(): void
    {
        $this->assertNotNull(Partner::find($this->host->id));
        $this->assertNotNull(Partner::find($this->otherPartner->id));
        $this->assertNotNull(User::find($this->hostOwner->id));
        $this->assertNotNull(Borrower::find($this->kept->id));
        $this->assertSame(1, FundingApplication::where('borrower_id', $this->kept->id)->count());
        $this->assertSame(1, Commission::where('borrower_id', $this->kept->id)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', User::where('entity_type', Borrower::class)->where('entity_id', $this->kept->id)->value('id'))->count());
        Storage::disk(Disk::UPLOADS)->assertExists("borrowers/{$this->kept->id}/license.pdf");
        Storage::disk(Disk::UPLOADS)->assertExists('audit/files/' . hash('sha256', 'michael@example.test') . '.pdf');
    }

    // ----------------------------------------------------------------- flow

    #[Test]
    public function it_asks_what_to_delete_the_emails_and_full_or_partial_then_lists_before_asking_to_confirm(): void
    {
        $this->artisan('records:purge')
            ->expectsChoice(PurgeRecords::QUESTION_TYPE, RecordPurgeService::TYPE_BORROWER, RecordPurgeService::TYPES)
            ->expectsQuestion(PurgeRecords::QUESTION_EMAIL, 'sandy@example.test')
            ->expectsChoice(PurgeRecords::QUESTION_MODE, RecordPurgeService::MODE_FULL, RecordPurgeService::MODES)
            ->expectsOutputToContain('sandy@example.test')
            ->expectsConfirmation(PurgeRecords::CONFIRMATION_QUESTION, 'yes')
            ->assertSuccessful();

        $this->assertNull(Borrower::withTrashed()->find($this->listed->id));
        $this->assertKeptIntact();
    }

    #[Test]
    public function full_or_partial_is_only_asked_for_a_borrower(): void
    {
        $this->artisan('records:purge')
            ->expectsChoice(PurgeRecords::QUESTION_TYPE, RecordPurgeService::TYPE_USER, RecordPurgeService::TYPES)
            ->expectsQuestion(PurgeRecords::QUESTION_EMAIL, 'demo-staff@example.test')
            ->expectsConfirmation(PurgeRecords::CONFIRMATION_QUESTION, 'no')
            ->assertSuccessful();
    }

    #[Test]
    public function the_list_comes_first_and_no_stops_with_nothing_deleted(): void
    {
        $this->purge(RecordPurgeService::TYPE_PARTNER, ['demo@example.test'])
            ->expectsOutputToContain('Demo Co Name')
            ->expectsOutputToContain('These borrowers will also be fully deleted')
            ->expectsOutputToContain('big@example.test')
            ->expectsOutputToContain('Leftover references: none')
            ->expectsConfirmation(PurgeRecords::CONFIRMATION_QUESTION, 'no')
            ->expectsOutputToContain('Stopped')
            ->assertSuccessful();

        $this->assertNotNull(Partner::find($this->target->id));
        $this->assertSame(2, Borrower::whereIn('id', $this->targetClientIds())->count());
        $this->assertKeptIntact();
    }

    #[Test]
    public function dry_run_only_lists_and_never_asks(): void
    {
        $this->purge(RecordPurgeService::TYPE_PARTNER, ['demo@example.test'], ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertNotNull(Partner::find($this->target->id));
    }

    #[Test]
    public function it_asks_yes_or_no_and_says_how_many_will_go(): void
    {
        $this->purge(RecordPurgeService::TYPE_PARTNER, ['demo@example.test'])
            ->expectsOutputToContain('This will force-delete 1 partner(s) and everything listed above.')
            ->expectsConfirmation(PurgeRecords::CONFIRMATION_QUESTION, 'no')
            ->assertSuccessful();

        $this->assertNotNull(Partner::find($this->target->id));
    }

    // ----------------------------------------------------------------- full

    #[Test]
    public function a_partner_full_delete_takes_the_partner_its_clients_and_all_their_data(): void
    {
        $this->purge(RecordPurgeService::TYPE_PARTNER, ['demo@example.test'])
            ->expectsConfirmation(PurgeRecords::CONFIRMATION_QUESTION, 'yes')
            ->expectsOutputToContain('Leftover references: none')
            ->assertSuccessful();

        $this->assertPartnerGone();
        $this->assertKeptIntact();
        $this->assertNotNull(Borrower::find($this->listed->id), 'a client of another partner stays');
    }

    #[Test]
    public function a_borrower_full_delete_takes_the_client_and_all_of_theirs(): void
    {
        $this->purge(RecordPurgeService::TYPE_BORROWER, ['sandy@example.test'], ['--mode' => RecordPurgeService::MODE_FULL])
            ->expectsConfirmation(PurgeRecords::CONFIRMATION_QUESTION, 'yes')
            ->assertSuccessful();

        $id = $this->listed->id;
        $this->assertNull(Borrower::withTrashed()->find($id));
        $this->assertSame(0, User::where('entity_type', Borrower::class)->where('entity_id', $id)->count());
        $this->assertSame(0, FundingApplication::withTrashed()->where('borrower_id', $id)->count());
        $this->assertSame(0, Invoice::withTrashed()->where('borrower_id', $id)->count());
        $this->assertSame(0, Payment::withTrashed()->where('borrower_id', $id)->count());
        $this->assertSame(0, DB::table('borrower_documents')->where('borrower_id', $id)->count());
        Storage::disk(Disk::UPLOADS)->assertMissing("borrowers/{$id}/license.pdf");
        Storage::disk(Disk::UPLOADS)->assertMissing("funding-applications/{$this->applicationIds['sandy@example.test']}/bank.pdf");
        $this->assertNotNull(Partner::find($this->otherPartner->id), 'its partner stays');
        $this->assertKeptIntact();
    }

    #[Test]
    public function a_user_full_delete_takes_the_login_and_what_is_its_own(): void
    {
        $this->purge(RecordPurgeService::TYPE_USER, ['demo-staff@example.test'])
            ->expectsConfirmation(PurgeRecords::CONFIRMATION_QUESTION, 'yes')
            ->assertSuccessful();

        $this->assertNull(User::find($this->targetStaff->id));
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $this->targetStaff->id)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $this->targetStaff->id)->count());
        $this->assertSame(0, DB::table('employees')->where('id', $this->targetStaff->entity_id)->count());
        $this->assertNotNull(Partner::find($this->target->id), 'its partner stays');
        $this->assertSame(2, Borrower::whereIn('id', $this->targetClientIds())->count());
    }

    #[Test]
    public function soft_deleted_records_are_force_deleted_too(): void
    {
        $this->listed->delete();

        $this->purge(RecordPurgeService::TYPE_BORROWER, ['sandy@example.test'], ['--mode' => RecordPurgeService::MODE_FULL])
            ->expectsConfirmation(PurgeRecords::CONFIRMATION_QUESTION, 'yes')
            ->assertSuccessful();

        $this->assertNull(Borrower::withTrashed()->find($this->listed->id));
    }

    #[Test]
    public function the_report_holds_ids_and_counts_but_no_names_or_emails(): void
    {
        $this->purge(RecordPurgeService::TYPE_PARTNER, ['demo@example.test'])
            ->expectsConfirmation(PurgeRecords::CONFIRMATION_QUESTION, 'yes')
            ->assertSuccessful();

        $report = Storage::disk(Disk::LOCAL)->get(Storage::disk(Disk::LOCAL)->files(RecordPurgeService::REPORT_FOLDER)[0]);
        $this->assertStringContainsString((string) $this->target->id, $report);
        $this->assertStringNotContainsString('@example.test', $report);
        $this->assertStringNotContainsString('Demo Co Name', $report);
    }

    // -------------------------------------------------------------- partial

    #[Test]
    public function a_borrower_partial_delete_takes_only_the_deals_and_their_commission_money(): void
    {
        $id = $this->listed->id;
        $applicationId = $this->applicationIds['sandy@example.test'];
        $commissionId = Commission::where('borrower_id', $id)->value('id');
        $payout = DB::table('commission_payouts')->where('commission_id', $commissionId)->first();
        $enrollment = Invoice::where('borrower_id', $id)->where('type', Invoice::INVOICE_TYPE_ENROLLMENT)->firstOrFail();

        $this->purge(RecordPurgeService::TYPE_BORROWER, ['sandy@example.test'], ['--mode' => RecordPurgeService::MODE_PARTIAL])
            ->expectsConfirmation(PurgeRecords::CONFIRMATION_QUESTION, 'yes')
            ->expectsOutputToContain('Leftover references: none')
            ->assertSuccessful();

        // Gone: the deals, their activity and audit trail, the commission and its payout money.
        $this->assertSame(0, FundingRequest::withTrashed()->where('borrower_id', $id)->count());
        $this->assertSame(0, FundingApplication::withTrashed()->where('borrower_id', $id)->count());
        $this->assertSame(0, DB::table('funding_application_documents')->where('funding_application_id', $applicationId)->count());
        $this->assertSame(0, DB::table('funding_application_co_borrowers')->where('funding_application_id', $applicationId)->count());
        $this->assertSame(0, DB::table('funding_application_audit_events')->where('funding_application_id', $applicationId)->count());
        $this->assertSame(0, DB::table('activity_log')->where('subject_type', FundingApplication::class)->where('subject_id', $applicationId)->count());
        $this->assertSame(0, Commission::withTrashed()->where('id', $commissionId)->count());
        $this->assertSame(0, DB::table('commission_payouts')->where('commission_id', $commissionId)->count());
        $this->assertNull(Invoice::withTrashed()->find($payout->invoice_id));
        $this->assertNull(Payment::withTrashed()->find($payout->payment_id));
        Storage::disk(Disk::UPLOADS)->assertMissing("funding-applications/{$applicationId}/bank.pdf");
        Storage::disk(Disk::UPLOADS)->assertMissing('audit/files/' . hash('sha256', 'sandy@example.test') . '.pdf');

        // Kept: the client, its login, its enrollment invoice and payment, its documents and its own activity.
        $this->assertNotNull(Borrower::find($id));
        $this->assertSame(1, User::where('entity_type', Borrower::class)->where('entity_id', $id)->count());
        $this->assertNotNull(Invoice::find($enrollment->id));
        $this->assertSame(1, Payment::where('invoice_id', $enrollment->id)->count());
        $this->assertSame(1, DB::table('borrower_documents')->where('borrower_id', $id)->count());
        $this->assertSame(1, DB::table('activity_log')->where('subject_type', Borrower::class)->where('subject_id', $id)->count());
        Storage::disk(Disk::UPLOADS)->assertExists("borrowers/{$id}/license.pdf");
        $this->assertKeptIntact();
    }

    #[Test]
    public function partial_is_refused_for_a_partner_or_a_user(): void
    {
        $this->purge(RecordPurgeService::TYPE_PARTNER, ['demo@example.test'], ['--mode' => RecordPurgeService::MODE_PARTIAL])
            ->expectsOutputToContain('only for a borrower')
            ->assertFailed();

        $this->purge(RecordPurgeService::TYPE_USER, ['demo-staff@example.test'], ['--mode' => RecordPurgeService::MODE_PARTIAL])
            ->expectsOutputToContain('only for a borrower')
            ->assertFailed();

        $this->assertNotNull(Partner::find($this->target->id));
        $this->assertNotNull(User::find($this->targetStaff->id));
    }

    // --------------------------------------------------------------- guards

    #[Test]
    public function the_host_partner_and_its_own_login_are_refused(): void
    {
        $this->purge(RecordPurgeService::TYPE_PARTNER, ['host@example.test'])
            ->expectsOutputToContain('host partner')
            ->assertFailed();

        $this->purge(RecordPurgeService::TYPE_USER, ['owner@example.test'])
            ->expectsOutputToContain('host partner')
            ->assertFailed();

        $this->assertNotNull(Partner::find($this->host->id));
        $this->assertNotNull(User::find($this->hostOwner->id));
    }

    #[Test]
    public function an_unknown_email_is_reported_and_skipped(): void
    {
        $this->purge(RecordPurgeService::TYPE_BORROWER, ['sandy@example.test', 'nobody@example.test'], ['--mode' => RecordPurgeService::MODE_FULL, '--dry-run' => true])
            ->expectsOutputToContain('Not found: nobody@example.test')
            ->assertSuccessful();
    }

    #[Test]
    public function a_kept_client_of_a_deleted_partner_blocks_the_run(): void
    {
        $this->purge(RecordPurgeService::TYPE_PARTNER, ['demo@example.test'], ['--keep-email' => ['big@example.test']])
            ->expectsOutputToContain('Blocker: kept client big@example.test')
            ->assertFailed();

        $this->assertNotNull(Partner::find($this->target->id));
    }

    #[Test]
    public function a_kept_row_pointing_at_a_deleted_user_blocks_until_reassigned(): void
    {
        // The partner's staff created a commission on a kept deal (commissions.created_by is RESTRICT).
        Commission::where('borrower_id', $this->kept->id)->update(['created_by' => $this->targetStaff->id]);

        $this->purge(RecordPurgeService::TYPE_PARTNER, ['demo@example.test'])
            ->expectsOutputToContain('Blocker: 1 kept row(s) in commissions.created_by')
            ->assertFailed();
        $this->assertNotNull(Partner::find($this->target->id));

        $this->purge(RecordPurgeService::TYPE_PARTNER, ['demo@example.test'], ['--reassign-to' => 'owner@example.test'])
            ->expectsConfirmation(PurgeRecords::CONFIRMATION_QUESTION, 'yes')
            ->assertSuccessful();

        $this->assertPartnerGone();
        $this->assertSame($this->hostOwner->id, (int) Commission::where('borrower_id', $this->kept->id)->value('created_by'));
    }
}
