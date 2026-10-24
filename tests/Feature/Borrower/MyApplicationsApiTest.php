<?php

namespace Tests\Feature\Borrower;

use App\Http\Controllers\Api\V1\Borrower\MyApplication\MyApplicationController;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * "My applications" in the borrower portal: the signed-in borrower's own
 * submitted pre-qualifications and funding applications (drafts excluded),
 * and the co-borrower form check.
 */
#[CoversClass(MyApplicationController::class)]
class MyApplicationsApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
    }

    private function preQualification(Borrower $borrower, string $status): PreQualificationRequest
    {
        return PreQualificationRequest::create(['borrower_id' => $borrower->id, 'partner_id' => $borrower->partner_id, 'status' => $status]);
    }

    /** @return array<int, int> */
    private function listed(string $url): array
    {
        return collect($this->postJson($url)->assertOk()->json('data'))->pluck('id')->all();
    }

    #[Test]
    public function a_borrower_lists_only_their_own_submitted_pre_qualifications(): void
    {
        $me = Borrower::find($this->me->entity_id);
        $submitted = $this->preQualification($me, PreQualificationRequest::PENDING);
        $this->preQualification($me, PreQualificationRequest::ON_HOLD);
        $this->preQualification($this->makeBorrower($this->partner), PreQualificationRequest::PENDING);
        $this->actingAsUser($this->me);

        $this->assertSame([$submitted->id], $this->listed('/api/v1/borrower/myapplication/pre-qualification'));
    }

    /**
     * Defect (BUG-027, CP-MYAPP-01): an application the borrower had started
     * (On Hold) was left out, so My Applications said "No funding applications
     * found." while the funding request said one had been started. It is
     * listed - as On Hold, with the request it continues from.
     */
    #[Test]
    public function a_borrower_lists_their_own_funding_applications_including_one_started_on_hold(): void
    {
        $submitted = FundingApplication::forceCreate(['borrower_id' => $this->me->entity_id, 'status' => FundingApplication::CLOSED_COMPLETED, 'created_at' => now()->subDay()]);
        $started = FundingApplication::forceCreate(['borrower_id' => $this->me->entity_id, 'status' => FundingApplication::ON_HOLD, 'funding_request_id' => 40, 'created_at' => now()]);
        FundingApplication::forceCreate(['borrower_id' => $this->makeBorrower($this->partner)->id, 'status' => FundingApplication::CLOSED_COMPLETED]);
        $this->actingAsUser($this->me);

        $this->assertSame([$started->id, $submitted->id], $this->listed('/api/v1/borrower/myapplication/funding-application'));
        $this->postJson('/api/v1/borrower/myapplication/funding-application')
            ->assertJsonPath('data.0.status', FundingApplication::ON_HOLD)
            ->assertJsonPath('data.0.funding_request_id', 40);
    }

    #[Test]
    public function the_co_borrower_check_validates_the_form(): void
    {
        $this->actingAsUser($this->me);
        $coBorrower = [
            'first_name' => 'Co',
            'last_name' => 'Owner',
            'role' => 'Partner',
            'ownership' => 25,
            'email' => 'co@example.org',
            'mobile_phone' => '(555) 210-0100',
            'date_of_birth' => '1980-02-03',
            'ssn' => '123456789',
            'street_address' => '1 Main St',
            'country' => 'United States',
            'state' => 'TX',
            'city' => 'Austin',
            'zip_code' => '73301',
        ];

        $this->postJson('/api/v1/borrower/myapplication/funding-application/co-borrower-validation', $coBorrower)->assertOk();
        $this->postJson('/api/v1/borrower/myapplication/funding-application/co-borrower-validation', ['ssn' => '12345'] + $coBorrower)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ssn');
    }

    /**
     * Defect (O28): ownership was `numeric` with no bounds, so -5% or 150% of a
     * business was accepted - on both the borrower and the partner form.
     */
    #[Test]
    public function a_co_borrowers_ownership_is_a_percentage_from_0_to_100(): void
    {
        $coBorrower = [
            'first_name' => 'Co', 'last_name' => 'Owner', 'role' => 'Partner', 'ownership' => 25,
            'email' => 'co@example.org', 'mobile_phone' => '(555) 210-0100', 'date_of_birth' => '1980-02-03',
            'ssn' => '123456789', 'street_address' => '1 Main St', 'country' => 'United States',
            'state' => 'TX', 'city' => 'Austin', 'zip_code' => '73301',
        ];
        $partnerAdmin = $this->makePartnerAdmin($this->makeWhiteLabelPartner());

        foreach ([
            [$this->me, '/api/v1/borrower/myapplication/funding-application/co-borrower-validation'],
            [$partnerAdmin, '/api/v1/common/funding-application/co-borrower-validation'],
        ] as [$user, $url]) {
            $this->actingAsUser($user);
            foreach ([-5, 100.5, 150] as $bad) {
                $this->postJson($url, ['ownership' => $bad] + $coBorrower)
                    ->assertUnprocessable()->assertJsonValidationErrors('ownership');
            }
            foreach ([0, 100] as $edge) {
                $this->postJson($url, ['ownership' => $edge] + $coBorrower)->assertJsonMissingValidationErrors('ownership');
            }
        }
    }

    /** Home phone is optional on both forms: left empty, it is no error. */
    #[Test]
    public function an_empty_home_phone_is_not_an_error(): void
    {
        $coBorrower = [
            'first_name' => 'Co', 'last_name' => 'Owner', 'role' => 'Partner', 'ownership' => 25,
            'email' => 'co@example.org', 'mobile_phone' => '(555) 210-0100', 'date_of_birth' => '1980-02-03',
            'ssn' => '123456789', 'street_address' => '1 Main St', 'country' => 'United States',
            'state' => 'TX', 'city' => 'Austin', 'zip_code' => '73301', 'home_phone' => '',
        ];
        $partnerAdmin = $this->makePartnerAdmin($this->makeWhiteLabelPartner());

        foreach ([
            [$this->me, '/api/v1/borrower/myapplication/funding-application/co-borrower-validation'],
            [$partnerAdmin, '/api/v1/common/funding-application/co-borrower-validation'],
        ] as [$user, $url]) {
            $this->actingAsUser($user);
            $this->postJson($url, $coBorrower)->assertJsonMissingValidationErrors('home_phone');
            $this->postJson($url, ['home_phone' => '(555) 21'] + $coBorrower)
                ->assertUnprocessable()->assertJsonValidationErrors('home_phone');
        }
    }
}
