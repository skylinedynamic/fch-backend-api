<?php

namespace Tests\Unit\Lead;

use App\Http\Requests\Api\V1\FCH\Common\Lead\Concerns\LeadPartnerRules;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which Lead Types a lead's details accept: either for the host's lead, only
 * Borrower for another partner's, and only its own type once converted.
 */
#[CoversTrait(LeadPartnerRules::class)]
class LeadPartnerRulesTest extends TestCase
{
    private function accepts(?Partner $partner, string $type, ?string $lockedType = null): bool
    {
        $rules = new class {
            use LeadPartnerRules;

            public function for(?Partner $partner, ?string $lockedType): array
            {
                return $this->leadPartnerRules($partner, null, $lockedType);
            }
        };

        return Validator::make(['lead_type' => $type], ['lead_type' => $rules->for($partner, $lockedType)['lead_type']])->passes();
    }

    private function partner(bool $isHost): Partner
    {
        return new Partner(['is_host' => $isHost]);
    }

    #[Test]
    public function the_hosts_lead_may_be_either_type(): void
    {
        $this->assertTrue($this->accepts($this->partner(true), Lead::PARTNER_TYPE_LEAD));
        $this->assertTrue($this->accepts($this->partner(true), Lead::BORROWER_TYPE_LEAD));
    }

    #[Test]
    public function another_partners_lead_is_only_a_borrower_lead(): void
    {
        $this->assertFalse($this->accepts($this->partner(false), Lead::PARTNER_TYPE_LEAD));
        $this->assertTrue($this->accepts($this->partner(false), Lead::BORROWER_TYPE_LEAD));
    }

    #[Test]
    public function a_converted_lead_keeps_its_type(): void
    {
        $this->assertTrue($this->accepts($this->partner(true), Lead::PARTNER_TYPE_LEAD, Lead::PARTNER_TYPE_LEAD));
        $this->assertFalse($this->accepts($this->partner(true), Lead::BORROWER_TYPE_LEAD, Lead::PARTNER_TYPE_LEAD));
    }
}
