<?php

namespace Tests\Feature\Application;

use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Application\RequestApplicationService;
use App\Services\FCH\Common\FundingRequest\FundingRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Host > Intake & Review > Funding Application and Funding Request: the host
 * sees whose client each row is (PARTNER NAME) and can narrow either list to
 * one partner - from the Partner dropdown (`partner_id`) or by typing in the
 * PARTNER NAME column (`filters.partner_name`, the partner's name or company).
 * A partner's own team only ever sees its own rows, whatever it sends.
 */
#[CoversClass(RequestApplicationService::class)]
#[CoversClass(FundingRequestService::class)]
class IntakeReviewPartnerFilterTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private const APPLICATIONS = '/api/v1/common/request-application/funding-application';
    private const REQUESTS = '/api/v1/common/funding-request';

    private User $hostAdmin;
    private Partner $wanda;
    private Partner $bravo;

    /** @var array<string, int> application id by label */
    private array $apps = [];

    /** @var array<string, int> funding request id by label */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = $this->makeHostPartner(['full_name' => 'Hal Host', 'company_name' => 'Host Funding']);
        $this->hostAdmin = $this->makePartnerAdmin($host);
        $this->wanda = $this->makeWhiteLabelPartner(['full_name' => 'Wanda Wells', 'company_name' => 'Wells Capital']);
        $this->bravo = $this->makeWhiteLabelPartner(['full_name' => 'Bravo Brown', 'company_name' => 'Brown Lending']);

        foreach (['host' => $host, 'wanda' => $this->wanda, 'bravo' => $this->bravo] as $label => $partner) {
            $this->requests[$label] = $this->fundingRequest($partner);
            $this->apps[$label] = $this->application($partner, $this->requests[$label]);
        }
    }

    private function fundingRequest(Partner $partner): int
    {
        return DB::table('funding_requests')->insertGetId([
            'borrower_id' => $this->makeBorrower($partner)->id,
            'partner_id' => $partner->id,
            'funding_sequence_id' => DB::table('funding_sequencings')->insertGetId(['name' => 'Working Capital', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]),
            'funding_type' => 'Working Capital',
            'requested_amount' => 100000,
            'status' => FundingRequest::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function application(Partner $partner, int $requestId): int
    {
        return DB::table('funding_applications')->insertGetId([
            'partner_id' => $partner->id,
            'borrower_id' => DB::table('funding_requests')->where('id', $requestId)->value('borrower_id'),
            'funding_request_id' => $requestId,
            'full_name' => 'Client of ' . $partner->full_name,
            'application_number' => 'FUND-' . $requestId,
            'requested_amount' => 100000,
            'status' => FundingApplication::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ids(string $endpoint, array $payload, ?User $viewer = null): array
    {
        $this->actingAsUser($viewer ?? $this->hostAdmin);

        return collect($this->postJson($endpoint, $payload)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    private function sorted(int ...$ids): array
    {
        sort($ids);

        return $ids;
    }

    /** The PARTNER NAME column's own filter, as PrimeReact sends it. */
    private static function column(string $typed): array
    {
        return ['filters' => ['partner_name' => [
            'operator' => 'and',
            'constraints' => [['value' => $typed, 'matchMode' => 'contains']],
        ]]];
    }

    // ---- Funding Application -------------------------------------------------

    #[Test]
    public function each_funding_application_names_its_partner(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $rows = collect($this->postJson(self::APPLICATIONS, [])->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('Wanda Wells', $rows[$this->apps['wanda']]['partner_name']);
        $this->assertSame('Bravo Brown', $rows[$this->apps['bravo']]['partner_name']);
        $this->assertSame('Hal Host', $rows[$this->apps['host']]['partner_name']);
    }

    #[Test]
    public function the_partner_dropdown_keeps_only_that_partners_applications(): void
    {
        $this->assertSame([$this->apps['wanda']], $this->ids(self::APPLICATIONS, ['partner_id' => $this->wanda->id]));
        $this->assertSame([$this->apps['bravo']], $this->ids(self::APPLICATIONS, ['partner_id' => $this->bravo->id]));
    }

    #[Test]
    public function the_partner_name_column_finds_applications_by_partner_name_or_company(): void
    {
        $this->assertSame([$this->apps['wanda']], $this->ids(self::APPLICATIONS, self::column('wanda')));
        $this->assertSame([$this->apps['bravo']], $this->ids(self::APPLICATIONS, self::column('Brown Lending')));
        $this->assertSame([], $this->ids(self::APPLICATIONS, self::column('Nobody')));
    }

    /**
     * Defect (found 2026-11-11): the search box ("Search here...") found
     * nothing for a partner's name - the funding application search never
     * looked at the partner, while the funding request search did.
     */
    #[Test]
    public function the_search_box_finds_applications_by_partner_name_or_company(): void
    {
        $this->assertSame([$this->apps['wanda']], $this->ids(self::APPLICATIONS, ['search' => 'Wanda Wells']));
        $this->assertSame([$this->apps['bravo']], $this->ids(self::APPLICATIONS, ['search' => 'Brown Lending']));
    }

    #[Test]
    public function funding_applications_sort_by_partner_name_both_ways(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $order = fn (string $dir) => collect($this->postJson(self::APPLICATIONS, ['sort_field' => 'partner_name', 'sort_order' => $dir])
            ->assertOk()->json('data'))->pluck('partner_name')->all();

        $this->assertSame(['Bravo Brown', 'Hal Host', 'Wanda Wells'], $order('asc'));
        $this->assertSame(['Wanda Wells', 'Hal Host', 'Bravo Brown'], $order('desc'));
    }

    #[Test]
    public function a_partner_cannot_see_another_partners_applications_through_the_partner_filter(): void
    {
        $wandaAdmin = $this->makePartnerAdmin($this->wanda);

        $this->assertSame([], $this->ids(self::APPLICATIONS, ['partner_id' => $this->bravo->id], $wandaAdmin));
        $this->assertSame([], $this->ids(self::APPLICATIONS, self::column('Bravo'), $wandaAdmin));
        $this->assertSame([$this->apps['wanda']], $this->ids(self::APPLICATIONS, ['partner_id' => $this->wanda->id], $wandaAdmin));
    }

    #[Test]
    public function an_unknown_partner_id_is_refused(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson(self::APPLICATIONS, ['partner_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('partner_id');
        $this->postJson(self::REQUESTS, ['partner_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('partner_id');
    }

    // ---- Funding Request -----------------------------------------------------

    #[Test]
    public function the_partner_dropdown_keeps_only_that_partners_funding_requests(): void
    {
        $this->assertSame([$this->requests['wanda']], $this->ids(self::REQUESTS, ['partner_id' => $this->wanda->id]));
        $this->assertSame([$this->requests['bravo']], $this->ids(self::REQUESTS, ['partner_id' => $this->bravo->id]));
    }

    #[Test]
    public function the_partner_name_column_finds_funding_requests_by_partner_name_or_company(): void
    {
        $this->assertSame([$this->requests['wanda']], $this->ids(self::REQUESTS, self::column('Wells')));
        $this->assertSame([$this->requests['bravo']], $this->ids(self::REQUESTS, self::column('bravo brown')));
        $this->assertSame([], $this->ids(self::REQUESTS, self::column('Nobody')));
    }

    #[Test]
    public function the_partner_filters_combine_with_the_other_filters(): void
    {
        DB::table('funding_requests')->where('id', $this->requests['wanda'])->update(['status' => FundingRequest::STATUS_APPROVED]);

        $this->assertSame([], $this->ids(self::REQUESTS, ['partner_id' => $this->bravo->id, 'status' => FundingRequest::STATUS_APPROVED]));
        $this->assertSame([$this->requests['wanda']], $this->ids(self::REQUESTS, ['partner_id' => $this->wanda->id, 'status' => FundingRequest::STATUS_APPROVED]));
        $this->assertSame(
            $this->sorted($this->apps['wanda']),
            $this->ids(self::APPLICATIONS, ['partner_id' => $this->wanda->id, 'status' => FundingApplication::PENDING])
        );
    }

    #[Test]
    public function a_partner_cannot_see_another_partners_funding_requests_through_the_partner_filter(): void
    {
        $wandaAdmin = $this->makePartnerAdmin($this->wanda);

        $this->assertSame([], $this->ids(self::REQUESTS, ['partner_id' => $this->bravo->id], $wandaAdmin));
        $this->assertSame([], $this->ids(self::REQUESTS, self::column('Bravo'), $wandaAdmin));
        $this->assertSame([$this->requests['wanda']], $this->ids(self::REQUESTS, [], $wandaAdmin));
    }
}
