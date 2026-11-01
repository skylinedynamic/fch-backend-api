<?php

namespace Tests\Feature\Agreement;

use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementSignController;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-106: the admin's View Agreement page (/common/agreement-sign/html) for
 * a partner or client marked as signed (has_sign) whose signed copy is gone,
 * and for one who never signed.
 *
 *   - the reply says whether the record is marked as signed, so the page can
 *     tell "file missing" (offer Re-sign) from "never signed"
 *   - Re-sign (agreement-sign/cancel) clears has_sign even when there is no
 *     signed copy left to delete, so they are asked to sign again
 */
#[CoversClass(AgreementSignController::class)]
class AgreementSignMissingFileTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $hostAdmin;
    private Partner $partner;
    private User $partnerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostAdmin = $this->makePartnerAdmin($this->makeHostPartner());
        $this->partner = $this->makeWhiteLabelPartner(['has_sign' => true]);
        $this->partnerUser = $this->makePartnerAdmin($this->partner);
        $this->actingAsUser($this->hostAdmin);
    }

    private function signedCopy(User $user, string $html = '<p>Signed terms</p>'): int
    {
        $template = DB::table('agreement_templates')->insertGetId([
            'partner_id' => $this->hostAdmin->partner_id,
            'slug' => 'partner-agreement',
            'template_html' => '<p>Terms</p>',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('agreement_signs')->insertGetId([
            'user_id' => $user->id,
            'entity_type' => $user->entity_type,
            'entity_id' => $user->entity_id,
            'agreement_template_id' => $template,
            'content_html' => $html,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_signed_partner_whose_copy_is_missing_is_reported_as_signed_with_no_content(): void
    {
        $this->getJson("/api/v1/common/agreement-sign/html/{$this->partnerUser->id}")
            ->assertOk()
            ->assertJsonPath('data.content_html', '')
            ->assertJsonPath('data.has_sign', true);
    }

    #[Test]
    public function a_partner_who_never_signed_is_reported_as_not_signed(): void
    {
        $this->partner->update(['has_sign' => false]);

        $this->getJson("/api/v1/common/agreement-sign/html/{$this->partnerUser->id}")
            ->assertOk()
            ->assertJsonPath('data.content_html', '')
            ->assertJsonPath('data.has_sign', false);
    }

    #[Test]
    public function a_signed_copy_comes_back_with_its_content(): void
    {
        $this->signedCopy($this->partnerUser);

        $this->getJson("/api/v1/common/agreement-sign/html/{$this->partnerUser->id}")
            ->assertOk()
            ->assertJsonPath('data.content_html', '<p>Signed terms</p>')
            ->assertJsonPath('data.has_sign', true);
    }

    #[Test]
    public function re_sign_clears_has_sign_even_when_the_signed_copy_is_missing(): void
    {
        $this->postJson("/api/v1/common/agreement-sign/cancel/{$this->partnerUser->id}")->assertOk();

        $this->assertFalse((bool) $this->partner->fresh()->has_sign, 'They are asked to sign again.');
        $this->assertSame('1', (string) $this->partnerUser->fresh()->is_refresh);
    }

    #[Test]
    public function re_sign_works_for_a_client_too(): void
    {
        $borrowerUser = $this->makeBorrowerUser($this->partner, ['has_sign' => true]);

        $this->getJson("/api/v1/common/agreement-sign/html/{$borrowerUser->id}")
            ->assertOk()
            ->assertJsonPath('data.has_sign', true);

        $this->postJson("/api/v1/common/agreement-sign/cancel/{$borrowerUser->id}")->assertOk();

        $this->assertFalse((bool) Borrower::findOrFail($borrowerUser->entity_id)->has_sign);
    }

    #[Test]
    public function cancelling_a_signed_copy_still_deletes_it(): void
    {
        $sign = $this->signedCopy($this->partnerUser);

        $this->postJson("/api/v1/common/agreement-sign/cancel/{$this->partnerUser->id}")->assertOk();

        $this->assertDatabaseMissing('agreement_signs', ['id' => $sign]);
        $this->assertFalse((bool) $this->partner->fresh()->has_sign);
    }

    #[Test]
    public function another_partner_still_cannot_re_sign_someone_elses_agreement(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeCertifiedPartner()));

        $this->postJson("/api/v1/common/agreement-sign/cancel/{$this->partnerUser->id}")->assertNotFound();

        $this->assertTrue((bool) $this->partner->fresh()->has_sign);
    }
}
