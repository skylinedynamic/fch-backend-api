<?php

namespace Tests\Integration\Console;

use App\Console\Commands\VerifyInternalAue;
use App\Models\Funding\FundingType;
use App\Models\Funding\LenderVisibility;
use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The operator audit of the lender records the Internal AUE routes against:
 * which lenders drop out of the matrix and why, and whether any lender is
 * routable at all. Read-only and offline - no OpenAI call is made.
 */
#[CoversClass(VerifyInternalAue::class)]
class VerifyInternalAueCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->sequence = DB::table('funding_sequencings')->insertGetId(['name' => 'Working Capital', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function category(string $name): int
    {
        $id = LenderProductCategory::create(['name' => $name, 'status' => 'Active'])->id;
        // On SQLite the visibility's category key still points at funding_types.
        FundingType::forceCreate(['id' => $id, 'name' => $name, 'status' => 'Active']);

        return $id;
    }

    private function lender(string $name, array $attributes = []): Lender
    {
        return Lender::create(array_merge([
            'name' => $name,
            'legal_name' => "{$name} LLC",
            'product_categories' => [],
            'contact_name' => 'Lee Lender',
            'contact_email' => strtolower(str_replace(' ', '', $name)) . '@lender.example',
            'contact_phone' => '5550100',
            'status' => Lender::STATUS_ACTIVE,
            'iso_status' => Lender::STATUS_ACTIVE,
        ], $attributes));
    }

    private function program(Lender $lender, int $category, array $attributes = []): void
    {
        LenderVisibility::create(array_merge([
            'lender_id' => $lender->id,
            'funding_sequencing_id' => $this->sequence,
            'lender_product_category_id' => $category,
            'min_fico' => 650,
            'min_tib_months' => 24,
            'min_monthly_revenue' => 10000,
            'allowed_states' => ['TX', 'CA'],
        ], $attributes));
    }

    #[Test]
    public function the_audit_passes_when_a_lender_is_routable_and_names_each_gap(): void
    {
        $mca = $this->category('Merchant Cash Advance');
        $this->program($this->lender('Routable Capital'), $mca);
        $this->lender('Dormant Funding', ['status' => 'Inactive']);
        $this->lender('Direct Only', ['iso_status' => 'Inactive']);
        $this->lender('Empty Shelf');
        $this->program($this->lender('Loose Terms'), $this->category('Equipment Leasing Plus'), ['min_fico' => null, 'min_tib_months' => null, 'min_monthly_revenue' => null, 'allowed_states' => null]);

        $this->artisan('fch:verify-internal-aue', ['--audit' => true])
            ->expectsOutputToContain('Dormant Funding')
            ->expectsOutputToContain('Direct Only')
            ->expectsOutputToContain('Empty Shelf')
            ->expectsOutputToContain('Loose Terms — Equipment Leasing Plus')
            ->expectsOutputToContain('Equipment Leasing Plus')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function the_audit_fails_when_no_lender_can_be_routed_to(): void
    {
        $this->lender('Dormant Funding', ['status' => 'Inactive']);
        $this->lender('Empty Shelf');

        $this->artisan('fch:verify-internal-aue', ['--audit' => true])->assertFailed();
    }

    #[Test]
    public function two_programs_that_reduce_to_the_same_entry_are_flagged(): void
    {
        $mca = $this->category('Merchant Cash Advance');
        $lender = $this->lender('Twin Capital');
        $this->program($lender, $mca);
        $this->program($lender, $mca, ['allowed_states' => ['NY']]);

        $this->artisan('fch:verify-internal-aue', ['--audit' => true])
            ->expectsOutputToContain('Twin Capital — Merchant Cash Advance')
            ->assertSuccessful();
    }

    #[Test]
    public function with_no_pre_qualification_to_check_it_fails(): void
    {
        $this->artisan('fch:verify-internal-aue')->assertFailed();
        $this->artisan('fch:verify-internal-aue', ['application' => 999999])->assertFailed();

        Http::assertNothingSent();
    }
}
