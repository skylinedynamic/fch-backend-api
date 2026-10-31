<?php

namespace Tests\Feature\Borrower;

use App\Http\Requests\Api\V1\Borrower\Profile\UpdateBusinessInfoRequest;
use App\Http\Requests\Api\V1\Borrower\Profile\UpdateFinancialInfoRequest;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerBankAccount;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Borrower\Profile\ProfileCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-054 (QA round 2): Business Details locks the EIN, address, phone,
 * website, email, DUNS and start date once they are saved, and Financial
 * Details the revenues, income and bank accounts - a change goes through a
 * Change Request the host approves. The lock was only in the browser: with the
 * read-only attribute removed, the save changed a locked address at once.
 * The API now refuses a change to a saved, locked field. An empty one may be
 * filled in, and the same value sent back again (the form sends every field)
 * is fine.
 *
 * The EIN is no longer required, but once saved it is locked like the rest.
 */
#[CoversClass(UpdateBusinessInfoRequest::class)]
#[CoversClass(UpdateFinancialInfoRequest::class)]
#[CoversClass(ProfileCompletionService::class)]
class LockedProfileFieldsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const SAVED = [
        'business_name' => 'Bo Co',
        'business_ein' => '123456789',
        'business_email' => 'office@boco.example.org',
        'business_phone' => '(512) 867-5309',
        'business_website' => 'https://boco.example.org',
        'duns_number' => '123456789',
        'business_address' => '1 Main St',
        'business_country' => 'United States',
        'business_country_code' => 'US',
        'business_city' => 'Austin',
        'business_state' => 'TX',
        'business_postal_code' => '73301',
        'business_entity_type' => 'LLC',
        'business_industry_type' => 'Retail',
        'business_start_date' => '2020-01-15',
        'monthly_revenue' => 10000,
        'annual_revenue' => 120000,
        'annual_personal_income' => 90000,
    ];

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
    }

    private function signInAs(array $saved = self::SAVED): Borrower
    {
        $user = $this->makeBorrowerUser($this->partner, $saved);
        $this->actingAsUser($user);

        return Borrower::find($user->entity_id);
    }

    /** What the Business Details form sends back: every field, as shown. */
    private function business(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Bo Co',
            'business_ein' => '123456789',
            'business_email' => 'office@boco.example.org',
            'business_phone' => '(512) 867-5309',
            'business_website' => 'https://boco.example.org',
            'duns_number' => '123456789',
            'business_address' => '1 Main St',
            'business_country' => 'United States',
            'business_country_code' => 'US',
            'business_city' => 'Austin',
            'business_state' => 'TX',
            'business_zip_code' => '73301',
            'business_entity_type' => 'LLC',
            'business_industry_type' => 'Retail',
            'business_start_date' => '2020-01-15',
        ], $overrides);
    }

    private function saveBusiness(array $overrides = [])
    {
        return $this->postJson('/api/v1/borrower/profile/business-info', $this->business($overrides));
    }

    public static function lockedBusinessChanges(): array
    {
        return [
            'EIN' => ['business_ein', '987654321'],
            'address' => ['business_address', '2 Other St'],
            'phone' => ['business_phone', '(737) 867-5309'],
            'website' => ['business_website', 'https://other.example.org'],
            'email' => ['business_email', 'new@other.example.org'],
            'DUNS' => ['duns_number', '987654321'],
            'start date' => ['business_start_date', '2019-06-01'],
        ];
    }

    #[Test]
    #[DataProvider('lockedBusinessChanges')]
    public function a_saved_locked_business_field_cannot_be_changed_without_a_change_request(string $field, string $value): void
    {
        $borrower = $this->signInAs();
        $before = $borrower->fresh()->getAttributes();

        $this->saveBusiness([$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame($before[$field], $borrower->fresh()->getAttributes()[$field]);
    }

    #[Test]
    public function the_same_values_sent_back_save_even_written_differently(): void
    {
        $this->signInAs();

        $this->saveBusiness([
            'business_ein' => '12-3456789',
            'business_email' => ' Office@BoCo.example.org ',
            'business_start_date' => '2020-01-15T00:00:00.000Z',
        ])->assertOk();
    }

    #[Test]
    public function fields_that_are_not_locked_still_change(): void
    {
        $borrower = $this->signInAs();

        $this->saveBusiness(['business_name' => 'Bo Co Holdings', 'business_city' => 'Dallas'])->assertOk();

        $this->assertSame('Bo Co Holdings', $borrower->fresh()->business_name);
        $this->assertSame('Dallas', $borrower->fresh()->business_city);
    }

    #[Test]
    public function an_empty_locked_field_may_be_filled_in_once(): void
    {
        $borrower = $this->signInAs(array_merge(self::SAVED, ['duns_number' => null, 'business_ein' => null]));

        $this->saveBusiness(['duns_number' => '987654321', 'business_ein' => '987654321'])->assertOk();

        $this->assertSame('987654321', $borrower->fresh()->duns_number);
        $this->assertSame('987654321', preg_replace('/\D/', '', (string) $borrower->fresh()->business_ein));

        // ...and is locked from then on.
        $this->saveBusiness(['business_ein' => '111111111'])->assertUnprocessable()->assertJsonValidationErrors(['business_ein']);
    }

    #[Test]
    public function the_ein_is_not_required(): void
    {
        $borrower = $this->signInAs(array_merge(self::SAVED, ['business_ein' => null]));

        $this->saveBusiness(['business_ein' => null])->assertOk();
        $this->saveBusiness(['business_ein' => ''])->assertOk();

        $this->assertEmpty($borrower->fresh()->business_ein);
    }

    #[Test]
    public function business_details_are_complete_without_an_ein(): void
    {
        $this->signInAs(array_merge(self::SAVED, ['business_ein' => null]));

        $completion = app(ProfileCompletionService::class)->checkCompletion();

        $this->assertNotContains('business_ein', $completion['business']['missing_fields']);
        $this->assertTrue($completion['business']['is_complete']);
    }

    private function financial(array $overrides = []): array
    {
        return array_merge([
            'currency' => 'USD',
            'monthly_revenue' => 10000,
            'annual_revenue' => 120000,
            'annual_personal_income' => 90000,
        ], $overrides);
    }

    #[Test]
    public function a_saved_revenue_cannot_be_changed_without_a_change_request(): void
    {
        $borrower = $this->signInAs();

        $this->postJson('/api/v1/borrower/profile/financial-info', $this->financial(['monthly_revenue' => 50000]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['monthly_revenue']);
        $this->assertEquals(10000, $borrower->fresh()->monthly_revenue);

        $this->postJson('/api/v1/borrower/profile/financial-info', $this->financial(['monthly_revenue' => '10000.00', 'avg_bank_balance' => 2500]))
            ->assertOk();
    }

    #[Test]
    public function a_saved_bank_account_cannot_be_renamed_without_a_change_request(): void
    {
        $borrower = $this->signInAs();
        $account = BorrowerBankAccount::create(['borrower_id' => $borrower->id, 'bank_name' => 'Chase', 'account_open_date' => '2018-03-01']);

        $this->postJson('/api/v1/borrower/profile/financial-info', $this->financial([
            'bank_accounts' => [['id' => $account->id, 'bank_name' => 'Wells Fargo', 'account_open_date' => '2018-03-01']],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['bank_accounts.0.bank_name']);

        $this->assertSame('Chase', $account->fresh()->bank_name);

        // A new account is not locked.
        $this->postJson('/api/v1/borrower/profile/financial-info', $this->financial([
            'bank_accounts' => [
                ['id' => $account->id, 'bank_name' => 'Chase', 'account_open_date' => '2018-03-01'],
                ['bank_name' => 'Wells Fargo', 'account_open_date' => '2021-05-01'],
            ],
        ]))->assertOk();
    }
}
