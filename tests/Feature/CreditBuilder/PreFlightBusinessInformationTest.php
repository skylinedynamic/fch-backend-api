<?php

namespace Tests\Feature\CreditBuilder;

use App\Http\Resources\Borrower\CapitalAccess\HasEvaluatedChecklist;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-043: the pre-flight checklist's "Business Information" item follows the
 * same rule as the profile's business section (ProfileCompletionService), and
 * names the fields still missing.
 */
#[CoversTrait(HasEvaluatedChecklist::class)]
class PreFlightBusinessInformationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;

    /** Every field the profile's Business Details tab requires. */
    private const COMPLETE_BUSINESS = [
        'business_name' => 'Acme LLC',
        'business_email' => 'acme@example.test',
        'business_address' => '1 Main St',
        'business_country' => 'US',
        'business_entity_type' => 'LLC',
        'business_industry_type' => 'Retail',
        'business_state' => 'TX',
        'business_city' => 'Austin',
        'business_postal_code' => '73301',
        'business_start_date' => '2020-01-01',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
    }

    private function businessItem(array $business): array
    {
        $borrower = $this->makeBorrower($this->partner, $business);
        $checklist = new class {
            use HasEvaluatedChecklist;

            public function evaluate($borrower, $raw): array
            {
                return $this->getEvaluatedChecklist($borrower, $raw);
            }
        };

        return $checklist->evaluate($borrower, [CapitalAccess::TASK_BUSINESS_INFORMATION])[0];
    }

    #[Test]
    public function a_missing_business_email_is_named(): void
    {
        $item = $this->businessItem(array_merge(self::COMPLETE_BUSINESS, ['business_email' => null]));

        $this->assertFalse($item['complete']);
        $this->assertSame(['business_email'], $item['missing_fields']);
    }

    #[Test]
    public function it_is_complete_when_the_profile_business_section_is(): void
    {
        // No EIN and no phone: the profile does not require them (BUG-054).
        $item = $this->businessItem(array_merge(self::COMPLETE_BUSINESS, [
            'business_ein' => null,
            'business_phone' => null,
        ]));

        $this->assertTrue($item['complete']);
        $this->assertSame([], $item['missing_fields']);
    }

    #[Test]
    public function it_needs_the_fields_the_profile_needs(): void
    {
        $item = $this->businessItem(array_merge(self::COMPLETE_BUSINESS, [
            'business_postal_code' => null,
            'business_entity_type' => 'Other',
            'business_entity_type_note' => null,
        ]));

        $this->assertFalse($item['complete']);
        $this->assertSame(['zip_code', 'entity_type_details'], $item['missing_fields']);
    }
}
