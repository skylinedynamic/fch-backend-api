<?php

namespace Tests\Feature\Partner;

use App\Http\Controllers\Api\V1\FCH\Common\Partner\PartnerOptionController;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The host's partner dropdowns: POST /common/get-all-partners lists every
 * partner - the host and archived ones too, unpaginated - as just its id,
 * name and company, sorted by name. The Partners table keeps its own
 * paginated /admin/partners/fetch-partners.
 */
#[CoversClass(PartnerOptionController::class)]
class PartnerOptionsApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const URL = '/api/v1/common/get-all-partners';

    private Partner $host;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner(['full_name' => 'Mia Host', 'company_name' => 'Host Co']);
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
    }

    #[Test]
    public function the_host_gets_every_partner_beyond_one_page_sorted_by_name(): void
    {
        foreach (range(1, 12) as $i) {
            $this->makeWhiteLabelPartner(['full_name' => sprintf('Partner %02d', 13 - $i), 'company_name' => "Co {$i}"]);
        }
        $this->actingAsUser($this->hostAdmin);

        $names = collect($this->postJson(self::URL)->assertOk()->json('data'))->pluck('full_name')->all();

        $this->assertCount(13, $names);
        $this->assertSame('Mia Host', $names[0]);
        $this->assertSame(array_map(fn ($i) => sprintf('Partner %02d', $i), range(1, 12)), array_slice($names, 1));
    }

    #[Test]
    public function each_partner_is_only_its_id_name_and_company(): void
    {
        $wlp = $this->makeWhiteLabelPartner(['full_name' => 'Wil Label', 'company_name' => 'WLP Co']);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson(self::URL)
            ->assertOk()
            ->assertExactJson([
                'status' => true,
                'message' => __('partner.success.options_retrieved'),
                'data' => [
                    ['id' => $this->host->id, 'full_name' => 'Mia Host', 'company_name' => 'Host Co'],
                    ['id' => $wlp->id, 'full_name' => 'Wil Label', 'company_name' => 'WLP Co'],
                ],
            ]);
    }

    #[Test]
    public function archived_partners_are_listed_so_their_records_stay_filterable(): void
    {
        $archived = $this->makeCertifiedPartner(['full_name' => 'Arch Ived']);
        $archived->archive($this->hostAdmin->id);
        $this->actingAsUser($this->hostAdmin);

        $this->assertContains($archived->id, collect($this->postJson(self::URL)->assertOk()->json('data'))->pluck('id')->all());
    }

    #[Test]
    public function the_hosts_staff_get_the_list_too(): void
    {
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->postJson(self::URL)->assertOk()->assertJsonPath('data.0.id', $this->host->id);
    }

    #[Test]
    public function a_white_label_or_certified_partner_is_refused(): void
    {
        foreach ([$this->makeWhiteLabelPartner(), $this->makeCertifiedPartner()] as $partner) {
            $this->actingAsUser($this->makePartnerAdmin($partner));
            $this->postJson(self::URL)->assertForbidden()->assertJsonPath('message', __('auth.access_denied_host_only'));

            $this->actingAsUser($this->makeStaffUser($partner));
            $this->postJson(self::URL)->assertForbidden();
        }
    }

    #[Test]
    public function a_hosts_borrower_is_refused(): void
    {
        $this->actingAsUser($this->makeBorrowerUser($this->host));

        $this->postJson(self::URL)->assertForbidden();
    }

    #[Test]
    public function a_guest_is_refused(): void
    {
        $this->postJson(self::URL)->assertUnauthorized();
    }

    #[Test]
    public function the_partners_table_list_still_pages_ten_at_a_time(): void
    {
        foreach (range(1, 12) as $i) {
            $this->makeWhiteLabelPartner();
        }
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/partners/fetch-partners')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 13);
    }
}
