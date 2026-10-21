<?php

namespace Tests\Feature\CreditProfile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Request Credit Profile list (Intake & Review) shows the latest request
 * first.
 *
 * A borrower has one request, updated in place when they submit again, so
 * "latest" is when it was last submitted (updated_at), not when it was first
 * created: a client who resubmits today must come to the top, above someone who
 * first asked yesterday. The list used to sort on created_at and ignore the
 * sort the screen sent.
 */
class CreditProfileRequestListOrderTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Four Corner Host',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        Passport::actingAs(User::create([
            'name' => 'Host Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::ADMIN,
            'partner_id' => $this->host->id,
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function requestAt(string $email, string $createdAt, ?string $updatedAt = null): CreditProfileRequest
    {
        $borrower = Borrower::create([
            'partner_id' => $this->host->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Client',
            'last_name' => $email,
            'email' => $email,
        ]);

        $request = CreditProfileRequest::create([
            'borrower_id' => $borrower->id,
            'first_name' => 'Client',
            'last_name' => $email,
            'address' => '1 Main St',
            'city' => 'Austin',
            'state' => 'Texas',
            'zip' => '78701',
            'ssn' => '123456789',
            'date_of_birth' => '1990-01-01',
            'phone' => '5125550100',
            'email' => $email,
        ]);

        // Timestamps set directly: what matters is their order, not the clock.
        $request->forceFill([
            'created_at' => Carbon::parse($createdAt),
            'updated_at' => Carbon::parse($updatedAt ?? $createdAt),
        ])->saveQuietly();

        return $request;
    }

    /** @return string[] row emails in the order the list returns them */
    private function listed(array $payload = []): array
    {
        return collect($this->postJson('/api/v1/admin/credit-profile-requests', $payload)->assertOk()->json('data.data'))
            ->pluck('email')
            ->all();
    }

    #[Test]
    public function the_most_recently_submitted_request_comes_first(): void
    {
        $this->requestAt('old@example.test', '2026-10-01 09:00:00');
        $this->requestAt('new@example.test', '2026-10-10 09:00:00');
        $this->requestAt('middle@example.test', '2026-10-05 09:00:00');

        $this->assertSame(['new@example.test', 'middle@example.test', 'old@example.test'], $this->listed());
    }

    #[Test]
    public function a_resubmitted_request_moves_to_the_top(): void
    {
        // First asked on the 1st, submitted again on the 12th: later than the
        // request first made on the 10th.
        $this->requestAt('resubmitted@example.test', '2026-10-01 09:00:00', '2026-10-12 09:00:00');
        $this->requestAt('newer-first-request@example.test', '2026-10-10 09:00:00');

        $this->assertSame(['resubmitted@example.test', 'newer-first-request@example.test'], $this->listed());
    }

    #[Test]
    public function the_screens_default_sort_is_latest_first(): void
    {
        // What the Request Credit Profile screen sends when it opens.
        $this->requestAt('old@example.test', '2026-10-01 09:00:00');
        $this->requestAt('new@example.test', '2026-10-10 09:00:00');

        $this->assertSame(['new@example.test', 'old@example.test'], $this->listed(['sort_field' => 'updated_at', 'sort_order' => 'desc']));
    }

    #[Test]
    public function a_column_sort_from_the_screen_is_honoured(): void
    {
        $this->requestAt('bravo@example.test', '2026-10-10 09:00:00');
        $this->requestAt('alpha@example.test', '2026-10-01 09:00:00');

        $this->assertSame(['alpha@example.test', 'bravo@example.test'], $this->listed(['sort_field' => 'email', 'sort_order' => 'asc']));
    }

    /**
     * BUG-009 (OP-CRP-04): APPLICANT, EMAIL and PHONE did nothing on the
     * 14-Oct build. Fixed by the sortable list on 15-Oct; pinned here in both
     * directions for every column the screen lets you sort.
     */
    #[Test]
    public function applicant_email_and_phone_sort_both_ways(): void
    {
        $zed = $this->requestAt('alpha@example.test', '2026-10-10 09:00:00');
        $zed->forceFill(['first_name' => 'Zed', 'phone' => '5125550199'])->saveQuietly();
        $amy = $this->requestAt('bravo@example.test', '2026-10-01 09:00:00');
        $amy->forceFill(['first_name' => 'Amy', 'phone' => '5125550100'])->saveQuietly();

        $this->assertSame(['bravo@example.test', 'alpha@example.test'], $this->listed(['sort_field' => 'first_name', 'sort_order' => 'asc']));
        $this->assertSame(['alpha@example.test', 'bravo@example.test'], $this->listed(['sort_field' => 'first_name', 'sort_order' => 'desc']));
        $this->assertSame(['alpha@example.test', 'bravo@example.test'], $this->listed(['sort_field' => 'email', 'sort_order' => 'asc']));
        $this->assertSame(['bravo@example.test', 'alpha@example.test'], $this->listed(['sort_field' => 'email', 'sort_order' => 'desc']));
        $this->assertSame(['bravo@example.test', 'alpha@example.test'], $this->listed(['sort_field' => 'phone', 'sort_order' => 'asc']));
        $this->assertSame(['alpha@example.test', 'bravo@example.test'], $this->listed(['sort_field' => 'phone', 'sort_order' => 'desc']));
    }

    #[Test]
    public function an_unknown_sort_field_falls_back_to_latest_first(): void
    {
        // Only the list's own columns may be sorted on; anything else - a
        // column that does not exist, or an attempt at SQL - is ignored.
        $this->requestAt('old@example.test', '2026-10-01 09:00:00');
        $this->requestAt('new@example.test', '2026-10-10 09:00:00');

        $this->assertSame(['new@example.test', 'old@example.test'], $this->listed(['sort_field' => 'ssn; drop table users', 'sort_order' => 'asc']));
    }

    #[Test]
    public function resubmitting_through_the_borrower_route_brings_the_request_to_the_top(): void
    {
        $this->requestAt('other@example.test', '2026-10-10 09:00:00');
        $mine = $this->requestAt('me@example.test', '2026-10-01 09:00:00');

        Carbon::setTestNow('2026-10-15 09:00:00');
        app(\App\Services\Borrower\Profile\CreditProfileRequestService::class)->store(
            User::create([
                'name' => 'Me',
                'email' => 'me-login@example.test',
                'password' => bcrypt('password'),
                'user_type' => User::BORROWER,
                'partner_id' => $this->host->id,
                'entity_type' => Borrower::class,
                'entity_id' => $mine->borrower_id,
            ]),
            // Exactly what is on file: an unchanged resubmission still counts.
            $mine->only(['first_name', 'last_name', 'address', 'city', 'state', 'zip', 'ssn', 'date_of_birth', 'phone', 'email'])
        );

        $this->assertSame(['me@example.test', 'other@example.test'], $this->listed());
    }
}
