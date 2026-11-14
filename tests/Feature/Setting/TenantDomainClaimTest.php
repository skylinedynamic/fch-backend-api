<?php

namespace Tests\Feature\Setting;

use App\Http\Requests\Api\V1\FCH\Admin\Partner\StorePartnerRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdateTenantConfigRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Rules\DomainNotClaimed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-020: a partner's portal domain could be set to the host's admin
 * domain or to another partner's portal domain - only the same column was
 * compared. A domain the host uses, or any other partner uses on either
 * portal, can never be claimed; a partner's two portals can't share one.
 */
#[CoversClass(DomainNotClaimed::class)]
#[CoversClass(UpdateTenantConfigRequest::class)]
#[CoversClass(StorePartnerRequest::class)]
class TenantDomainClaimTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $hostAdmin;
    private Partner $partner;
    private Partner $wanda;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // As stage sets them: the reserved list the edge resolver uses.
        config([
            'app.base_website' => 'skylinedynamic.dev',
            'domains.reserved' => [
                'base_reserved_website' => 'skylinedynamic.dev',
                'base_reserved_api_portal' => 'api-stage.skylinedynamic.dev',
                'base_reserved_partner_portal' => 'https://admin-stage.skylinedynamic.dev',
                'base_reserved_borrower_portal' => 'https://client-stage.skylinedynamic.dev',
            ],
            'app.url' => 'http://localhost',
        ]);
        $host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($host);
        $this->partner = $this->makeWhiteLabelPartner(['partner_domain' => 'qarunbwl.skylinedynamic.dev']);
        $this->wanda = $this->makeWhiteLabelPartner([
            'partner_domain' => 'wanda.skylinedynamic.dev',
            'borrower_domain' => 'wanda-borrower.skylinedynamic.dev',
        ]);
        $this->actingAsUser($this->hostAdmin);
    }

    private function save(array $overrides)
    {
        return $this->postJson("/api/v1/admin/partners/tenant-config/{$this->partner->id}", array_merge([
            'active_custom_url' => false,
            'partner_domain' => 'qarunbwl.skylinedynamic.dev',
            'borrower_domain' => null,
        ], $overrides));
    }

    /** @return iterable<string, array{string}> */
    public static function hostDomains(): iterable
    {
        yield 'the admin portal' => ['admin-stage.skylinedynamic.dev'];
        yield 'the client portal' => ['client-stage.skylinedynamic.dev'];
        yield 'the API' => ['api-stage.skylinedynamic.dev'];
        yield 'the website' => ['skylinedynamic.dev'];
        yield 'a reserved name' => ['admin.skylinedynamic.dev'];
    }

    #[Test]
    #[DataProvider('hostDomains')]
    public function a_host_domain_is_refused_as_the_borrower_domain(string $domain): void
    {
        $this->save(['borrower_domain' => $domain])
            ->assertStatus(422)
            ->assertJsonPath('errors.borrower_domain', __('partner.validation.borrower_domain.unique'));

        $this->assertNull($this->partner->fresh()->borrower_domain);
    }

    #[Test]
    #[DataProvider('hostDomains')]
    public function a_host_domain_is_refused_as_the_partner_domain(string $domain): void
    {
        $this->save(['partner_domain' => $domain])
            ->assertStatus(422)
            ->assertJsonPath('errors.partner_domain', __('partner.validation.partner_domain.unique'));
    }

    #[Test]
    public function another_partners_partner_domain_is_refused_as_the_borrower_domain(): void
    {
        $this->save(['borrower_domain' => 'wanda.skylinedynamic.dev'])
            ->assertStatus(422)
            ->assertJsonPath('errors.borrower_domain', __('partner.validation.borrower_domain.unique'));
    }

    #[Test]
    public function another_partners_borrower_domain_is_refused_as_the_partner_domain(): void
    {
        $this->save(['partner_domain' => 'wanda-borrower.skylinedynamic.dev'])
            ->assertStatus(422)
            ->assertJsonPath('errors.partner_domain', __('partner.validation.partner_domain.unique'));
    }

    #[Test]
    public function a_partners_two_portals_cannot_share_a_domain(): void
    {
        $this->save(['borrower_domain' => 'qarunbwl.skylinedynamic.dev'])
            ->assertStatus(422)
            ->assertJsonPath('errors.borrower_domain', __('partner.validation.borrower_domain.same_as_partner'));
    }

    #[Test]
    public function the_partners_own_domains_and_a_free_one_still_save(): void
    {
        $this->save(['borrower_domain' => 'qarunbwl-borrower.skylinedynamic.dev'])->assertOk();
        $this->assertSame('qarunbwl-borrower.skylinedynamic.dev', $this->partner->fresh()->borrower_domain);

        $this->save(['borrower_domain' => 'qarunbwl-borrower.skylinedynamic.dev'])->assertOk();
    }

    #[Test]
    public function a_deleted_partners_domain_is_free(): void
    {
        $gone = $this->makeWhiteLabelPartner(['partner_domain' => 'gone.skylinedynamic.dev']);
        $gone->delete();

        $this->save(['borrower_domain' => 'gone.skylinedynamic.dev'])->assertOk();
    }

    // ── Add Partner ────────────────────────────────────────────────────────

    private function newPartner(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Pat Partner',
            'company_name' => 'Pat Co ' . uniqid(),
            'email' => 'pat-' . uniqid() . '@example.test',
            'phone_number' => '(555) 234-5678',
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'country' => 'United States',
            'partner_domain' => 'pat' . uniqid(),
            'is_free_trail' => true,
            'free_trail_end_date' => now()->addDays(14)->format('Y-m-d'),
        ], $overrides);
    }

    #[Test]
    public function a_new_partners_subdomain_cannot_be_the_hosts_or_another_partners_borrower_domain(): void
    {
        $this->postJson('/api/v1/admin/partners', $this->newPartner(['partner_domain' => 'admin-stage']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('partner_domain');

        $this->postJson('/api/v1/admin/partners', $this->newPartner(['partner_domain' => 'wanda-borrower']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('partner_domain');
    }
}
