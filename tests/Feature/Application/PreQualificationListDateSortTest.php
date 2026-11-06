<?php

namespace Tests\Feature\Application;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\User\User;
use App\Services\Borrower\Application\MyApplicationService;
use App\Services\FCH\Common\Application\RequestApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Pre-Qualification lists (Owner > Intake & Review > Pre-Qualification, and the
 * client's My Applications) order by the date their DATE column shows.
 *
 * Defect: BUG-174 - the column shows `created_at`, but both lists ordered by
 * `COALESCE(updated_at, created_at)`, so a request created 19 Oct and updated
 * later sorted after one created 20 Oct, and a request whose status changed
 * today jumped above newer ones.
 */
#[CoversClass(RequestApplicationService::class)]
#[CoversClass(MyApplicationService::class)]
class PreQualificationListDateSortTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private User $admin;

    private User $client;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = $this->makeHostPartner();
        $this->admin = $this->makePartnerAdmin($host);
        $this->client = $this->makeBorrowerUser($host);
        $borrower = Borrower::find($this->client->entity_id);

        // Created order: oct19 < oct20 < nov01. Updated order differs: oct19 was
        // touched last, oct20 never.
        $this->ids['oct19'] = $this->request($borrower, '2026-10-19 10:00:00', '2026-11-02 09:00:00');
        $this->ids['oct20'] = $this->request($borrower, '2026-10-20 10:00:00', null);
        $this->ids['nov01'] = $this->request($borrower, '2026-11-01 10:00:00', '2026-11-01 10:00:00');
    }

    private function request(Borrower $borrower, string $createdAt, ?string $updatedAt): int
    {
        $request = PreQualificationRequest::create([
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
            'status' => PreQualificationRequest::FAILED,
        ]);
        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $updatedAt])->saveQuietly();

        return $request->id;
    }

    /** @return array<int, int> */
    private function adminOrder(array $payload = []): array
    {
        $this->actingAsUser($this->admin);

        return collect($this->postJson('/api/v1/common/request-application/pre-qualification', $payload)->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    private function labels(array $ids): array
    {
        $byId = array_flip($this->ids);

        return array_map(fn ($id) => $byId[$id], $ids);
    }

    #[Test]
    public function the_date_column_sorts_ascending_by_the_date_shown(): void
    {
        $this->assertSame(['oct19', 'oct20', 'nov01'], $this->labels($this->adminOrder(['sort_field' => 'date', 'sort_order' => 'asc'])));
    }

    #[Test]
    public function the_date_column_sorts_descending_by_the_date_shown(): void
    {
        $this->assertSame(['nov01', 'oct20', 'oct19'], $this->labels($this->adminOrder(['sort_field' => 'date', 'sort_order' => 'desc'])));
    }

    #[Test]
    public function the_default_order_is_newest_shown_date_first(): void
    {
        $this->assertSame(['nov01', 'oct20', 'oct19'], $this->labels($this->adminOrder()));
    }

    #[Test]
    public function the_date_filter_matches_the_date_shown(): void
    {
        // oct19 was updated on 2 Nov, but its row shows 19 Oct.
        $this->assertSame(['oct19'], $this->labels($this->adminOrder(['start_date' => '2026-10-19', 'end_date' => '2026-10-19'])));
    }

    #[Test]
    public function the_clients_my_applications_list_is_newest_shown_date_first(): void
    {
        $this->actingAsUser($this->client);

        $ids = collect($this->postJson('/api/v1/borrower/myapplication/pre-qualification')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame(['nov01', 'oct20', 'oct19'], $this->labels($ids));
    }
}
