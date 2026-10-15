<?php

namespace Tests\Feature\Profile;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The borrower's Social Security Number on their own profile screen
 * (/borrower/profile/personal-info).
 *
 * The screen used to collect only the last four digits, as `last_4_ssn`, and
 * every save wrote them through the model into ssn_encrypted - the column that
 * holds the full nine-digit SSN the credit profile and funding application
 * store. Saving a name change therefore replaced a borrower's full SSN with its
 * last four digits.
 *
 * Now the screen takes the full SSN (validated by App\Rules\Ssn). The profile
 * read returns the borrower's own SSN for the screen to mask, leaving it out of
 * a save keeps what is on file, and it is required only while no full SSN is on
 * file.
 */
class BorrowerProfileSsnTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Craig',
            'last_name' => 'Rice',
            'email' => 'craig@example.test',
        ]);

        Passport::actingAs(User::create([
            'name' => 'Craig Rice',
            'email' => 'craig@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::BORROWER,
            'partner_id' => $partner->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Craig',
            'last_name' => 'Rice',
            'email' => 'craig@example.test',
            'phone_number' => '5552345678',
            'country' => 'United States',
            'country_code' => '+1',
            'state' => 'Texas',
            'city' => 'Austin',
            'zip_code' => '78701',
            'home_address' => '100 Congress Ave',
            'date_of_birth' => '1985-04-12',
        ], $overrides);
    }

    private function save(array $overrides = [])
    {
        return $this->postJson('/api/v1/borrower/profile/personal-info', $this->payload($overrides));
    }

    // -- reading ------------------------------------------------------------

    /**
     * The profile read carries the borrower's own full SSN; the screen masks it
     * (***-**-6789) and its eye button shows it, with no second request.
     */
    #[Test]
    public function the_profile_returns_the_borrowers_own_full_ssn(): void
    {
        $this->borrower->update(['ssn' => '123-45-6789']);

        $this->getJson('/api/v1/borrower/profile/personal-info')
            ->assertOk()
            ->assertJsonPath('data.ssn', '123456789')
            ->assertJsonMissingPath('data.last_4_ssn');
    }

    #[Test]
    public function only_the_last_four_on_file_comes_back_as_just_those_digits(): void
    {
        // Rows from before the full SSN was collected hold just the last four.
        $this->borrower->update(['last_4_ssn' => '6789']);

        $this->getJson('/api/v1/borrower/profile/personal-info')->assertJsonPath('data.ssn', '6789');
    }

    #[Test]
    public function no_ssn_on_file_reads_as_nothing(): void
    {
        $this->getJson('/api/v1/borrower/profile/personal-info')->assertJsonPath('data.ssn', null);
    }

    #[Test]
    public function the_profile_read_with_the_ssn_is_never_cached(): void
    {
        $this->borrower->update(['ssn' => '123-45-6789']);

        $response = $this->getJson('/api/v1/borrower/profile/personal-info');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // -- saving -------------------------------------------------------------

    #[Test]
    public function a_borrower_saves_their_full_ssn(): void
    {
        $this->save(['ssn' => '234-56-7890'])->assertOk();

        $this->assertSame('234567890', $this->borrower->fresh()->ssn);
    }

    #[Test]
    public function the_ssn_may_be_sent_without_hyphens(): void
    {
        $this->save(['ssn' => '234567890'])->assertOk();

        $this->assertSame('234567890', $this->borrower->fresh()->ssn);
    }

    /**
     * Defect: every profile save wrote the last four digits over the full SSN.
     */
    #[Test]
    public function saving_the_profile_without_an_ssn_keeps_the_full_one_on_file(): void
    {
        $this->borrower->update(['ssn' => '123-45-6789']);

        $this->save(['first_name' => 'Craigory'])->assertOk();

        $borrower = $this->borrower->fresh();
        $this->assertSame('Craigory', $borrower->first_name);
        $this->assertSame('123456789', $borrower->ssn);
    }

    #[Test]
    public function the_old_last_four_field_no_longer_writes_the_ssn(): void
    {
        $this->borrower->update(['ssn' => '123-45-6789']);

        $this->save(['last_4_ssn' => '9999'])->assertOk();

        $this->assertSame('123456789', $this->borrower->fresh()->ssn);
    }

    #[Test]
    public function a_new_ssn_replaces_the_one_on_file(): void
    {
        $this->borrower->update(['ssn' => '123-45-6789']);

        $this->save(['ssn' => '234-56-7890'])->assertOk();

        $this->assertSame('234567890', $this->borrower->fresh()->ssn);
    }

    #[Test]
    public function the_ssn_is_required_while_none_is_on_file(): void
    {
        $this->save()->assertUnprocessable()->assertJsonValidationErrors('ssn');
    }

    #[Test]
    public function the_ssn_is_required_while_only_the_last_four_is_on_file(): void
    {
        $this->borrower->update(['last_4_ssn' => '6789']);

        $this->save()->assertUnprocessable()->assertJsonValidationErrors('ssn');
        $this->save(['ssn' => '123-45-6789'])->assertOk();

        $this->assertSame('123456789', $this->borrower->fresh()->ssn);
    }

    public static function malformedSsns(): array
    {
        return [
            'too short' => ['123-45-678'],
            'too long' => ['123-45-67890'],
            'last four only' => ['6789'],
            'letters' => ['abc-de-fghi'],
            'area 000' => ['000-12-3456'],
            'area 666' => ['666-12-3456'],
            'area 900+' => ['912-34-5678'],
            'group 00' => ['123-00-4567'],
            'serial 0000' => ['123-45-0000'],
        ];
    }

    #[Test]
    #[DataProvider('malformedSsns')]
    public function a_malformed_ssn_is_refused_and_nothing_changes(string $ssn): void
    {
        $this->borrower->update(['ssn' => '123-45-6789']);

        $this->save(['ssn' => $ssn])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ssn' => __('common.ssn_invalid')]);

        $this->assertSame('123456789', $this->borrower->fresh()->ssn);
    }
}
