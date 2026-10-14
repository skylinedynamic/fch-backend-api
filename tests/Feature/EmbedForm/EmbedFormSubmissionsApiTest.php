<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Controllers\Api\V1\FCH\Common\EmbedForm\EmbedFormController;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    #[Test]
    public function an_allowed_domain_must_be_a_url(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson('/api/v1/common/embed-forms/config', ['allowed_domains' => ['not a url']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allowed_domains.0');
    }
}
