<?php

namespace Tests\Feature\Borrower;

use App\Http\Requests\Api\V1\Borrower\Profile\StoreChangeRequest;
use App\Http\Requests\Api\V1\Borrower\Profile\UpdatePersonalProfileRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\StoreBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateBorrowerRequest;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Rules\DateOfBirth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-005 (QA round 2): Owner portal › Clients saved a client born 01 Jan 2009
 * (17) on create, and client 153 born 01 Jan 1920 on edit - date_of_birth was
 * only `nullable`. A borrower must be 18 to 120; the same holds on the
 * borrower's own profile and on a change request for the date of birth.
 * (Checked by the platform-wide App\Rules\DateOfBirth, which replaced the
 * client-only AdultDateOfBirth.)
 */
class ClientDateOfBirthTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-21 10:00:00');

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'email' => 'michael@example.test',
            'date_of_birth' => '1985-04-12',
        ]);

        $admin = User::create([
            'name' => 'Host Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::ADMIN,
            'partner_id' => $partner->id,
            'status' => User::STATUS['active'],
        ]);

        Passport::actingAs($admin, [], 'api');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function storedDob(): ?string
    {
        return substr((string) $this->borrower->fresh()->getRawOriginal('date_of_birth'), 0, 10) ?: null;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function outOfRangeDates(): iterable
    {
        // The admin form sends MM-DD-YYYY.
        yield 'QA: 17 years old' => ['01-01-2009', 'common.date_of_birth_minimum_age'];
        yield 'over 120' => ['01-01-1900', 'common.date_of_birth_max_age'];
    }

    #[Test]
    #[DataProvider('outOfRangeDates')]
    public function editing_a_client_refuses_an_age_outside_18_to_120(string $dob, string $message): void
    {
        $age = $message === 'common.date_of_birth_minimum_age' ? 18 : 120;

        $this->postJson("/api/v1/common/borrowers/{$this->borrower->id}", ['date_of_birth' => $dob])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth' => __($message, ['age' => $age])]);

        $this->assertSame('1985-04-12', $this->storedDob());
    }

    #[Test]
    public function editing_a_client_to_an_adult_date_of_birth_is_saved(): void
    {
        $this->postJson("/api/v1/common/borrowers/{$this->borrower->id}", ['date_of_birth' => '03-15-1990'])
            ->assertOk();

        $this->assertSame('1990-03-15', $this->storedDob());
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function dateOfBirthRequests(): iterable
    {
        yield 'admin: add client' => [StoreBorrowerRequest::class];
        yield 'admin: edit client' => [UpdateBorrowerRequest::class];
        yield 'borrower: personal profile' => [UpdatePersonalProfileRequest::class];
    }

    #[Test]
    #[DataProvider('dateOfBirthRequests')]
    public function every_client_date_of_birth_is_checked_with_the_shared_rule(string $requestClass): void
    {
        $rules = (new $requestClass)->rules();

        $this->assertTrue(
            collect((array) $rules['date_of_birth'])->contains(fn ($rule) => $rule instanceof DateOfBirth),
            "{$requestClass} must validate date_of_birth with DateOfBirth"
        );
    }

    #[Test]
    public function a_change_request_for_the_date_of_birth_uses_the_shared_rule(): void
    {
        $request = StoreChangeRequest::create('/', 'POST', ['field_name' => 'date_of_birth', 'new_value' => '2009-01-01']);

        $this->assertTrue(
            collect((array) $request->rules()['new_value'])->contains(fn ($rule) => $rule instanceof DateOfBirth),
            'A date_of_birth change request must validate new_value with DateOfBirth'
        );
    }
}
