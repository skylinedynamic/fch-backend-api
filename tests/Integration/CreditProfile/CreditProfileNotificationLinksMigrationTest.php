<?php

namespace Tests\Integration\CreditProfile;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The migration that points the host's stored credit profile notifications at
 * the request they are about.
 *
 * "Update requested" was sent linking to the list, and "new request" may link to
 * a request the one-per-borrower merge removed. A stored notification keeps its
 * link, so the migration repairs it - matched by the borrower name in its text,
 * and only where the name belongs to exactly one request.
 */
class CreditProfileNotificationLinksMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_15_130000_link_credit_profile_notifications_to_their_request.php';

    private const LIST = '/intake-review/credit-profile-requests';

    private int $partnerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partnerId = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ])->id;
    }

    private function migrate(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    /** A borrower with a credit profile request; returns the request id. */
    private function requestFor(string $first, string $last): int
    {
        $borrowerId = Borrower::create([
            'partner_id' => $this->partnerId,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => $first,
            'last_name' => $last,
            'email' => Str::lower("{$first}.{$last}." . Str::random(4) . '@example.test'),
        ])->id;

        return DB::table('credit_profile_requests')->insertGetId([
            'borrower_id' => $borrowerId,
            'first_name' => $first,
            'last_name' => $last,
            'address' => '1 Main St',
            'city' => 'Austin',
            'state' => 'Texas',
            'zip' => '78701',
            'ssn' => '123456789',
            'date_of_birth' => '1990-01-01',
            'phone' => '5125550100',
            'email' => 'row@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function notification(string $type, string $body, ?string $url): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => $type,
            'notifiable_type' => Partner::class,
            'notifiable_id' => $this->partnerId,
            'data' => json_encode(['title' => 'x', 'body' => $body, 'url' => $url, 'icon' => 'ri-file-user-line', 'category' => 'document']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function urlOf(string $id): ?string
    {
        return json_decode(DB::table('notifications')->where('id', $id)->value('data'), true)['url'] ?? null;
    }

    #[Test]
    public function an_update_request_linking_to_the_list_now_opens_the_borrowers_request(): void
    {
        $requestId = $this->requestFor('Odessa', 'Mccall');
        $id = $this->notification('host.credit_profile.update_requested', 'Odessa Mccall asked to update their credit profile.', self::LIST);

        $this->migrate();

        $this->assertSame(self::LIST . "/{$requestId}", $this->urlOf($id));
    }

    #[Test]
    public function a_new_request_linking_to_a_removed_request_now_opens_the_kept_one(): void
    {
        $requestId = $this->requestFor('Odessa', 'Mccall');
        $id = $this->notification('host.credit_profile.requested', 'Odessa Mccall submitted a credit profile request.', self::LIST . '/99999');

        $this->migrate();

        $this->assertSame(self::LIST . "/{$requestId}", $this->urlOf($id));
    }

    #[Test]
    public function a_link_that_already_works_is_left_alone(): void
    {
        $requestId = $this->requestFor('Odessa', 'Mccall');
        $this->requestFor('Other', 'Person');
        $id = $this->notification('host.credit_profile.requested', 'Odessa Mccall submitted a credit profile request.', self::LIST . "/{$requestId}");

        $this->migrate();

        $this->assertSame(self::LIST . "/{$requestId}", $this->urlOf($id));
    }

    #[Test]
    public function a_name_shared_by_two_borrowers_keeps_its_link(): void
    {
        $this->requestFor('Sam', 'Lee');
        $this->requestFor('Sam', 'Lee');
        $id = $this->notification('host.credit_profile.update_requested', 'Sam Lee asked to update their credit profile.', self::LIST);

        $this->migrate();

        $this->assertSame(self::LIST, $this->urlOf($id));
    }

    #[Test]
    public function a_borrower_without_a_request_keeps_the_list(): void
    {
        $id = $this->notification('host.credit_profile.update_requested', 'Nobody Here asked to update their credit profile.', self::LIST);

        $this->migrate();

        $this->assertSame(self::LIST, $this->urlOf($id));
    }

    #[Test]
    public function other_notifications_are_not_touched(): void
    {
        $this->requestFor('Odessa', 'Mccall');
        $id = $this->notification('borrower.credit_profile.updated', 'Odessa Mccall asked to update their credit profile.', self::LIST);

        $this->migrate();

        $this->assertSame(self::LIST, $this->urlOf($id));
    }
}
