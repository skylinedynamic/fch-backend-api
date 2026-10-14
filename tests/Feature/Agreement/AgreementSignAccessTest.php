<?php

namespace Tests\Feature\Agreement;

use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementSignController;
use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateController;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Signed agreements (/common/agreement-sign/*, /borrower/agreement-sign/*) and
 * a partner's agreement templates (/common/agreement-templates/*). Everyone
 * reaches their own signed agreement; only the host's team reaches someone
 * else's - which includes cancelling it.
 */
#[CoversClass(AgreementSignController::class)]
#[CoversClass(AgreementTemplateController::class)]
class AgreementSignAccessTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->signer = $this->makePartnerAdmin($this->makeCertifiedPartner());
    }

    public static function otherUsersAgreementRoutes(): array
    {
        return [
            'view' => ['GET', '/api/v1/common/agreement-sign/html/%d'],
            'download' => ['GET', '/api/v1/common/agreement-sign/download/%d'],
            'cancel' => ['POST', '/api/v1/common/agreement-sign/cancel/%d'],
        ];
    }

    /**
     * Defect: the access check restricted only users whose entity is a
     * non-host Partner or a Borrower - a partner's staff (entity Employee)
     * slipped through and could read, download or revoke anyone's signed
     * agreement, the host's included.
     */
    #[Test]
    #[DataProvider('otherUsersAgreementRoutes')]
    public function a_partners_staff_cannot_reach_someone_elses_agreement(string $method, string $url): void
    {
        $this->actingAsUser($this->makeStaffUser($this->partner));

        $this->json($method, sprintf($url, $this->signer->id))->assertNotFound();
    }

    #[Test]
    #[DataProvider('otherUsersAgreementRoutes')]
    public function a_non_host_partner_admin_cannot_reach_someone_elses_agreement(string $method, string $url): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->json($method, sprintf($url, $this->signer->id))->assertNotFound();
    }

    #[Test]
    #[DataProvider('otherUsersAgreementRoutes')]
    public function the_hosts_team_reaches_any_users_agreement(string $method, string $url): void
    {
        foreach ([$this->makePartnerAdmin($this->host), $this->makeStaffUser($this->host)] as $hostUser) {
            $this->actingAsUser($hostUser);

            $this->assertNotSame(404, $this->json($method, sprintf($url, $this->signer->id))->status());
        }
    }

    #[Test]
    public function a_borrower_reaches_only_their_own_agreement_from_the_borrower_portal(): void
    {
        $borrowerUser = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($borrowerUser);

        $this->getJson("/api/v1/borrower/agreement-sign/signed-html/{$this->signer->id}")->assertNotFound();
        $this->getJson("/api/v1/borrower/agreement-sign/download/{$this->signer->id}")->assertNotFound();
        $this->getJson('/api/v1/borrower/agreement-sign/check')->assertOk();
    }

    #[Test]
    public function the_sign_check_answers_for_the_signed_in_partner(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->getJson('/api/v1/common/agreement-sign/check')->assertOk();
        $this->postJson('/api/v1/common/agreement-sign/submit', [])->assertStatus(422)->assertJsonValidationErrors(['agreement_template_id']);
    }

    #[Test]
    public function a_partner_manages_only_its_own_templates(): void
    {
        $theirs = DB::table('agreement_templates')->insertGetId([
            'partner_id' => $this->host->id,
            'slug' => 'partner-agreement',
            'template_html' => '<p>Terms</p>',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->getJson('/api/v1/common/agreement-templates')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/common/agreement-templates/update-status/{$theirs}", ['is_active' => false]);

        $this->assertTrue((bool) DB::table('agreement_templates')->where('id', $theirs)->value('is_active'));
    }

    #[Test]
    public function a_partner_saves_and_opens_its_own_template_by_slug(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $id = $this->postJson('/api/v1/common/agreement-templates/save', ['slug' => 'borrower-agreement', 'template_html' => '<p>v1</p>'])
            ->assertOk()->json('data.id');
        $this->postJson("/api/v1/common/agreement-templates/save/{$id}", ['slug' => 'borrower-agreement', 'template_html' => '<p>v2</p>'])->assertOk();

        $this->postJson('/api/v1/common/agreement-templates/show', ['slug' => 'borrower-agreement'])
            ->assertOk()
            ->assertJsonPath('data.id', $id);
        $this->assertSame('<p>v2</p>', DB::table('agreement_templates')->where('id', $id)->value('template_html'));
        $this->assertSame($this->partner->id, (int) DB::table('agreement_templates')->where('id', $id)->value('partner_id'));
    }

    #[Test]
    public function another_partners_template_is_neither_opened_nor_overwritten(): void
    {
        $theirs = DB::table('agreement_templates')->insertGetId([
            'partner_id' => $this->host->id,
            'slug' => 'borrower-agreement',
            'template_html' => '<p>Host terms</p>',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->postJson('/api/v1/common/agreement-templates/show', ['slug' => 'borrower-agreement'])->assertJsonPath('status', false);
        $this->postJson("/api/v1/common/agreement-templates/save/{$theirs}", ['slug' => 'borrower-agreement', 'template_html' => '<p>Mine</p>'])
            ->assertJsonPath('status', false);

        $this->assertSame('<p>Host terms</p>', DB::table('agreement_templates')->where('id', $theirs)->value('template_html'));
    }

    private function template(Partner $owner, string $slug): int
    {
        return DB::table('agreement_templates')->insertGetId([
            'partner_id' => $owner->id,
            'slug' => $slug,
            'template_html' => "<p>{$slug} terms of {$owner->id}</p>",
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_borrower_signs_their_own_partners_borrower_agreement(): void
    {
        $mine = $this->template($this->partner, 'borrower');
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->postJson('/api/v1/borrower/agreement-sign/submit', ['agreement_template_id' => $mine])->assertOk();

        $this->assertTrue((bool) Borrower::find($me->entity_id)->has_sign);
        $this->assertDatabaseHas('agreement_signs', ['user_id' => $me->id, 'agreement_template_id' => $mine]);
    }

    /**
     * Defect: any active template on the platform was accepted, so a borrower
     * could mark themselves signed by "signing" another partner's agreement,
     * or the host's partner agreement, instead of the one their partner
     * requires (see AgreementService::checkSignNeed()).
     */
    #[Test]
    public function a_borrower_cannot_sign_an_agreement_that_is_not_theirs_to_sign(): void
    {
        $this->template($this->partner, 'borrower');
        $someoneElses = $this->template($this->makeCertifiedPartner(), 'borrower');
        $hostPartnerAgreement = $this->template($this->host, 'partner');
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner));

        foreach ([$someoneElses, $hostPartnerAgreement] as $template) {
            $this->postJson('/api/v1/borrower/agreement-sign/submit', ['agreement_template_id' => $template])->assertJsonPath('status', false);
        }

        $this->assertFalse((bool) Borrower::find($me->entity_id)->has_sign);
        $this->assertDatabaseCount('agreement_signs', 0);
    }

    #[Test]
    public function a_partner_signs_the_hosts_partner_agreement_and_no_other(): void
    {
        $hosts = $this->template($this->host, 'partner');
        $someoneElses = $this->template($this->partner, 'partner');
        $this->actingAsUser($this->signer);

        $this->postJson('/api/v1/common/agreement-sign/submit', ['agreement_template_id' => $someoneElses])->assertJsonPath('status', false);
        $this->assertFalse((bool) $this->signer->partner->fresh()->has_sign);

        $this->postJson('/api/v1/common/agreement-sign/submit', ['agreement_template_id' => $hosts])->assertOk();
        $this->assertTrue((bool) $this->signer->partner->fresh()->has_sign);
    }
}
