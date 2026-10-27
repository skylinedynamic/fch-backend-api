<?php

namespace Tests\Integration\Profile;

use App\Services\Borrower\Profile\ProfileCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The Financial section of profile completion (the Complete Your Profile
 * popup and the walkthrough's first step) does not wait for Annual Personal
 * Income or Average Monthly Deposits: both are optional on the tab.
 */
#[CoversClass(ProfileCompletionService::class)]
class ProfileCompletionOptionalFinancialFieldsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private function completionFor(array $financials): array
    {
        $user = $this->makeBorrowerUser($this->makeWhiteLabelPartner(), $financials);
        $this->actingAsUser($user);

        return app(ProfileCompletionService::class)->checkCompletion();
    }

    #[Test]
    public function financial_is_complete_without_either_field(): void
    {
        $completion = $this->completionFor([
            'monthly_revenue' => 10000,
            'annual_revenue' => 120000,
            'annual_personal_income' => null,
            'average_monthly_deposits' => null,
        ]);

        $this->assertTrue($completion['financial']['is_complete']);
        $this->assertSame([], $completion['financial']['missing_fields']);
    }

    #[Test]
    public function revenue_of_zero_counts_as_filled_in(): void
    {
        $completion = $this->completionFor([
            'monthly_revenue' => 0,
            'annual_revenue' => 0,
        ]);

        $this->assertTrue($completion['financial']['is_complete']);
    }

    #[Test]
    public function revenue_is_still_required(): void
    {
        $completion = $this->completionFor([
            'monthly_revenue' => null,
            'annual_revenue' => null,
            'annual_personal_income' => null,
            'average_monthly_deposits' => null,
        ]);

        $this->assertFalse($completion['financial']['is_complete']);
        $this->assertSame(['monthly_revenue', 'annual_revenue'], $completion['financial']['missing_fields']);
    }
}
