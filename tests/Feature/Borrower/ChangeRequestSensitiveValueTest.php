<?php

namespace Tests\Feature\Borrower;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-014: the change request queue listed an SSN or EIN change with both
 * numbers in full. They are masked like the client's own details, and the
 * reviewer fetches the pair in full when deciding - logged on the client.
 */
class ChangeRequestSensitiveValueTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Borrower $client;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reviewer = $this->makeStaffUser($this->makeHostPartner());
        $this->client = $this->makeBorrower($this->makeWhiteLabelPartner());
    }

    private function request(string $field, ?string $old, string $new): BorrowerChangeRequest
    {
        return BorrowerChangeRequest::create([
            'borrower_id' => $this->client->id,
            'partner_id' => $this->client->partner_id,
            'field_name' => $field,
            'db_field_name' => $field,
            'visible_field_name' => ActivityLog::fieldLabel($field),
            'old_value' => $old,
            'new_value' => $new,
            'status' => BorrowerChangeRequest::STATUS_PENDING,
        ]);
    }

    private function listed(): array
    {
        $this->actingAsUser($this->reviewer);
        $response = $this->postJson('/api/v1/admin/change-requests')->assertOk();

        return collect($response->json('data'))->keyBy('field_name')->all();
    }

    #[Test]
    public function an_ssn_or_ein_change_is_listed_masked(): void
    {
        $this->request('ssn', '123456789', '234567890');
        $this->request('business_ein', '987654321', '123450000');
        $this->request('business_name', 'Old Co', 'New Co');

        $rows = $this->listed();

        $this->assertSame(['***-**-6789', '***-**-7890'], [$rows['ssn']['old_value'], $rows['ssn']['new_value']]);
        $this->assertSame(['**-***4321', '**-***0000'], [$rows['business_ein']['old_value'], $rows['business_ein']['new_value']]);
        $this->assertSame(['Old Co', 'New Co'], [$rows['business_name']['old_value'], $rows['business_name']['new_value']]);
        $this->assertTrue($rows['ssn']['is_masked']);
        $this->assertFalse($rows['business_name']['is_masked']);
    }

    #[Test]
    public function the_reviewer_reveals_both_numbers_and_the_look_is_logged(): void
    {
        $request = $this->request('business_ein', '987654321', '123450000');
        $this->actingAsUser($this->reviewer);

        $this->postJson("/api/v1/admin/change-requests/{$request->id}/reveal")
            ->assertOk()
            ->assertJsonPath('data.old_value', '987654321')
            ->assertJsonPath('data.new_value', '123450000');

        $log = ActivityLog::where('subject_type', Borrower::class)->where('subject_id', $this->client->id)->latest('id')->firstOrFail();
        $this->assertSame($this->reviewer->id, $log->causer_id);
        $this->assertStringContainsString('Business EIN', $log->event);
        $this->assertStringNotContainsString('4321', json_encode($log->getAttributes()));
        $this->assertStringNotContainsString('0000', json_encode($log->getAttributes()));
    }

    #[Test]
    public function a_field_that_is_not_masked_has_nothing_to_reveal(): void
    {
        $request = $this->request('business_name', 'Old Co', 'New Co');
        $this->actingAsUser($this->reviewer);

        $this->postJson("/api/v1/admin/change-requests/{$request->id}/reveal")->assertUnprocessable();
    }

    #[Test]
    public function only_the_host_team_can_reveal(): void
    {
        $request = $this->request('ssn', '123456789', '234567890');
        $this->actingAsUser($this->makePartnerAdmin($this->makeWhiteLabelPartner()));

        $this->postJson("/api/v1/admin/change-requests/{$request->id}/reveal")->assertForbidden();
    }
}
