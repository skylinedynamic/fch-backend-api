<?php

namespace Tests\Feature\Borrower;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-014: a client's SSN and Business EIN left the API in full on every read
 * of the client, so anyone who could open the client - or its network
 * response - had both numbers. The client's details now carry them masked, and
 * the full number is fetched one field at a time, by anyone who can open the
 * client, with a record of who looked.
 */
class ClientSensitiveFieldRevealTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private const SSN = '123456789';

    private const EIN = '987654321';

    private Partner $host;

    private Borrower $client;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->client = $this->makeBorrower($this->host, ['business_ein' => self::EIN]);
        $this->client->ssn = self::SSN;
        $this->client->save();
        $this->editor = $this->makePartnerAdmin($this->host);
    }

    private function reveal(string $field, ?Borrower $client = null)
    {
        return $this->postJson('/api/v1/common/borrowers/' . ($client ?? $this->client)->id . '/reveal', ['field' => $field]);
    }

    #[Test]
    public function the_clients_details_carry_the_ssn_and_ein_masked(): void
    {
        $this->actingAsUser($this->editor);

        $response = $this->getJson('/api/v1/common/borrowers/' . $this->client->id)
            ->assertOk()
            ->assertJsonPath('data.ssn', '***-**-6789')
            ->assertJsonPath('data.business_ein', '**-***4321');

        $this->assertStringNotContainsString(self::SSN, $response->getContent());
        $this->assertStringNotContainsString(self::EIN, $response->getContent());
    }

    #[Test]
    public function a_client_without_either_number_carries_null(): void
    {
        $bare = $this->makeBorrower($this->host);
        $this->actingAsUser($this->editor);

        $this->getJson('/api/v1/common/borrowers/' . $bare->id)
            ->assertOk()
            ->assertJsonPath('data.ssn', null)
            ->assertJsonPath('data.business_ein', null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function fields(): iterable
    {
        yield 'ssn' => ['ssn', self::SSN];
        yield 'business_ein' => ['business_ein', self::EIN];
    }

    #[Test]
    #[DataProvider('fields')]
    public function the_full_number_is_fetched_by_naming_the_field(string $field, string $digits): void
    {
        $this->actingAsUser($this->editor);

        $this->reveal($field)
            ->assertOk()
            ->assertJsonPath('data.field', $field)
            ->assertJsonPath('data.value', $digits);
    }

    #[Test]
    #[DataProvider('fields')]
    public function each_reveal_is_logged_on_the_client_without_the_number(string $field, string $digits): void
    {
        $this->actingAsUser($this->editor);

        $this->reveal($field)->assertOk();

        $log = ActivityLog::where('subject_type', Borrower::class)
            ->where('subject_id', $this->client->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($this->editor->id, $log->causer_id);
        $this->assertSame(ActivityLog::VISIBILITY_INTERNAL, $log->visibility);
        $this->assertStringContainsString(ActivityLog::fieldLabel($field), $log->event);
        $this->assertStringNotContainsString(substr($digits, -4), json_encode($log->getAttributes()));
    }

    #[Test]
    public function a_number_not_on_file_is_revealed_as_null(): void
    {
        $bare = $this->makeBorrower($this->host);
        $this->actingAsUser($this->editor);

        $this->reveal('ssn', $bare)->assertOk()->assertJsonPath('data.value', null);
    }

    #[Test]
    public function staff_who_can_open_the_client_can_reveal_without_a_further_permission(): void
    {
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->reveal('ssn')->assertOk()->assertJsonPath('data.value', self::SSN);
    }

    #[Test]
    public function another_partners_client_is_not_found(): void
    {
        $other = $this->makeWhiteLabelPartner();
        $this->actingAsUser($this->makePartnerAdmin($other));

        $this->reveal('ssn')->assertNotFound();
    }

    #[Test]
    public function only_the_ssn_or_ein_can_be_asked_for(): void
    {
        $this->actingAsUser($this->editor);

        $this->reveal('date_of_birth')->assertUnprocessable()->assertJsonValidationErrors('field');
        $this->postJson('/api/v1/common/borrowers/' . $this->client->id . '/reveal', [])->assertUnprocessable();
    }

    /**
     * The form shows the masked numbers and may send them back on save. That
     * is not a new number - the one on file stays.
     */
    #[Test]
    public function saving_the_masked_numbers_back_keeps_the_ones_on_file(): void
    {
        $this->actingAsUser($this->editor);

        $this->postJson('/api/v1/common/borrowers/' . $this->client->id, [
            'first_name' => 'Renamed',
            'ssn' => '***-**-6789',
            'business_ein' => '**-***4321',
        ])->assertOk()->assertJsonPath('data.ssn', '***-**-6789');

        $client = $this->client->fresh();
        $this->assertSame('Renamed', $client->first_name);
        $this->assertSame(self::SSN, $client->ssn);
        $this->assertSame(self::EIN, $client->business_ein);
    }

    #[Test]
    public function a_new_number_typed_in_is_still_saved(): void
    {
        $this->actingAsUser($this->editor);

        $this->postJson('/api/v1/common/borrowers/' . $this->client->id, [
            'ssn' => '234-56-7890',
            'business_ein' => '12-3456789',
        ])->assertOk()->assertJsonPath('data.ssn', '***-**-7890')->assertJsonPath('data.business_ein', '**-***6789');

        $client = $this->client->fresh();
        $this->assertSame('234567890', $client->ssn);
        $this->assertSame('123456789', $client->business_ein);
    }
}
