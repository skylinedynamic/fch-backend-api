<?php

namespace Tests\Feature\Notification;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Notification\Sms\SmsRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The number a user is texted at comes from the record they log in as - its
 * phone joined with its country_code - not from the users row:
 *
 *   Borrower  borrowers.country_code + borrowers.phone_number
 *   Partner   partners.country_code  + partners.phone_number
 *   Employee  employees.country_code + employees.phone_number_1
 *   other     users.phone_number (users has no country_code)
 */
class SmsRecipientTest extends TestCase
{
    use RefreshDatabase;

    private int $partnerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner@example.test',
            'phone_number' => '(202) 595-5472',
            'country_code' => '+1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(?string $entityType, ?int $entityId, ?string $ownPhone = '(999) 999-9999'): User
    {
        return User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'User',
            'email' => 'user-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'phone_number' => $ownPhone,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function borrower(?string $phone, ?string $countryCode): int
    {
        return DB::table('borrowers')->insertGetId([
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'email' => 'b-' . uniqid() . '@example.test',
            'partner_id' => $this->partnerId,
            'phone_number' => $phone,
            'country_code' => $countryCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function numberFor(User $user): ?string
    {
        return app(SmsRecipient::class)->numberFor($user);
    }

    #[Test]
    public function a_borrower_is_texted_at_the_borrowers_phone_with_its_country_code(): void
    {
        $user = $this->user(Borrower::class, $this->borrower('(546) 456-5465', '+1'));

        $this->assertSame('+1(546) 456-5465', $this->numberFor($user));
    }

    #[Test]
    public function a_partner_is_texted_at_the_partners_phone_with_its_country_code(): void
    {
        $user = $this->user(Partner::class, $this->partnerId);

        $this->assertSame('+1(202) 595-5472', $this->numberFor($user));
    }

    #[Test]
    public function an_employee_is_texted_at_phone_number_1_with_its_country_code(): void
    {
        $employeeId = DB::table('employees')->insertGetId([
            'partner_id' => $this->partnerId,
            'first_name' => 'Em',
            'last_name' => 'Ployee',
            'email' => 'e-' . uniqid() . '@example.test',
            'phone_number_1' => '(312) 450-0000',
            'phone_number_2' => '(312) 450-1111',
            'country_code' => '+1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('+1(312) 450-0000', $this->numberFor($this->user(Employee::class, $employeeId)));
    }

    #[Test]
    public function a_user_without_an_entity_is_texted_at_the_users_own_phone(): void
    {
        $this->assertSame('(999) 999-9999', $this->numberFor($this->user(null, null)));
    }

    #[Test]
    public function a_number_already_in_international_form_is_not_prefixed_twice(): void
    {
        $user = $this->user(Borrower::class, $this->borrower('+1 546 456 5465', '+1'));

        $this->assertSame('+1 546 456 5465', $this->numberFor($user));
    }

    #[Test]
    public function without_a_country_code_the_phone_is_used_as_stored(): void
    {
        $user = $this->user(Borrower::class, $this->borrower('(546) 456-5465', null));

        $this->assertSame('(546) 456-5465', $this->numberFor($user));
    }

    #[Test]
    public function an_entity_without_a_phone_falls_back_to_the_users_own(): void
    {
        $user = $this->user(Borrower::class, $this->borrower(null, '+1'));

        $this->assertSame('(999) 999-9999', $this->numberFor($user));
    }

    #[Test]
    public function no_phone_anywhere_means_no_number(): void
    {
        $user = $this->user(Borrower::class, $this->borrower(null, '+1'), ownPhone: null);

        $this->assertNull($this->numberFor($user));
    }

    #[Test]
    public function the_user_model_routes_sms_through_it(): void
    {
        $user = $this->user(Borrower::class, $this->borrower('(546) 456-5465', '+1'));

        $this->assertSame('+1(546) 456-5465', $user->routeNotificationForSms());
    }
}
