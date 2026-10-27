<?php

namespace Tests\Integration\Profile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Services\Borrower\Profile\ProfileCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Profile completion reports whether Funding is open: only once the borrower's
 * credit report is uploaded (a BorrowerCreditProfile, with
 * `credit_profile_updated` set), the same check that shows them their score.
 */
#[CoversClass(ProfileCompletionService::class)]
class ProfileCompletionFundingAccessTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private function fundingFor(array $borrowerAttributes = [], bool $withReport = false): array
    {
        $user = $this->makeBorrowerUser($this->makeWhiteLabelPartner(), $borrowerAttributes);
        if ($withReport) {
            BorrowerCreditProfile::create(['borrower_id' => $user->entity_id, 'fico_score' => 712]);
        }
        $this->actingAsUser($user);

        return app(ProfileCompletionService::class)->checkCompletion()['funding'];
    }

    #[Test]
    public function locked_without_a_credit_report(): void
    {
        $this->assertFalse($this->fundingFor(['credit_profile_updated' => true])['unlocked']);
    }

    #[Test]
    public function locked_with_a_report_whose_profile_is_not_marked_updated(): void
    {
        $this->assertFalse($this->fundingFor(['credit_profile_updated' => false], withReport: true)['unlocked']);
    }

    #[Test]
    public function open_with_the_credit_report_uploaded(): void
    {
        $funding = $this->fundingFor(['credit_profile_updated' => true], withReport: true);

        $this->assertTrue($funding['unlocked']);
        $this->assertTrue($funding['credit_profile_submitted']);
    }

    #[Test]
    public function the_lock_follows_the_same_check_as_the_credit_score(): void
    {
        $user = $this->makeBorrowerUser($this->makeWhiteLabelPartner(), ['credit_profile_updated' => true]);
        BorrowerCreditProfile::create(['borrower_id' => $user->entity_id]);
        $this->actingAsUser($user);

        $this->assertSame(
            Borrower::find($user->entity_id)->hasVerifiedCreditScore(),
            app(ProfileCompletionService::class)->checkCompletion()['funding']['unlocked']
        );
    }
}
