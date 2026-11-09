<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\FCH\Common\ActivityLog\ActivityLogController;
use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-001 (QA round 2): opening a client profile with an id that is not a
 * client (/clients/abc, /clients/999999999). The profile counters answered a
 * non-numeric id with a 500 (the int parameter threw before the controller's
 * try), and the activity list cast "abc" to 0 - which MySQL matches against
 * every entry whose borrower_id is JSON null - so the panel filled with
 * unrelated activity. A non-numeric id is now not found, and an activity
 * filter id that is not a positive whole number is refused.
 */
#[CoversClass(BorrowerController::class)]
#[CoversClass(ActivityLogController::class)]
class ClientProfileInvalidIdTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostAdmin = $this->makePartnerAdmin($this->makeHostPartner());
    }

    public static function idsThatAreNotAClient(): array
    {
        return [
            'not a number' => ['abc'],
            'no such client' => ['999999999'],
        ];
    }

    #[Test]
    #[DataProvider('idsThatAreNotAClient')]
    public function the_profile_counters_of_an_id_that_is_not_a_client_are_not_found(string $id): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/borrowers-profile-states-details-count/{$id}")
            ->assertNotFound();
    }

    #[Test]
    #[DataProvider('idsThatAreNotAClient')]
    public function the_profile_of_an_id_that_is_not_a_client_is_not_found(string $id): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->getJson("/api/v1/common/borrowers/{$id}")
            ->assertNotFound();
    }

    public static function activityFilters(): array
    {
        return [
            'borrower_id' => ['borrower_id'],
            'partner_id' => ['partner_id'],
            'lead_id' => ['lead_id'],
            'funding_request_id' => ['funding_request_id'],
            'funding_application_id' => ['funding_application_id'],
        ];
    }

    #[Test]
    #[DataProvider('activityFilters')]
    public function the_activity_list_refuses_a_filter_id_that_is_not_a_number(string $filter): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/activity-logs/fetch-all-activities', [$filter => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$filter]);
    }

    #[Test]
    public function the_activity_list_still_takes_a_numeric_filter_id_or_none(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/activity-logs/fetch-all-activities', ['borrower_id' => 999999999, 'limit' => 15, 'page' => 1])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/common/activity-logs/fetch-all-activities', ['borrower_id' => '7'])
            ->assertOk();

        $this->postJson('/api/v1/common/activity-logs/fetch-all-activities', ['limit' => 15, 'page' => 1])
            ->assertOk();
    }
}
