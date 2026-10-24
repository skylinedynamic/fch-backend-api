<?php

namespace Tests\Unit\Lead;

use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Only the host converting another partner's borrower lead does so on that
 * partner's behalf - the borrower stays the lead partner's.
 */
#[CoversClass(Lead::class)]
class LeadOnBehalfTest extends TestCase
{
    private function userOf(Partner $partner, array $attributes = []): User
    {
        $user = new User(array_merge([
            'partner_id' => $partner->id,
            'user_type' => $partner->is_host ? User::ADMIN : User::PARTNER,
            'entity_type' => Partner::class,
        ], $attributes));

        return $user->setRelation('partner', $partner);
    }

    private function partner(int $id, bool $isHost = false): Partner
    {
        $partner = new Partner(['is_host' => $isHost]);
        $partner->id = $id;

        return $partner;
    }

    private function lead(?int $partnerId, string $type = Lead::BORROWER_TYPE_LEAD): Lead
    {
        return new Lead(['partner_id' => $partnerId, 'lead_type' => $type]);
    }

    #[Test]
    public function the_host_converts_another_partners_borrower_lead_on_its_behalf(): void
    {
        $host = $this->userOf($this->partner(1, true));

        $this->assertTrue($this->lead(2)->convertsOnBehalfOfPartnerFor($host));
    }

    #[Test]
    public function the_hosts_own_a_partner_type_or_an_ownerless_lead_is_not_on_anyones_behalf(): void
    {
        $host = $this->userOf($this->partner(1, true));

        $this->assertFalse($this->lead(1)->convertsOnBehalfOfPartnerFor($host));
        $this->assertFalse($this->lead(2, Lead::PARTNER_TYPE_LEAD)->convertsOnBehalfOfPartnerFor($host));
        $this->assertFalse($this->lead(null)->convertsOnBehalfOfPartnerFor($host));
    }

    #[Test]
    public function other_partners_their_borrowers_and_guests_never_convert_on_a_partners_behalf(): void
    {
        $wlp = $this->userOf($this->partner(2));
        $hostsBorrower = $this->userOf($this->partner(1, true), ['user_type' => User::BORROWER, 'entity_type' => Borrower::class]);

        $this->assertFalse($this->lead(3)->convertsOnBehalfOfPartnerFor($wlp));
        $this->assertFalse($this->lead(2)->convertsOnBehalfOfPartnerFor($hostsBorrower));
        $this->assertFalse($this->lead(2)->convertsOnBehalfOfPartnerFor(null));
    }
}
