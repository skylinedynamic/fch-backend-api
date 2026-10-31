<?php

namespace Tests\Feature\Agreement;

use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementTemplateController;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The Agreement Builder's preview shows the template owner's real company
 * details in the {{owner.*}} merge fields - the same values the server fills
 * when the agreement is signed. Signer fields have no real value while a
 * template is being built, so none are returned.
 */
#[CoversClass(AgreementTemplateController::class)]
class AgreementPreviewValuesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner([
            'company_name' => 'Acme Lending',
            'email' => 'hello@acme.test',
            'phone_number' => '(512) 555-0100',
            'address_line_1' => '1 Main St',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78701',
        ]);
    }

    #[Test]
    public function a_partner_gets_its_own_company_details_for_the_preview(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner, []));

        $values = $this->postJson('/api/v1/common/agreement-templates/preview-values')->assertOk()->json('data');

        $this->assertSame('Acme Lending', $values['owner.company_name']);
        $this->assertSame('hello@acme.test', $values['owner.email']);
        $this->assertSame('(512) 555-0100', $values['owner.phone']);
        $this->assertStringContainsString('1 Main St', $values['owner.address']);
        $this->assertStringContainsString('Austin', $values['owner.address']);
        $this->assertSame(now()->format('m/d/Y'), $values['today']);
    }

    #[Test]
    public function no_signer_details_are_returned(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner, []));

        $values = $this->postJson('/api/v1/common/agreement-templates/preview-values')->assertOk()->json('data');

        $this->assertSame([], array_filter(array_keys($values), fn ($key) => str_starts_with($key, 'signer.')));
    }

    #[Test]
    public function the_host_gets_the_details_of_the_partner_it_configures(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host, ['manage partner configuration']));

        $values = $this->postJson('/api/v1/common/agreement-templates/preview-values', ['partner_id' => $this->partner->id])
            ->assertOk()->json('data');

        $this->assertSame('Acme Lending', $values['owner.company_name']);
    }

    #[Test]
    public function another_partners_details_need_the_host_permission(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host, []));

        $this->postJson('/api/v1/common/agreement-templates/preview-values', ['partner_id' => $this->partner->id])->assertForbidden();
    }
}
