<?php

namespace Tests\Feature\Lender;

use App\Http\Controllers\Api\V1\FCH\Admin\Lender\LenderController;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\CheckLenderDuplicatesRequest;
use App\Http\Resources\FCH\Lender\LenderResource;
use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
use App\Models\User\User;
use App\Services\FCH\Admin\Lender\LenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Lenders (admin): every lender has a system-made Lender ID ("LND-1234") the
 * screens show and search; two lenders may share a name or a legal name, and
 * Add/Edit Lender asks which lenders already have them, to warn - not refuse.
 */
#[CoversClass(LenderController::class)]
#[CoversClass(LenderService::class)]
#[CoversClass(LenderResource::class)]
#[CoversClass(CheckLenderDuplicatesRequest::class)]
#[CoversClass(Lender::class)]
class LenderCodeAndDuplicatesApiTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private const LENDERS = '/api/v1/admin/lenders';

    private const DUPLICATES = '/api/v1/admin/lenders/check-duplicates';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeStaffUser($this->makeHostPartner());
        $this->actingAsUser($this->admin);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kabbage',
            'legal_name' => 'Kabbage Inc.',
            'product_categories' => [LenderProductCategory::create(['name' => 'Term '.uniqid(), 'status' => 'Active'])->id],
            'contact_name' => 'Lee Lender',
            'contact_title' => 'Partnerships',
            'contact_email' => 'lee@kabbage.example.org',
            'contact_phone' => '5552340100',
            'status' => Lender::STATUS_ACTIVE,
        ], $overrides);
    }

    // ---- Lender ID -----------------------------------------------------------

    #[Test]
    public function add_lender_makes_its_lender_id_and_ignores_one_sent_in(): void
    {
        $created = $this->postJson(self::LENDERS, $this->payload(['lender_code' => 'LND-0000']))->assertCreated();

        // "LND-" . four random digits . the lender's id.
        $code = $created->json('data.lender_code');
        $this->assertMatchesRegularExpression('/^LND-\d{4}' . $created->json('data.id') . '$/', $code);
        $this->assertSame($code, Lender::find($created->json('data.id'))->lender_code);
    }

    #[Test]
    public function edit_lender_keeps_the_lender_id_whatever_is_sent(): void
    {
        $id = $this->postJson(self::LENDERS, $this->payload())->json('data.id');
        $code = Lender::find($id)->lender_code;

        $this->putJson(self::LENDERS."/{$id}", $this->payload(['name' => 'Kabbage Renamed', 'lender_code' => 'LND-9999']))
            ->assertOk()
            ->assertJsonPath('data.lender_code', $code);
    }

    #[Test]
    public function the_lender_list_shows_searches_filters_and_sorts_by_lender_id(): void
    {
        $a = Lender::find($this->postJson(self::LENDERS, $this->payload(['name' => 'Alpha', 'legal_name' => 'Alpha LLC']))->json('data.id'));
        $b = Lender::find($this->postJson(self::LENDERS, $this->payload(['name' => 'Bravo', 'legal_name' => 'Bravo LLC']))->json('data.id'));

        $rows = collect($this->getJson(self::LENDERS)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($a->lender_code, $rows[$a->id]['lender_code']);

        $this->assertSame([$b->id], collect($this->getJson(self::LENDERS.'?search='.$b->lender_code)->json('data'))->pluck('id')->all());

        $column = json_encode(['lender_code' => ['operator' => 'and', 'constraints' => [['value' => $a->lender_code, 'matchMode' => 'contains']]]]);
        $this->assertSame([$a->id], collect($this->getJson(self::LENDERS.'?filters='.urlencode($column))->json('data'))->pluck('id')->all());

        $ascending = collect($this->getJson(self::LENDERS.'?sort_field=lender_code&sort_order=asc')->json('data'))->pluck('lender_code')->all();
        $expected = $ascending;
        sort($expected);
        $this->assertSame($expected, $ascending);
    }

    #[Test]
    public function the_active_lender_list_carries_the_lender_id(): void
    {
        $id = $this->postJson(self::LENDERS, $this->payload())->json('data.id');

        $all = collect($this->getJson(self::LENDERS.'/get/all')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(Lender::find($id)->lender_code, $all[$id]['lender_code']);
    }

    // ---- Duplicates: allowed, with a warning -----------------------------------

    /**
     * Requirement change (2026-11-11): a legal name was unique (R2 BUG-008);
     * two lenders may now share a name or a legal name - Add/Edit warns.
     */
    #[Test]
    public function two_lenders_may_share_a_name_and_a_legal_name(): void
    {
        $this->postJson(self::LENDERS, $this->payload())->assertCreated();
        $this->postJson(self::LENDERS, $this->payload(['legal_name' => 'KABBAGE  inc.']))->assertCreated();

        $this->assertSame(2, Lender::where('name', 'Kabbage')->count());
        $this->assertCount(2, Lender::pluck('lender_code')->unique());
    }

    #[Test]
    public function edit_lender_may_take_another_lenders_legal_name(): void
    {
        $this->postJson(self::LENDERS, $this->payload());
        $other = $this->postJson(self::LENDERS, $this->payload(['name' => 'OnDeck', 'legal_name' => 'OnDeck Capital']))->json('data.id');

        $this->putJson(self::LENDERS."/{$other}", $this->payload(['name' => 'OnDeck', 'legal_name' => 'Kabbage Inc.']))->assertOk();
    }

    #[Test]
    public function the_duplicate_check_names_lenders_with_the_same_name_or_legal_name(): void
    {
        $kabbage = Lender::find($this->postJson(self::LENDERS, $this->payload())->json('data.id'));
        $this->postJson(self::LENDERS, $this->payload(['name' => 'OnDeck', 'legal_name' => 'OnDeck Capital']));

        $found = $this->postJson(self::DUPLICATES, ['name' => '  KABBAGE ', 'legal_name' => 'kabbage   inc.'])->assertOk()->json('data');

        $this->assertSame([['id' => $kabbage->id, 'lender_code' => $kabbage->lender_code, 'name' => 'Kabbage', 'legal_name' => 'Kabbage Inc.']], $found['name']);
        $this->assertSame($found['name'], $found['legal_name']);
    }

    #[Test]
    public function the_duplicate_check_finds_nothing_for_a_new_name(): void
    {
        $this->postJson(self::LENDERS, $this->payload());

        $found = $this->postJson(self::DUPLICATES, ['name' => 'Brand New', 'legal_name' => 'Brand New LLC'])->assertOk()->json('data');

        $this->assertSame(['name' => [], 'legal_name' => []], $found);
    }

    #[Test]
    public function the_duplicate_check_leaves_out_the_lender_being_edited_and_deleted_lenders(): void
    {
        $self = $this->postJson(self::LENDERS, $this->payload())->json('data.id');
        $deleted = $this->postJson(self::LENDERS, $this->payload())->json('data.id');
        $this->deleteJson(self::LENDERS."/{$deleted}")->assertOk();

        $found = $this->postJson(self::DUPLICATES, ['name' => 'Kabbage', 'legal_name' => 'Kabbage Inc.', 'ignore_id' => $self])->assertOk()->json('data');

        $this->assertSame(['name' => [], 'legal_name' => []], $found);
    }

    #[Test]
    public function the_duplicate_check_takes_one_field_at_a_time_and_checks_its_input(): void
    {
        $this->postJson(self::LENDERS, $this->payload());

        $this->assertCount(1, $this->postJson(self::DUPLICATES, ['name' => 'Kabbage'])->assertOk()->json('data.name'));
        $this->postJson(self::DUPLICATES, ['ignore_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('ignore_id');
        $this->postJson(self::DUPLICATES, ['name' => str_repeat('x', 256)])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    #[Test]
    public function the_duplicate_check_is_for_the_host_team_only(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeWhiteLabelPartner()));

        $this->postJson(self::DUPLICATES, ['name' => 'Kabbage'])->assertForbidden();
    }
}
