<?php

namespace Tests\Feature\Payment;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-195 (decision D8): the Payments list COMPANY column showed "-" on every
 * row. The list loaded the payer's partner and borrower with only their name
 * columns, so `company_name` / `business_name` were never read.
 */
class PaymentListCompanyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner(['company_name' => 'Host Holdings LLC']);
    }

    private function payment(Partner $partner, string $entityType, int $entityId, array $extra = []): Payment
    {
        return Payment::create(array_merge([
            'partner_id' => $partner->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'transaction_id' => 'TX-' . uniqid(),
            'amount' => 10,
            'currency' => 'USD',
            'status' => Payment::STATUS_SUCCEEDED,
            'transaction_date' => now(),
        ], $extra));
    }

    /** Company per payer name, as the list returns it. */
    private function companies(User $viewer): array
    {
        $this->actingAsUser($viewer);

        return collect($this->postJson('/api/v1/common/payments/fetch-payments', ['per_page' => 50])->assertOk()->json('data'))
            ->mapWithKeys(fn ($row) => [$row['name'] => $row['company']])
            ->all();
    }

    #[Test]
    public function the_host_sees_each_payers_company(): void
    {
        $partner = $this->makeWhiteLabelPartner(['full_name' => 'Wanda Partner', 'company_name' => 'Wanda Co']);
        $this->payment($partner, Partner::class, $partner->id, ['is_partner_paying' => true]);

        $client = $this->makeBorrower($this->host, ['first_name' => 'Mia', 'last_name' => 'Client', 'business_name' => 'Mia Bakery LLC']);
        $this->payment($this->host, Borrower::class, $client->id, ['borrower_id' => $client->id]);

        $this->assertSame(
            ['Wanda Partner' => 'Wanda Co', 'Mia Client' => 'Mia Bakery LLC'],
            $this->companies($this->makePartnerAdmin($this->host))
        );
    }

    #[Test]
    public function a_partner_sees_its_own_company_and_its_clients_company(): void
    {
        $partner = $this->makeWhiteLabelPartner(['full_name' => 'Wanda Partner', 'company_name' => 'Wanda Co']);
        $this->payment($partner, Partner::class, $partner->id, ['is_partner_paying' => true]);

        $client = $this->makeBorrower($partner, ['first_name' => 'Ola', 'last_name' => 'Client', 'business_name' => 'Ola Studio']);
        $this->payment($partner, Borrower::class, $client->id, ['borrower_id' => $client->id, 'is_partner_paying' => false]);

        $this->assertSame(
            ['Wanda Partner' => 'Wanda Co', 'Ola Client' => 'Ola Studio'],
            $this->companies($this->makePartnerAdmin($partner))
        );
    }

    #[Test]
    public function a_payer_without_a_company_has_none(): void
    {
        $client = $this->makeBorrower($this->host, ['first_name' => 'No', 'last_name' => 'Company', 'business_name' => null]);
        $this->payment($this->host, Borrower::class, $client->id, ['borrower_id' => $client->id]);

        $this->assertSame(['No Company' => null], $this->companies($this->makePartnerAdmin($this->host)));
    }
}
