<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Controllers\Api\V1\FCH\Common\EmbedForm\EmbedFormController;
use App\Models\Borrower\Borrower;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The partner portal's Embedded Form inbox: /common/embed-forms/submissions*.
 * Applications that came in through a partner's embedded form are that
 * partner's - the host's team sees every partner's.
 */
#[CoversClass(EmbedFormController::class)]
class EmbedFormSubmissionsApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private Partner $otherPartner;
    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->otherPartner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner, self::PERMISSIONS);
    }

    /** What the embed-forms routes are gated on (routes/common.php). */
    private const PERMISSIONS = ['view configuration', 'update configuration', 'view application', 'convert application'];

    private function submissionFor(Partner $partner, array $attributes = []): int
    {
        return DB::table('embedded_form_submissions')->insertGetId(array_merge([
            'submission_uid' => (string) \Illuminate\Support\Str::uuid(),
            'partner_id' => $partner->id,
            'first_name' => 'Ann',
            'last_name' => 'Applicant',
            'email' => 'ann-' . uniqid() . '@example.test',
            'status' => EmbedFormSubmission::STATUS_NEW,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    #[Test]
    public function the_inbox_holds_my_partners_submissions_and_the_host_sees_all(): void
    {
        $mine = $this->submissionFor($this->partner);
        $this->submissionFor($this->otherPartner);

        $this->actingAsUser($this->partnerAdmin);
        $ids = collect($this->postJson('/api/v1/common/embed-forms/submissions/fetch')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$mine], $ids->all());

        $this->actingAsUser($this->makePartnerAdmin($this->host, self::PERMISSIONS));
        $this->assertCount(2, $this->postJson('/api/v1/common/embed-forms/submissions/fetch')->assertOk()->json('data'));
    }

    /**
     * Defect (BUG-009, OP-EMB-11): clicking Applicant, Contact Info or Desired
     * Funding did nothing - the list was always newest first, whatever the sort.
     */
    #[Test]
    public function the_inbox_sorts_by_each_sortable_column_in_both_directions(): void
    {
        $zed = $this->submissionFor($this->partner, ['first_name' => 'Zed', 'email' => 'a@example.test', 'desired_funding_amount' => 5000, 'status' => EmbedFormSubmission::STATUS_REVIEWING, 'created_at' => now()->subDay()]);
        $amy = $this->submissionFor($this->partner, ['first_name' => 'Amy', 'email' => 'z@example.test', 'desired_funding_amount' => 50000, 'status' => EmbedFormSubmission::STATUS_NEW, 'created_at' => now()]);
        $this->actingAsUser($this->partnerAdmin);

        $sorted = fn (string $field, string $order) => collect($this->postJson('/api/v1/common/embed-forms/submissions/fetch', [
            'sort_field' => $field,
            'sort_order' => $order,
        ])->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$amy, $zed], $sorted('first_name', 'asc'));
        $this->assertSame([$zed, $amy], $sorted('first_name', 'desc'));
        $this->assertSame([$zed, $amy], $sorted('email', 'asc'));
        $this->assertSame([$amy, $zed], $sorted('email', 'desc'));
        $this->assertSame([$zed, $amy], $sorted('desired_funding_amount', 'asc'));
        $this->assertSame([$amy, $zed], $sorted('desired_funding_amount', 'desc'));
        $this->assertSame([$amy, $zed], $sorted('status', 'asc'));
        $this->assertSame([$zed, $amy], $sorted('created_at', 'asc'));
        $this->assertSame([$amy, $zed], $sorted('created_at', 'desc'));
    }

    /**
     * Defect: Desired Funding asc/desc did nothing on the host's Loan
     * Applications. Since the funding-application rewrite a submission stores
     * requested_amount and leaves desired_funding_amount null; the column shows
     * requested_amount, but the sort only read the (null) legacy column.
     */
    #[Test]
    public function desired_funding_sorts_by_the_amount_the_column_shows(): void
    {
        $small = $this->submissionFor($this->partner, ['desired_funding_amount' => null, 'requested_amount' => 20000, 'created_at' => now()->subDays(2)]);
        $large = $this->submissionFor($this->partner, ['desired_funding_amount' => null, 'requested_amount' => 80000, 'created_at' => now()]);
        $legacy = $this->submissionFor($this->partner, ['desired_funding_amount' => 40000, 'requested_amount' => null, 'created_at' => now()->subDay()]);
        $this->actingAsUser($this->partnerAdmin);

        $sorted = fn (string $order) => collect($this->postJson('/api/v1/common/embed-forms/submissions/fetch', [
            'sort_field' => 'desired_funding_amount',
            'sort_order' => $order,
        ])->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$small, $legacy, $large], $sorted('asc'));
        $this->assertSame([$large, $legacy, $small], $sorted('desc'));
    }

    /**
     * BUG-191 (decision D6): amounts in different currencies were sorted by
     * face value, so £15,000 sat between $6,546 and $20,000. They are grouped
     * by currency in the form's order (USD, EUR, GBP) - an amount with no
     * currency is the form's default - and sorted by amount within each.
     */
    #[Test]
    public function desired_funding_sorts_by_currency_then_amount(): void
    {
        $usdSmall = $this->submissionFor($this->partner, ['requested_amount' => 6546, 'currency' => 'USD']);
        $gbp = $this->submissionFor($this->partner, ['requested_amount' => 15000, 'currency' => 'GBP']);
        $usdLarge = $this->submissionFor($this->partner, ['requested_amount' => 25000, 'currency' => 'USD']);
        $eur = $this->submissionFor($this->partner, ['requested_amount' => 9000, 'currency' => 'EUR']);
        $untyped = $this->submissionFor($this->partner, ['requested_amount' => 20000, 'currency' => null]);
        $this->actingAsUser($this->partnerAdmin);

        $sorted = fn (string $order) => collect($this->postJson('/api/v1/common/embed-forms/submissions/fetch', [
            'sort_field' => 'desired_funding_amount',
            'sort_order' => $order,
        ])->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$usdSmall, $untyped, $usdLarge, $eur, $gbp], $sorted('asc'));
        $this->assertSame([$usdLarge, $untyped, $usdSmall, $eur, $gbp], $sorted('desc'));
    }

    #[Test]
    public function an_unknown_sort_field_falls_back_to_newest_first(): void
    {
        $older = $this->submissionFor($this->partner, ['created_at' => now()->subDay()]);
        $newer = $this->submissionFor($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/embed-forms/submissions/fetch', ['sort_field' => 'ssn; drop table users', 'sort_order' => 'asc'])
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer)
            ->assertJsonPath('data.1.id', $older);
    }

    #[Test]
    public function i_open_and_move_my_partners_submission(): void
    {
        $id = $this->submissionFor($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/embed-forms/submissions/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->putJson("/api/v1/common/embed-forms/submissions/{$id}/status", ['status' => EmbedFormSubmission::STATUS_REVIEWING])->assertOk();

        $this->assertSame(EmbedFormSubmission::STATUS_REVIEWING, DB::table('embedded_form_submissions')->where('id', $id)->value('status'));
    }

    #[Test]
    public function a_status_must_be_a_known_one(): void
    {
        $id = $this->submissionFor($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson("/api/v1/common/embed-forms/submissions/{$id}/status", ['status' => 'lost'])->assertStatus(422);
    }

    #[Test]
    public function another_partners_submission_is_out_of_reach(): void
    {
        $theirs = $this->submissionFor($this->otherPartner);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/embed-forms/submissions/{$theirs}")->assertForbidden();
        $this->putJson("/api/v1/common/embed-forms/submissions/{$theirs}/status", ['status' => EmbedFormSubmission::STATUS_REJECTED])->assertForbidden();
        $this->assertNotSame(200, $this->postJson("/api/v1/common/embed-forms/submissions/{$theirs}/convert-to-borrower")->status());

        $this->assertSame(EmbedFormSubmission::STATUS_NEW, DB::table('embedded_form_submissions')->where('id', $theirs)->value('status'));
        $this->assertSame(0, DB::table('borrowers')->where('partner_id', $this->partner->id)->count());
    }

    /**
     * BUG-098: a Rejected submission could still be converted, and ended up
     * Converted. It has to be moved out of Rejected first.
     */
    #[Test]
    public function a_rejected_submission_cannot_be_converted_or_previewed(): void
    {
        $rejected = $this->submissionFor($this->partner, ['status' => EmbedFormSubmission::STATUS_REJECTED]);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/embed-forms/submissions/{$rejected}/conversion-preview")
            ->assertStatus(422)
            ->assertJsonPath('message', __('embed_form.rejected_cannot_convert'));
        $this->postJson("/api/v1/common/embed-forms/submissions/{$rejected}/convert-to-borrower")
            ->assertStatus(422)
            ->assertJsonPath('message', __('embed_form.rejected_cannot_convert'));

        $this->assertSame(EmbedFormSubmission::STATUS_REJECTED, DB::table('embedded_form_submissions')->where('id', $rejected)->value('status'));
        $this->assertSame(0, DB::table('borrowers')->where('partner_id', $this->partner->id)->count());
    }

    #[Test]
    public function an_unknown_submission_is_not_found(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson('/api/v1/common/embed-forms/submissions/999999')->assertNotFound();
    }

    #[Test]
    public function another_partners_attachment_cannot_be_downloaded(): void
    {
        $theirs = $this->submissionFor($this->otherPartner);
        $attachmentId = DB::table('embedded_form_attachments')->insertGetId([
            'submission_id' => $theirs,
            'partner_id' => $this->otherPartner->id,
            'field_name' => 'bank_statement_document',
            'file_path' => 'embed/statement.pdf',
            'original_name' => 'statement.pdf',
            'created_at' => now(),
        ]);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/embed-forms/attachments/{$attachmentId}/download")->assertForbidden();
    }

    #[Test]
    public function the_form_settings_load_for_my_partner(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson('/api/v1/common/embed-forms/config')->assertSuccessful();
    }

    #[Test]
    public function i_change_and_rekey_only_my_partners_form(): void
    {
        $theirs = EmbedFormConfig::create(['partner_id' => $this->otherPartner->id, 'public_key' => 'their-key', 'form_title' => 'Theirs', 'is_active' => true]);
        $mine = EmbedFormConfig::create(['partner_id' => $this->partner->id, 'public_key' => 'my-key', 'form_title' => 'Mine', 'is_active' => true]);
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson('/api/v1/common/embed-forms/config', [
            'form_title' => 'Apply now',
            'allowed_domains' => ['https://mine.example.org'],
            'is_active' => false,
        ])->assertOk();
        $key = $this->postJson('/api/v1/common/embed-forms/config/generate-key')->assertOk()->json('data.public_key');

        $mine->refresh();
        $this->assertSame('Apply now', $mine->form_title);
        $this->assertFalse((bool) $mine->is_active);
        $this->assertSame($key, $mine->public_key);
        $this->assertNotSame('my-key', $key);
        $this->assertSame(['their-key', 'Theirs'], [$theirs->fresh()->public_key, $theirs->fresh()->form_title]);
    }

    /**
     * notification_email was saved but never used: new-submission mail goes to
     * the partner's own email, and the portal never sent the field. The column
     * and the setting are removed.
     */
    #[Test]
    public function the_form_has_no_notification_email_setting(): void
    {
        EmbedFormConfig::create(['partner_id' => $this->partner->id, 'public_key' => 'my-key', 'form_title' => 'Mine', 'is_active' => true]);
        $this->actingAsUser($this->partnerAdmin);

        $this->assertFalse(Schema::hasColumn('embedded_form_configs', 'notification_email'));
        $this->assertArrayNotHasKey('notification_email', $this->getJson('/api/v1/common/embed-forms/config')->assertOk()->json('data'));
        // Sent anyway, it is neither validated nor stored.
        $this->putJson('/api/v1/common/embed-forms/config', ['notification_email' => 'not-an-email'])->assertOk();
    }

    #[Test]
    public function an_allowed_domain_must_be_a_domain(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson('/api/v1/common/embed-forms/config', ['allowed_domains' => ['not a url']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allowed_domains.0');
    }

    /**
     * BUG-058 (retest): the Loan Applications list labelled a submission
     * converted onto a paid client "Free Plan" - the list endpoint never sent
     * the client it was converted onto, so the portal could not know its plan.
     */
    #[Test]
    public function the_list_sends_the_client_a_submission_was_converted_onto_with_its_plan(): void
    {
        $client = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Owner',
            'last_name' => 'Client Two',
            'email' => 'client-two@example.test',
            'is_free_trail' => false,
        ]);
        Subscription::create([
            'entity_type' => Borrower::class,
            'entity_id' => $client->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->submissionFor($this->partner, [
            'status' => EmbedFormSubmission::STATUS_CONVERTED,
            'converted_borrower_id' => $client->id,
        ]);

        $this->actingAsUser($this->partnerAdmin);
        $row = $this->postJson('/api/v1/common/embed-forms/submissions/fetch')->assertOk()->json('data.0');

        $this->assertSame('Ann Applicant', trim($row['first_name'] . ' ' . $row['last_name']));
        $this->assertSame($client->id, $row['converted_borrower']['id'] ?? null);
        $this->assertFalse($row['converted_borrower']['is_free_trail']);
        $this->assertSame(SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT, $row['converted_borrower']['enrollment_type']);
    }
}
