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
use App\Models\FundingRequest\FundingRequest;
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

    /** The same wording as a blocked client or partner delete. */
    private const LENDER_BLOCKED_ONE = "This lender has related records and cannot be deleted. Please change the lender's status instead.";

    private const SEQUENCE_BLOCKED_ONE = "This funding sequence has related records and cannot be deleted. Please change the funding sequence's status instead.";

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
            'contact_phone' => '5552340100',
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

        $this->deleteJson("/api/v1/admin/lenders/{$lender->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', self::LENDER_BLOCKED_ONE);
        $this->assertNotNull($lender->fresh());
    }

    /**
     * Defect: a lender assigned to a funding request (assign_lender) could be
     * deleted, leaving the request pointing at a lender that is gone.
     */
    #[Test]
    public function a_lender_assigned_to_a_funding_request_is_kept_and_the_user_is_told_why(): void
    {
        $lender = Lender::create($this->lender(['name' => 'Reagan Capital']));
        $request = $this->fundingRequestAssignedTo($lender);
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/lenders/check-deletable', ['ids' => [$lender->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', false)
            ->assertJsonPath('data.0.name', 'Reagan Capital')
            ->assertJsonPath('data.0.reason', self::LENDER_BLOCKED_ONE);
        $this->postJson('/api/v1/admin/lenders/multiple-delete', ['ids' => [$lender->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', self::LENDER_BLOCKED_ONE)
            ->assertJsonPath('errors.blocked', [['id' => $lender->id, 'name' => 'Reagan Capital']]);
        $this->deleteJson("/api/v1/admin/lenders/{$lender->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', self::LENDER_BLOCKED_ONE);

        $this->assertNotSoftDeleted($lender);
        $this->assertSame($lender->id, (int) $request->fresh()->assign_lender);
    }

    #[Test]
    public function when_any_selected_lender_is_assigned_none_are_deleted_and_each_blocked_one_is_named(): void
    {
        $free = Lender::create($this->lender(['name' => 'Free Lender']));
        $first = Lender::create($this->lender(['name' => 'Reagan Capital']));
        $second = Lender::create($this->lender(['name' => 'Jessamine Funding']));
        $this->fundingRequestAssignedTo($first);
        $this->fundingRequestAssignedTo($second);
        $this->actingAsUser($this->admin);

        $response = $this->postJson('/api/v1/admin/lenders/multiple-delete', ['ids' => [$free->id, $first->id, $second->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Some selected lenders have related records and cannot be deleted: Reagan Capital, Jessamine Funding. '
                . 'Please change their status instead, or unselect them to delete the others.');

        $this->assertSame(['Reagan Capital', 'Jessamine Funding'], array_column($response->json('errors.blocked'), 'name'));
        $this->assertNotSoftDeleted($free);
        $this->assertNotSoftDeleted($first);
        $this->assertNotSoftDeleted($second);
    }

    /** Only the assigned lender counts - one merely matched (lender_ids) can go. */
    #[Test]
    public function a_lender_only_matched_to_a_request_is_deleted(): void
    {
        $assigned = Lender::create($this->lender(['name' => 'Assigned Lender']));
        $matched = Lender::create($this->lender(['name' => 'Matched Lender']));
        $request = $this->fundingRequestAssignedTo($assigned);
        $request->update(['lender_ids' => [$assigned->id, $matched->id]]);
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/lenders/check-deletable', ['ids' => [$matched->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', true);
        $this->postJson('/api/v1/admin/lenders/multiple-delete', ['ids' => [$matched->id]])->assertOk();

        $this->assertSoftDeleted($matched);
    }

    /**
     * Defect: the portal deleted a selection one lender per call, all at once -
     * a refused lender left the others deleted. One call now deletes them all.
     */
    #[Test]
    public function several_unassigned_lenders_are_deleted_in_one_call(): void
    {
        $first = Lender::create($this->lender(['name' => 'First Lender']));
        $second = Lender::create($this->lender(['name' => 'Second Lender']));
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/lenders/multiple-delete', ['ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('message', __('lender.deleted_successfully'));

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
    }

    #[Test]
    public function deleting_lenders_needs_a_list_of_existing_ids(): void
    {
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/lenders/multiple-delete', [])->assertStatus(422);
        $this->postJson('/api/v1/admin/lenders/multiple-delete', ['ids' => [999999]])->assertStatus(422);
    }

    /**
     * Defect: a funding sequence used by a funding request could be deleted,
     * leaving the request pointing at an option that is gone.
     */
    #[Test]
    public function a_sequence_used_by_a_funding_request_is_kept_and_the_user_is_told_why(): void
    {
        $sequence = $this->sequenceNamed('SBA 7(a)');
        $request = $this->fundingRequestOn($sequence);
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/funding-sequencings/check-deletable', ['ids' => [$sequence->id]])
            ->assertOk()
            ->assertJsonPath('data.0.is_deletable', false)
            ->assertJsonPath('data.0.name', 'SBA 7(a)')
            ->assertJsonPath('data.0.reason', self::SEQUENCE_BLOCKED_ONE);
        $this->postJson('/api/v1/admin/funding-sequencings/multiple-delete', ['ids' => [$sequence->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', self::SEQUENCE_BLOCKED_ONE)
            ->assertJsonPath('errors.blocked', [['id' => $sequence->id, 'name' => 'SBA 7(a)']]);
        $this->deleteJson("/api/v1/admin/funding-sequencings/{$sequence->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', self::SEQUENCE_BLOCKED_ONE);

        $this->assertNotSoftDeleted($sequence);
        $this->assertNotSoftDeleted($request);
    }

    #[Test]
    public function when_any_selected_sequence_is_used_none_are_deleted_and_each_blocked_one_is_named(): void
    {
        $free = $this->sequenceNamed('Free Option');
        $first = $this->sequenceNamed('SBA 7(a)');
        $second = $this->sequenceNamed('Bridge Loans');
        $this->fundingRequestOn($first);
        $this->fundingRequestOn($second);
        $this->actingAsUser($this->admin);

        $response = $this->postJson('/api/v1/admin/funding-sequencings/multiple-delete', ['ids' => [$free->id, $first->id, $second->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Some selected funding sequences have related records and cannot be deleted: SBA 7(a), Bridge Loans. '
                . 'Please change their status instead, or unselect them to delete the others.');

        $this->assertSame(['SBA 7(a)', 'Bridge Loans'], array_column($response->json('errors.blocked'), 'name'));
        $this->assertNotSoftDeleted($free);
        $this->assertNotSoftDeleted($first);
        $this->assertNotSoftDeleted($second);
    }

    /** A request that was itself deleted no longer keeps its sequence. */
    #[Test]
    public function a_sequence_whose_only_request_was_deleted_can_be_deleted(): void
    {
        $sequence = $this->sequenceNamed('SBA 7(a)');
        $this->fundingRequestOn($sequence)->delete();
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/funding-sequencings/multiple-delete', ['ids' => [$sequence->id]])->assertOk();

        $this->assertSoftDeleted($sequence);
    }

    /**
     * Defect: the portal deleted a selection one sequence per call, all at
     * once - a refused one left the others deleted. One call now deletes them.
     */
    #[Test]
    public function several_unused_sequences_are_deleted_in_one_call(): void
    {
        $first = $this->sequenceNamed('First Option');
        $second = $this->sequenceNamed('Second Option');
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/funding-sequencings/multiple-delete', ['ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('message', __('funding_sequencing.deleted'));

        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
    }

    #[Test]
    public function deleting_sequences_needs_a_list_of_existing_ids(): void
    {
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/admin/funding-sequencings/multiple-delete', [])->assertStatus(422);
        $this->postJson('/api/v1/admin/funding-sequencings/multiple-delete', ['ids' => [999999]])->assertStatus(422);
    }

    /**
     * Defect: a deleted funding sequence left its lender visibility rules
     * behind, and those kept the lender from ever being deleted. Only a live
     * sequence's rules count now.
     */
    #[Test]
    public function a_lender_whose_only_sequence_was_deleted_can_be_deleted(): void
    {
        $lender = Lender::create($this->lender());
        $this->actingAsUser($this->admin);
        $data = $this->sequence();
        $data['lender_visibilities'] = [$this->visibility($lender->id, $data['funding_type_id'])];
        $sequenceId = $this->postJson('/api/v1/admin/funding-sequencings', $data)->assertCreated()->json('data.id');

        $this->postJson('/api/v1/admin/funding-sequencings/multiple-delete', ['ids' => [$sequenceId]])->assertOk();
        $this->deleteJson("/api/v1/admin/lenders/{$lender->id}")->assertOk();

        $this->assertSoftDeleted($lender);
    }

    private function sequenceNamed(string $name): FundingSequencing
    {
        return FundingSequencing::create(array_diff_key($this->sequence(['name' => $name]), ['readiness_factors' => true]));
    }

    private function fundingRequestOn(FundingSequencing $sequence): FundingRequest
    {
        return FundingRequest::create([
            'partner_id' => $this->host->id,
            'borrower_id' => $this->makeBorrower($this->host)->id,
            'funding_sequence_id' => $sequence->id,
            'status' => 'Pending',
        ]);
    }

    private function fundingRequestAssignedTo(Lender $lender): FundingRequest
    {
        $sequence = FundingSequencing::create(array_diff_key($this->sequence(), ['readiness_factors' => true]));

        return FundingRequest::create([
            'partner_id' => $this->host->id,
            'borrower_id' => $this->makeBorrower($this->host)->id,
            'funding_sequence_id' => $sequence->id,
            'status' => 'Pending',
            'lender_ids' => [$lender->id],
            'assign_lender' => $lender->id,
        ]);
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
    public function a_lenders_minimum_scores_must_be_a_fico_score_and_a_whole_business_score(): void
    {
        $lender = Lender::create($this->lender(['name' => 'Scored']));
        $this->actingAsUser($this->admin);
        $data = $this->sequence();
        $category = $data['funding_type_id'];

        $data['lender_visibilities'] = [$this->visibility($lender->id, $category, ['min_fico' => 299, 'min_business_credit_score' => -1])];
        $this->postJson('/api/v1/admin/funding-sequencings', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'lender_visibilities.0.min_fico' => __('common.fico_score_invalid', ['min' => 300, 'max' => 850]),
                'lender_visibilities.0.min_business_credit_score' => __('common.business_credit_score_invalid'),
            ]);

        $data['lender_visibilities'] = [$this->visibility($lender->id, $category, ['min_fico' => 851, 'min_business_credit_score' => 12.5])];
        $this->postJson('/api/v1/admin/funding-sequencings', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lender_visibilities.0.min_fico', 'lender_visibilities.0.min_business_credit_score']);

        // The bounds themselves, and a business score of 0, are valid.
        $data['lender_visibilities'] = [$this->visibility($lender->id, $category, ['min_fico' => 300, 'min_business_credit_score' => 0])];
        $id = $this->postJson('/api/v1/admin/funding-sequencings', $data)->assertSuccessful()->json('data.id');

        $this->postJson("/api/v1/admin/funding-sequencings/{$id}/lender/{$lender->id}/visibility", [
            'lender_visibilities' => [$this->visibility($lender->id, $category, ['min_fico' => 900])],
        ])->assertStatus(422)->assertJsonValidationErrors(['lender_visibilities.0.min_fico']);
    }

    #[Test]
    public function a_lenders_visibility_rules_are_replaced_one_lender_at_a_time(): void
    {
        $a = Lender::create($this->lender(['name' => 'A']));
        $b = Lender::create($this->lender(['name' => 'B', 'contact_phone' => '5552340101']));
        $this->actingAsUser($this->admin);
        $data = $this->sequence();
        $category = $data['funding_type_id'];
        $data['lender_visibilities'] = [$this->visibility($a->id, $category), $this->visibility($b->id, $category)];
        $id = $this->postJson('/api/v1/admin/funding-sequencings', $data)->json('data.id');

        $this->postJson("/api/v1/admin/funding-sequencings/{$id}/lender/{$a->id}/visibility", [
            // B's 610 is a valid FICO (300-850) but must be ignored: only A is named.
            'lender_visibilities' => [$this->visibility($a->id, $category, ['min_fico' => 700]), $this->visibility($b->id, $category, ['min_fico' => 610])],
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
