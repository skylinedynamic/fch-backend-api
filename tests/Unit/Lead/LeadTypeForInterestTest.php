<?php

namespace Tests\Unit\Lead;

use App\Models\Lead\Lead;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** "What brings you here today?" on the website contact page decides the lead type. */
#[CoversClass(Lead::class)]
class LeadTypeForInterestTest extends TestCase
{
    #[Test]
    public function the_two_answers_are_the_lead_types(): void
    {
        $this->assertSame([Lead::BORROWER_TYPE_LEAD, Lead::PARTNER_TYPE_LEAD], Lead::WEBSITE_INTERESTS);
        $this->assertSame(['Borrower', 'Partner'], Lead::WEBSITE_INTERESTS);
    }

    #[Test]
    public function a_partnership_enquiry_is_a_partner_lead(): void
    {
        $this->assertSame(Lead::PARTNER_TYPE_LEAD, Lead::leadTypeForInterest(Lead::PARTNER_TYPE_LEAD));
    }

    #[Test]
    public function a_client_enquiry_or_no_answer_is_a_borrower_lead(): void
    {
        $this->assertSame(Lead::BORROWER_TYPE_LEAD, Lead::leadTypeForInterest(Lead::BORROWER_TYPE_LEAD));
        $this->assertSame(Lead::BORROWER_TYPE_LEAD, Lead::leadTypeForInterest(null));
    }
}
