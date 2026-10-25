<?php

namespace Tests\Feature\Setting;

use App\Http\Controllers\Api\V1\FCH\Common\Setting\TenantConfigController;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdateTenantConfigRequest;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-121: Settings > Custom Domain saved "not a domain" as the borrower
 * domain - only "required / string / not taken" was checked. A domain must now
 * be a host name, is saved the way the domain resolver compares it (no scheme,
 * no www., lower case), and the borrower domain stays optional.
 */
#[CoversClass(TenantConfigController::class)]
#[CoversClass(UpdateTenantConfigRequest::class)]
class TenantDomainValidationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const URL = '/api/v1/common/partner/tenant-config';

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner([
            'active_custom_url' => true,
            'partner_domain' => 'portal.mine.test',
            'borrower_domain' => 'clients.mine.test',
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));
    }

    private function save(array $overrides)
    {
        return $this->postJson(self::URL, array_merge([
            'active_custom_url' => true,
            'partner_domain' => 'portal.mine.test',
            'borrower_domain' => 'clients.mine.test',
        ], $overrides));
    }

    /** @return iterable<string, array{string}> */
    public static function notDomains(): iterable
    {
        yield 'words with spaces' => ['not a domain'];
        yield 'no dot' => ['localhost'];
        yield 'bad character' => ['app_portal.example.com'];
        yield 'starts with a hyphen' => ['-app.example.com'];
        yield 'a path' => ['app.example.com/login'];
        yield 'empty label' => ['app..example.com'];
    }

    #[Test]
    #[DataProvider('notDomains')]
    public function a_borrower_domain_that_is_not_a_host_name_is_refused(string $value): void
    {
        $this->save(['borrower_domain' => $value])
            ->assertStatus(422)
            ->assertJsonPath('errors.borrower_domain', __('partner.validation.borrower_domain.host'));

        $this->assertSame('clients.mine.test', $this->partner->fresh()->borrower_domain);
    }

    #[Test]
    #[DataProvider('notDomains')]
    public function a_partner_domain_that_is_not_a_host_name_is_refused(string $value): void
    {
        $this->save(['partner_domain' => $value])
            ->assertStatus(422)
            ->assertJsonPath('errors.partner_domain', __('partner.validation.partner_domain.host'));

        $this->assertSame('portal.mine.test', $this->partner->fresh()->partner_domain);
    }

    #[Test]
    public function the_borrower_domain_stays_optional_with_the_custom_domain_on(): void
    {
        $this->save(['borrower_domain' => null])->assertOk();
        $this->assertNull($this->partner->fresh()->borrower_domain);

        $this->save(['borrower_domain' => ''])->assertOk();
        $this->assertNull($this->partner->fresh()->borrower_domain);
    }

    #[Test]
    public function the_partner_domain_is_still_required_with_the_custom_domain_on(): void
    {
        // The screens leave this to the API (no browser check), so it names the field.
        $this->save(['partner_domain' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.partner_domain', __('partner.validation.partner_domain.portal_required'));
        $this->assertNotSame('partner.validation.partner_domain.portal_required', __('partner.validation.partner_domain.portal_required'));
    }

    #[Test]
    public function a_domain_is_saved_the_way_the_resolver_compares_it(): void
    {
        $this->save([
            'partner_domain' => ' HTTPS://www.Portal.Example.com/ ',
            'borrower_domain' => 'http://Apply.Example.com',
        ])->assertOk();

        $partner = $this->partner->fresh();
        $this->assertSame('portal.example.com', $partner->partner_domain);
        $this->assertSame('apply.example.com', $partner->borrower_domain);
    }

    #[Test]
    public function a_staff_user_can_save_their_partners_current_domains(): void
    {
        // The duplicate check excluded the user's entity_id - the employee id
        // for staff - so the partner's own domain read as "already taken".
        $staff = $this->makeStaffUser($this->partner);
        Employee::whereKey($staff->entity_id)->update(['id' => 90210]);
        $staff->update(['entity_id' => 90210]);
        $this->actingAsUser($staff->fresh());

        $this->save([])->assertOk();
    }

    #[Test]
    public function a_domain_another_partner_uses_is_still_refused(): void
    {
        $this->makeWhiteLabelPartner(['borrower_domain' => 'taken.example.com']);

        $this->save(['borrower_domain' => 'https://www.Taken.example.com'])
            ->assertStatus(422)
            ->assertJsonPath('errors.borrower_domain', __('partner.validation.borrower_domain.unique'));
    }
}
