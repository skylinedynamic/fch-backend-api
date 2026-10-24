<?php

namespace Tests\Feature\Application;

use App\Http\Controllers\Api\V1\FCH\Common\Application\RequestApplicationController;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Application\PreQualificationAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Intake & Review > Pre-Qualification > Update Status. A partner (and its
 * staff) changes the status of its own pre-qualifications only; the host's
 * team may change any partner's. Same cases as stage's
 * RequestApplicationReviewTest, limited to pre-qualification.
 */
#[CoversClass(RequestApplicationController::class)]
class PreQualificationStatusAccessTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private Partner $otherPartner;
    private User $partnerAdmin;
    private int $myPreQualId;
    private int $theirPreQualId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->otherPartner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);

        $myBorrower = $this->makeBorrower($this->partner);
        $theirBorrower = $this->makeBorrower($this->otherPartner);
        $this->myPreQualId = $this->preQualFor($myBorrower->id, $this->partner->id);
        $this->theirPreQualId = $this->preQualFor($theirBorrower->id, $this->otherPartner->id);
    }

    private function preQualFor(int $borrowerId, int $partnerId): int
    {
        return DB::table('pre_qualification_requests')->insertGetId([
            'borrower_id' => $borrowerId,
            'partner_id' => $partnerId,
            'status' => PreQualificationRequest::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function updateStatus(int $id, string $status, ?string $note = null)
    {
        return $this->postJson('/api/v1/common/request-application/pre-qualification/status-update', array_filter([
            'id' => $id,
            'status' => $status,
            'status_note' => $note,
        ]));
    }

    private function statusOf(int $id): ?string
    {
        return DB::table('pre_qualification_requests')->where('id', $id)->value('status');
    }

    #[Test]
    public function a_partner_approves_and_declines_its_own_pre_qualification(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->updateStatus($this->myPreQualId, PreQualificationRequest::APPROVED, 'Looks good')->assertOk();
        $this->assertSame(PreQualificationRequest::APPROVED, $this->statusOf($this->myPreQualId));
        $this->assertSame('Looks good', DB::table('pre_qualification_requests')->where('id', $this->myPreQualId)->value('status_note'));

        $this->updateStatus($this->myPreQualId, PreQualificationRequest::DECLINED)->assertOk();
        $this->assertSame(PreQualificationRequest::DECLINED, $this->statusOf($this->myPreQualId));
    }

    /** Before this fix the record was looked up by id alone, so any partner could change any other partner's. */
    #[Test]
    public function a_partner_cannot_change_another_partners_pre_qualification(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->updateStatus($this->theirPreQualId, PreQualificationRequest::DECLINED)->assertJsonPath('status', false);

        $this->assertSame(PreQualificationRequest::PENDING, $this->statusOf($this->theirPreQualId));
    }

    #[Test]
    public function the_host_team_can_change_any_partners_pre_qualification(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->updateStatus($this->theirPreQualId, PreQualificationRequest::APPROVED)->assertOk();

        $this->assertSame(PreQualificationRequest::APPROVED, $this->statusOf($this->theirPreQualId));
    }

    /** Re-run produces the internal memo and lender routing, so only the host may start it. */
    #[Test]
    public function the_host_can_re_run_any_partners_pre_qualification(): void
    {
        $this->mock(PreQualificationAiService::class, fn ($mock) => $mock->shouldReceive('evaluate')->once()->andReturn([]));
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson("/api/v1/common/request-application/pre-qualification/{$this->theirPreQualId}/run-ai")->assertOk();

        $this->assertSame(PreQualificationRequest::GPT_PENDING, DB::table('pre_qualification_requests')->where('id', $this->theirPreQualId)->value('gpt_status'));
    }

    #[Test]
    public function a_white_label_partner_cannot_re_run_even_its_own_pre_qualification(): void
    {
        $this->mock(PreQualificationAiService::class, fn ($mock) => $mock->shouldNotReceive('evaluate'));
        $wlp = $this->makeWhiteLabelPartner();
        $ownId = $this->preQualFor($this->makeBorrower($wlp)->id, $wlp->id);
        $this->actingAsUser($this->makePartnerAdmin($wlp));

        $this->postJson("/api/v1/common/request-application/pre-qualification/{$ownId}/run-ai")->assertStatus(403);

        $this->assertNull(DB::table('pre_qualification_requests')->where('id', $ownId)->value('gpt_status'));
    }

    #[Test]
    public function a_re_run_already_in_progress_is_not_started_twice(): void
    {
        $this->mock(PreQualificationAiService::class, fn ($mock) => $mock->shouldNotReceive('evaluate'));
        DB::table('pre_qualification_requests')->where('id', $this->myPreQualId)->update(['gpt_status' => PreQualificationRequest::GPT_PENDING, 'updated_at' => now()]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson("/api/v1/common/request-application/pre-qualification/{$this->myPreQualId}/run-ai")->assertStatus(409);
    }

    #[Test]
    public function a_status_must_be_a_known_one(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->updateStatus($this->myPreQualId, 'Vibes')->assertStatus(422);

        $this->assertSame(PreQualificationRequest::PENDING, $this->statusOf($this->myPreQualId));
    }
}
