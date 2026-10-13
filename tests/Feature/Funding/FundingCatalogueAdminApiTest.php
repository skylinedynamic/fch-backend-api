<?php

namespace Tests\Feature\Funding;

use App\Http\Controllers\Api\V1\FCH\Admin\Funding\FundingGroupController;
use App\Http\Controllers\Api\V1\FCH\Admin\Funding\FundingSequencingController;
use App\Http\Controllers\Api\V1\FCH\Admin\Funding\FundingTypeController;
use App\Http\Controllers\Api\V1\FCH\Admin\Lender\LenderController;
use App\Http\Controllers\Api\V1\FCH\Admin\Lender\LenderProductCategoryController;
use App\Models\Funding\FundingGroup;
use App\Models\Funding\FundingSequencing;
use App\Models\Funding\FundingType;
use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The host team's funding catalogue: funding groups and types, lenders and
 * their product categories, and the funding sequences (the options borrowers
 * are shown) with each lender's visibility rules. Admin routes: host team only.
 */
#[CoversClass(FundingGroupController::class)]
#[CoversClass(FundingTypeController::class)]
#[CoversClass(LenderController::class)]
#[CoversClass(LenderProductCategoryController::class)]
#[CoversClass(FundingSequencingController::class)]
class FundingCatalogueAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->admin = $this->makeStaffUser($this->host);
    }

    private function lender(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Capital',
            'legal_name' => 'Acme Capital LLC',
            'product_categories' => [],
            'contact_name' => 'Lee Lender',
            'contact_title' => 'Partnerships',
            'contact_email' => 'lee@acme.example.org',
            'contact_phone' => '5550100',
            'status' => Lender::STATUS_ACTIVE,
        ], $overrides);
    }

    private function sequence(array $overrides = []): array
    {
        return array_merge([
            'name' => 'SBA 7(a)',
            'subtitle' => 'Long-term',
            'funding_group_id' => FundingGroup::create(['name' => 'Loans ' . uniqid(), 'status' => 'Active'])->id,
            'funding_type_id' => $this->productCategory(),
            'min_amount' => 10000,
            'max_amount' => 500000,
            'icon' => 'bank',
            'status' => 'Active',
            'readiness_factors' => ['Two years in business'],
        ], $overrides);
    }

    /**
     * A product category, the table funding_type_id points at in production.
     * Under the suite's SQLite the migration that repoints that foreign key
     * (MySQL-only DROP FOREIGN KEY) is a no-op and the key still names
     * funding_types, so a funding type with the same id is made alongside.
     */
    private function productCategory(): int
    {
        $id = LenderProductCategory::create(['name' => 'Term ' . uniqid(), 'status' => 'Active'])->id;
        FundingType::forceCreate(['id' => $id, 'name' => 'Type ' . uniqid(), 'status' => 'Active']);

        return $id;
    }

    private function visibility(int $lenderId, int $categoryId, array $overrides = []): array
    {
        return array_merge([
            'lender_id' => $lenderId,
            'lender_product_category_id' => $categoryId,
            'min_fico' => 650,
            'min_tib_months' => 24,
            'min_monthly_revenue' => 10000,
            'min_monthly_deposits' => 8000,
        ], $overrides);
    }

    #[Test]
    public function the_catalogue_is_host_team_only(): void
    {
        $partner = $this->makeWhiteLabelPartner();
        $this->actingAsUser($this->makePartnerAdmin($partner));

        $this->getJson('/api/v1/admin/funding-groups')->assertForbidden();
        $this->getJson('/api/v1/admin/lenders/get/all')->assertForbidden();
        $this->postJson('/api/v1/admin/funding-sequencings', $this->sequence())->assertForbidden();
    }

    #[Test]
    public function groups_and_types_are_managed(): void
    {
        $this->actingAsUser($this->admin);

        $group = $this->postJson('/api/v1/admin/funding-groups', ['name' => 'Loans'])->assertCreated()->json('data.id');
        $type = $this->postJson('/api/v1/admin/funding-types', ['name' => 'Term'])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/admin/funding-groups/{$group}", ['name' => 'Loans'])->assertOk();
        $this->putJson("/api/v1/admin/funding-types/{$type}", ['name' => 'Term loans'])->assertOk();
        $this->getJson('/api/v1/admin/funding-groups')->assertOk()->assertJsonPath('data.0.name', 'Loans');
        $this->getJson('/api/v1/admin/funding-types')->assertOk()->assertJsonPath('data.0.name', 'Term loans');

        $this->postJson('/api/v1/admin/funding-groups', ['name' => 'Loans'])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->deleteJson("/api/v1/admin/funding-groups/{$group}")->assertOk();
        $this->deleteJson("/api/v1/admin/funding-types/{$type}")->assertOk();
        $this->assertSoftDeleted('funding_groups', ['id' => $group]);
        $this->deleteJson("/api/v1/admin/funding-groups/{$group}")->assertNotFound();
    }

    /**
     * Defect: groups and types are soft-deleted, but the
     * unique-name rules counted deleted rows - a deleted name could never be
     * used again, with no way to see or restore the row holding it.
     */
    #[Test]
    public function a_deleted_name_can_be_used_again(): void
    {
        $this->actingAsUser($this->admin);

        // Product categories too (findings O32): their table had a unique index
        // on name, so a deleted category's name stayed taken for good.
        foreach (['/api/v1/admin/funding-groups', '/api/v1/admin/funding-types', '/api/v1/admin/lenders/product-categories'] as $url) {
            $id = $this->postJson($url, ['name' => 'Reused'])->assertCreated()->json('data.id');
            $this->deleteJson("{$url}/{$id}")->assertOk();

            $this->postJson($url, ['name' => 'Reused'])->assertCreated();
        }
    }

    /** Among categories that are not deleted, a name is still taken once. */
    #[Test]
    public function a_live_product_category_name_stays_unique(): void
    {
        $this->actingAsUser($this->admin);
        $url = '/api/v1/admin/lenders/product-categories';

        $this->postJson($url, ['name' => 'Equipment'])->assertCreated();
        $other = $this->postJson($url, ['name' => 'Inventory'])->assertCreated()->json('data.id');
        $gone = $this->postJson($url, ['name' => 'Factoring'])->assertCreated()->json('data.id');
        $this->deleteJson("{$url}/{$gone}")->assertOk();

        $this->postJson($url, ['name' => 'Equipment'])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->putJson("{$url}/{$other}", ['name' => 'Equipment'])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->putJson("{$url}/{$other}", ['name' => 'Factoring'])->assertOk();
    }

    /**
     * Defect (findings O31): create took any phone but edit demanded a unique
     * one, so two lenders on one number could be made and then neither could
     * be edited. A phone number is not unique - lenders share support lines.
     */
    #[Test]
    public function lenders_can_share_a_phone_number_and_still_be_edited(): void
    {
        $this->actingAsUser($this->admin);

        $categories = [$this->productCategory()];

        $first = $this->postJson('/api/v1/admin/lenders', $this->lender(['name' => 'First Lender', 'product_categories' => $categories]))->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/admin/lenders', $this->lender(['name' => 'Second Lender', 'product_categories' => $categories]))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/admin/lenders/{$first}", $this->lender(['name' => 'First Lender Renamed', 'product_categories' => $categories]))->assertOk();
        $this->putJson("/api/v1/admin/lenders/{$second}", $this->lender(['name' => 'Second Lender Renamed', 'product_categories' => $categories]))->assertOk();
    }

    #[Test]
    public function lenders_and_product_categories_are_managed(): void
    {
        $this->actingAsUser($this->admin);

        $category = $this->postJson('/api/v1/admin/lenders/product-categories', ['name' => 'Equipment'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/lenders/product-categories/{$category}", ['name' => 'Equipment finance'])->assertOk();
        $this->getJson('/api/v1/admin/lenders/product-categories')->assertOk()->assertJsonPath('data.0.name', 'Equipment finance');

        $lender = $this->postJson('/api/v1/admin/lenders', $this->lender(['product_categories' => [$category]]))->assertCreated()->json('data.id');
        $this->getJson("/api/v1/admin/lenders/{$lender}")->assertOk()->assertJsonPath('data.name', 'Acme Capital');
        $this->putJson("/api/v1/admin/lenders/{$lender}", $this->lender(['name' => 'Acme Funding', 'product_categories' => [$category]]))->assertOk();
        $this->getJson('/api/v1/admin/lenders')->assertOk();
        $this->getJson('/api/v1/admin/lenders/get/all')->assertOk()->assertJsonPath('data.0.name', 'Acme Funding');

        $this->deleteJson("/api/v1/admin/lenders/{$lender}")->assertOk();
        $this->deleteJson("/api/v1/admin/lenders/product-categories/{$category}")->assertOk();
        $this->putJson("/api/v1/admin/lenders/product-categories/{$category}", ['name' => 'Gone'])->assertNotFound();
    }

    #[Test]
    public function a_lender_used_by_a_sequence_cannot_be_deleted(): void
    {
        $lender = Lender::create($this->lender());
        $this->actingAsUser($this->admin);
        $data = $this->sequence();
        $data['lender_visibilities'] = [$this->visibility($lender->id, $data['funding_type_id'])];
        $this->postJson('/api/v1/admin/funding-sequencings', $data)->assertCreated();

        $this->deleteJson("/api/v1/admin/lenders/{$lender->id}")->assertUnprocessable();
        $this->assertNotNull($lender->fresh());
    }

    #[Test]
    public function a_sequence_is_created_shown_updated_and_deleted(): void
    {
        $this->actingAsUser($this->admin);

        $id = $this->postJson('/api/v1/admin/funding-sequencings', $this->sequence())->assertCreated()->json('data.id');
        $this->getJson("/api/v1/admin/funding-sequencings/{$id}")->assertOk()->assertJsonPath('data.name', 'SBA 7(a)');
        $this->postJson('/api/v1/admin/funding-sequencings/fetch')->assertOk();
        $this->getJson('/api/v1/admin/funding-sequencings/grouped')->assertOk();

        $this->putJson("/api/v1/admin/funding-sequencings/{$id}", $this->sequence(['name' => 'SBA Express']))->assertOk();
        $this->patchJson("/api/v1/admin/funding-sequencings/{$id}/status", ['status' => 'Inactive'])->assertOk();
        $this->patchJson("/api/v1/admin/funding-sequencings/{$id}/status", ['status' => 'Retired'])->assertUnprocessable();

        $sequence = FundingSequencing::find($id);
        $this->assertSame('SBA Express', $sequence->name);
        $this->assertSame('Inactive', $sequence->status);

        $this->deleteJson("/api/v1/admin/funding-sequencings/{$id}")->assertOk();
        $this->getJson("/api/v1/admin/funding-sequencings/{$id}")->assertNotFound();
    }

    #[Test]
    public function a_range_must_run_upwards(): void
    {
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/funding-sequencings', $this->sequence(['min_amount' => 50000, 'max_amount' => 1000]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_amount');
    }

    /**
     * Defect: the display tab checked only max_amount > 0, where create and
     * the full update require max_amount > min_amount, so the display tab
     * could save a range that runs backwards.
     */
    #[Test]
    public function the_display_tab_also_refuses_a_backwards_range(): void
    {
        $this->actingAsUser($this->admin);
        $id = $this->postJson('/api/v1/admin/funding-sequencings', $this->sequence(['amount_range' => '$10,000 - $500,000']))->json('data.id');

        // min/max are not stored - the screen sends them with the
        // amount_range it builds from them, and only amount_range is kept.
        $this->putJson("/api/v1/admin/funding-sequencings/{$id}/display", [
            'min_amount' => 50000,
            'max_amount' => 1000,
            'amount_range' => '$50,000 - $1,000',
            'icon' => 'bank',
            'status' => 'Active',
        ])->assertUnprocessable()->assertJsonValidationErrors('max_amount');

        $this->assertSame('10000-500000', FundingSequencing::find($id)->amount_range);
    }

    #[Test]
    public function a_lenders_visibility_rules_are_replaced_one_lender_at_a_time(): void
    {
        $a = Lender::create($this->lender(['name' => 'A']));
        $b = Lender::create($this->lender(['name' => 'B', 'contact_phone' => '5550101']));
        $this->actingAsUser($this->admin);
        $data = $this->sequence();
        $category = $data['funding_type_id'];
        $data['lender_visibilities'] = [$this->visibility($a->id, $category), $this->visibility($b->id, $category)];
        $id = $this->postJson('/api/v1/admin/funding-sequencings', $data)->json('data.id');

        $this->postJson("/api/v1/admin/funding-sequencings/{$id}/lender/{$a->id}/visibility", [
            'lender_visibilities' => [$this->visibility($a->id, $category, ['min_fico' => 700]), $this->visibility($b->id, $category, ['min_fico' => 1])],
        ])->assertOk();

        $rules = FundingSequencing::find($id)->lenderVisibilities()->get()->keyBy('lender_id');
        $this->assertSame(700, (int) $rules[$a->id]->min_fico);
        // Only the named lender's rules change.
        $this->assertSame(650, (int) $rules[$b->id]->min_fico);

        $this->postJson("/api/v1/admin/funding-sequencings/{$id}/visibilities/fetch")->assertOk();
    }

    #[Test]
    public function visibility_rows_are_deleted_only_from_their_own_sequence(): void
    {
        $lender = Lender::create($this->lender());
        $this->actingAsUser($this->admin);
        $one = $this->sequence();
        $one['lender_visibilities'] = [$this->visibility($lender->id, $one['funding_type_id'])];
        $first = $this->postJson('/api/v1/admin/funding-sequencings', $one)->json('data.id');
        $two = $this->sequence(['name' => 'Other']);
        $two['lender_visibilities'] = [$this->visibility($lender->id, $two['funding_type_id'])];
        $second = $this->postJson('/api/v1/admin/funding-sequencings', $two)->json('data.id');
        $othersRow = FundingSequencing::find($second)->lenderVisibilities()->value('id');

        $this->deleteJson("/api/v1/admin/funding-sequencings/{$first}/visibilities", ['visibility_ids' => [$othersRow]])->assertOk();

        $this->assertSame(1, FundingSequencing::find($second)->lenderVisibilities()->count());
    }
}
