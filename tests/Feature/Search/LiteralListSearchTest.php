<?php

namespace Tests\Feature\Search;

use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-002: searching Clients, Leads or Partners for "%" or "_" alone listed
 * every record, because the term went into LIKE as typed. The search text is
 * now matched literally.
 */
class LiteralListSearchTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner(['company_name' => 'Host Co', 'full_name' => 'Host Owner']);
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'partner_id' => $this->host->id,
            'company_name' => 'Lead Co',
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'phone_number' => '(555) 123-4567',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => 'New',
            'probability' => 'High',
        ], $attributes));
    }

    #[Test]
    public function clients_are_not_all_matched_by_a_percent_or_an_underscore(): void
    {
        $this->makeBorrower($this->host, ['business_name' => 'Plain Bakery']);
        $percent = $this->makeBorrower($this->host, ['business_name' => '50% Off Outlet']);

        $this->assertSame(0, Borrower::search('_')->count());
        $this->assertSame([$percent->id], Borrower::search('%')->pluck('id')->all());
        $this->assertSame([$percent->id], Borrower::search('50%')->pluck('id')->all());
    }

    #[Test]
    public function leads_are_not_all_matched_by_a_percent_or_an_underscore(): void
    {
        $this->lead();
        $underscore = $this->lead(['company_name' => 'snake_case Ltd']);

        $this->assertSame(0, Lead::search('%')->count());
        $this->assertSame([$underscore->id], Lead::search('_')->pluck('id')->all());
    }

    #[Test]
    public function partners_are_not_all_matched_by_a_percent_or_an_underscore(): void
    {
        $this->makeCertifiedPartner(['company_name' => 'Acme Lending']);

        $this->assertSame(0, Partner::search('%')->count());
        $this->assertSame(0, Partner::search('_')->count());
        $this->assertSame(1, Partner::search('Acme')->count());
    }
}
