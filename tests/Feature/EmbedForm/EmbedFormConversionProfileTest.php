<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Borrower\Borrower;
use App\Models\Common\Permission;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Converting an application carries what the applicant already entered onto the
 * borrower, so the borrower portal does not ask for the same answers again.
 */
class EmbedFormConversionProfileTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function converting_fills_the_borrower_profile_from_the_application(): void
    {
        Mail::fake();
        Notification::fake();

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host',
            'full_name' => 'Host',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        $config = EmbedFormConfig::create([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Business Funding Application',
            'is_active' => true,
        ]);

        $submission = EmbedFormSubmission::create([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'form_config_id' => $config->id,
            'status' => EmbedFormSubmission::STATUS_NEW,
            'first_name' => 'Ada',
            'last_name' => 'Applicant',
            'email' => 'ada-' . uniqid() . '@example.test',
            'phone' => '+15553456789',
            'date_of_birth' => '1985-05-15',
            'business_name' => 'Ada Holdings',
            'business_email' => 'accounts@ada-holdings.example.test',
            'business_address' => '123 Commerce Way',
            'business_country' => 'United States',
            'business_country_code' => '+1',
            'business_city' => 'Albany',
            'business_state' => 'New York',
            'business_postal_code' => '10001',
            'entity_type' => 'LLC',
            'industry_type' => 'Advertising, Marketing & Media',
            'years_in_business' => 'Under 6 months',
            'number_of_employees' => 12,
            'monthly_revenue' => 45000,
            'annual_revenue' => 540000,
            'has_business_debt' => true,
            'debt_balance' => 25000,
        ]);

        $id = DB::table('users')->insertGetId([
            'name' => 'Host Admin',
            'email' => 'admin-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::ADMIN,
            'partner_id' => $partner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::with('permissions', 'roles')->findOrFail($id);
        foreach (['view application', 'convert application'] as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Passport::actingAs(User::with('permissions', 'roles', 'partner')->findOrFail($id), [], 'api');

        $this->postJson("/api/v1/common/embed-forms/submissions/{$submission->id}/convert-to-borrower")
            ->assertOk()
            ->assertJsonPath('data.is_existing_borrower', false);

        $borrower = Borrower::where('email', $submission->email)->firstOrFail();

        $this->assertSame('1985-05-15', substr((string) $borrower->getRawOriginal('date_of_birth'), 0, 10));
        $this->assertSame('123 Commerce Way', $borrower->business_address);
        $this->assertSame('United States', $borrower->business_country);
        $this->assertSame('+1', $borrower->business_country_code);
        $this->assertSame('Albany', $borrower->business_city);
        $this->assertSame('New York', $borrower->business_state);
        $this->assertSame('10001', $borrower->business_postal_code);
        $this->assertSame('LLC', $borrower->business_entity_type);
        $this->assertSame('Advertising, Marketing & Media', $borrower->business_industry_type);
        // Read as stored: the model presents this column through a cast.
        $this->assertSame('Under 6 months', (string) DB::table('borrowers')->where('id', $borrower->id)->value('years_in_business'));
        $this->assertEquals(12, $borrower->number_of_employees);
        $this->assertEquals(45000, $borrower->monthly_revenue);
        $this->assertEquals(540000, $borrower->annual_revenue);
        $this->assertEquals(25000, $borrower->current_business_debt);

        $this->assertSame(EmbedFormSubmission::STATUS_CONVERTED, $submission->fresh()->status);
    }
}
