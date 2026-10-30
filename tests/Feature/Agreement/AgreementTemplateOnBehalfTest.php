<?php

namespace Tests\Feature\Agreement;

use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateController;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Partner profile › Configuration › Agreement: the host edits a partner's
 * borrower agreement through the same /common/agreement-templates endpoints
 * the partner uses on its own Settings page, naming the partner with
 * `partner_id`.
 *
 * Unlike the Email, SMS and Branding endpoints, these check who is asking: a
 * partner's agreement is what its borrowers sign, so only a host user holding
 * `manage partner configuration` may name another partner.
 */
#[CoversClass(AgreementTemplateController::class)]
class AgreementTemplateOnBehalfTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PERMISSION = 'manage partner configuration';

    private Partner $host;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner(['company_name' => 'Acme Lending']);
    }

    private function hostAdmin(array $permissions = [self::PERMISSION]): User
    {
        return $this->actingAsUser($this->makePartnerAdmin($this->host, $permissions));
    }

    private function template(Partner $partner, array $attributes = []): int
    {
        return DB::table('agreement_templates')->insertGetId(array_merge([
            'partner_id' => $partner->id,
            'slug' => 'borrower',
            'template_html' => '<p>Terms</p>',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    #[Test]
    public function the_host_saves_and_reads_a_partners_borrower_agreement(): void
    {
        $this->hostAdmin();

        $id = $this->postJson('/api/v1/common/agreement-templates/save', [
            'partner_id' => $this->partner->id,
            'slug' => 'borrower',
            'template_html' => '<p>Acme terms</p>',
        ])->assertOk()->json('data.id');

        $this->assertSame($this->partner->id, (int) DB::table('agreement_templates')->where('id', $id)->value('partner_id'));
        $this->assertFalse(DB::table('agreement_templates')->where('partner_id', $this->host->id)->exists());

        $this->postJson('/api/v1/common/agreement-templates/show', ['partner_id' => $this->partner->id, 'slug' => 'borrower'])
            ->assertOk()
            ->assertJsonPath('data.id', $id);
        $this->getJson('/api/v1/common/agreement-templates?partner_id=' . $this->partner->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);
    }

    #[Test]
    public function the_host_updates_a_partners_existing_agreement_by_id(): void
    {
        $id = $this->template($this->partner);
        $this->hostAdmin();

        $this->postJson("/api/v1/common/agreement-templates/save/{$id}", [
            'partner_id' => $this->partner->id,
            'slug' => 'borrower',
            'template_html' => '<p>v2</p>',
        ])->assertOk();

        $this->assertSame('<p>v2</p>', DB::table('agreement_templates')->where('id', $id)->value('template_html'));
    }

    #[Test]
    public function the_host_turns_a_partners_agreement_off(): void
    {
        $id = $this->template($this->partner);
        $this->hostAdmin();

        $this->postJson("/api/v1/common/agreement-templates/update-status/{$id}", [
            'partner_id' => $this->partner->id,
            'is_active' => false,
        ])->assertOk();

        $this->assertFalse((bool) DB::table('agreement_templates')->where('id', $id)->value('is_active'));
    }

    #[Test]
    public function a_host_user_without_the_permission_cannot_name_another_partner(): void
    {
        $id = $this->template($this->partner);
        $this->hostAdmin([]);

        $this->getJson('/api/v1/common/agreement-templates?partner_id=' . $this->partner->id)->assertForbidden();
        $this->postJson('/api/v1/common/agreement-templates/show', ['partner_id' => $this->partner->id, 'slug' => 'borrower'])->assertForbidden();
        $this->postJson('/api/v1/common/agreement-templates/save', [
            'partner_id' => $this->partner->id, 'slug' => 'borrower', 'template_html' => '<p>x</p>',
        ])->assertForbidden();
        $this->postJson("/api/v1/common/agreement-templates/update-status/{$id}", [
            'partner_id' => $this->partner->id, 'is_active' => false,
        ])->assertForbidden();

        $this->assertSame('<p>Terms</p>', DB::table('agreement_templates')->where('id', $id)->value('template_html'));
        $this->assertTrue((bool) DB::table('agreement_templates')->where('id', $id)->value('is_active'));
    }

    #[Test]
    public function a_non_host_partner_cannot_name_another_partner_even_with_the_permission(): void
    {
        $id = $this->template($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->makeWhiteLabelPartner(), [self::PERMISSION]));

        $this->postJson('/api/v1/common/agreement-templates/save', [
            'partner_id' => $this->partner->id, 'slug' => 'borrower', 'template_html' => '<p>hijacked</p>',
        ])->assertForbidden();
        $this->postJson("/api/v1/common/agreement-templates/update-status/{$id}", [
            'partner_id' => $this->partner->id, 'is_active' => false,
        ])->assertForbidden();

        $this->assertSame('<p>Terms</p>', DB::table('agreement_templates')->where('id', $id)->value('template_html'));
        $this->assertTrue((bool) DB::table('agreement_templates')->where('id', $id)->value('is_active'));
    }

    #[Test]
    public function an_unknown_partner_is_not_found(): void
    {
        $this->hostAdmin();

        $this->getJson('/api/v1/common/agreement-templates?partner_id=999999')->assertNotFound();
    }

    #[Test]
    public function without_partner_id_the_host_still_edits_its_own_agreements(): void
    {
        $this->hostAdmin();

        $this->postJson('/api/v1/common/agreement-templates/save', ['slug' => 'partner', 'template_html' => '<p>Host</p>'])->assertOk();

        $this->assertTrue(DB::table('agreement_templates')->where('partner_id', $this->host->id)->where('slug', 'partner')->exists());
        $this->assertFalse(DB::table('agreement_templates')->where('partner_id', $this->partner->id)->exists());
    }
}
