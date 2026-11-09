<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Resources\FCH\EmbedForm\EmbedFormSubmissionResource;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Entity Type and Industry Type offer "Other". The public form now asks what it
 * is, as the borrower profile does ("Entity Type Details" / "Industry Type
 * Details"): a text field shown only for "Other", required then, kept with the
 * submission and shown to the partner.
 */
class EmbedFormOtherTypeNoteTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;

    private EmbedFormConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        $this->config = EmbedFormConfig::create([
            'partner_id' => $this->partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Funding Application',
            'is_active' => true,
        ]);
    }

    private function businessStep(array $answers)
    {
        return $this->postJson("/api/v1/embed/validate-step/{$this->config->public_key}/1", $answers);
    }

    private function submission(array $attributes): EmbedFormSubmission
    {
        return EmbedFormSubmission::create(array_merge([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $this->partner->id,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ], $attributes));
    }

    // -- The page ------------------------------------------------------------

    #[Test]
    public function the_page_asks_for_the_details_only_when_other_is_chosen(): void
    {
        $page = $this->get('/form/'.$this->config->public_key)->assertOk()->getContent();

        foreach (['entity_type_note', 'industry_type_note'] as $field) {
            $this->assertStringContainsString("name=\"{$field}\"", $page);
            // Required only while shown: listed with the other revealed fields.
            $this->assertMatchesRegularExpression("/CONDITIONAL_REQ = \\[[^\\]]*'{$field}'/s", $page);
        }
        // Hidden until "Other" is picked.
        $this->assertMatchesRegularExpression('/id="entityTypeNoteWrap"[^>]*display: none/', $page);
        $this->assertMatchesRegularExpression('/id="industryTypeNoteWrap"[^>]*display: none/', $page);
        $this->assertStringContainsString('Entity Type Details', $page);
        $this->assertStringContainsString('Industry Type Details', $page);
        // The review step shows what was written.
        $this->assertStringContainsString("val('entity_type_note')", $page);
        $this->assertStringContainsString("val('industry_type_note')", $page);
    }

    #[Test]
    public function each_type_and_its_details_share_a_row(): void
    {
        // Half-width columns, in this order: the details sit beside their own
        // select, not under the pair of selects.
        $page = $this->get('/form/'.$this->config->public_key)->assertOk()->getContent();

        $order = array_map(fn (string $marker) => strpos($page, $marker), [
            'id="industry_type"', 'id="industryTypeNoteWrap"', 'id="typeNoteSpacer"', 'id="entity_type"', 'id="entityTypeNoteWrap"',
        ]);

        $this->assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);
        // With only the entity type on "Other", the spacer holds the industry
        // row so the entity type and its details start the next one together.
        $this->assertStringContainsString('function placeTypeNotes()', $page);
        $this->assertStringContainsString("entity && !industry ? '' : 'none'", $page);
    }

    // -- The server's rules --------------------------------------------------

    #[Test]
    public function other_needs_its_details(): void
    {
        $this->businessStep(['entity_type' => 'Other', 'industry_type' => 'Other'])
            ->assertStatus(422)
            // One message per field, as every embed form error is given.
            ->assertJsonPath('errors.entity_type_note', __('borrower_validation.business_entity_type_note_required_if'))
            ->assertJsonPath('errors.industry_type_note', __('borrower_validation.business_industry_type_note_required_if'));
    }

    #[Test]
    public function other_with_its_details_is_accepted(): void
    {
        $errors = $this->businessStep([
            'entity_type' => 'Other', 'entity_type_note' => 'Cooperative',
            'industry_type' => 'Other', 'industry_type_note' => 'Beekeeping',
        ])->json('errors') ?? [];

        $this->assertArrayNotHasKey('entity_type_note', $errors);
        $this->assertArrayNotHasKey('industry_type_note', $errors);
    }

    #[Test]
    public function a_listed_type_needs_no_details(): void
    {
        $errors = $this->businessStep(['entity_type' => 'C-Corporation', 'industry_type' => 'Real Estate (Non-Lending)'])
            ->json('errors') ?? [];

        $this->assertArrayNotHasKey('entity_type_note', $errors);
        $this->assertArrayNotHasKey('industry_type_note', $errors);
    }

    #[Test]
    public function the_details_are_at_most_255_characters(): void
    {
        $this->businessStep(['entity_type' => 'Other', 'entity_type_note' => str_repeat('x', 256)])
            ->assertJsonValidationErrors('entity_type_note');
    }

    // -- What is kept and shown ----------------------------------------------

    #[Test]
    public function the_submission_keeps_the_details_for_other(): void
    {
        $submission = $this->submission([
            'entity_type' => 'Other', 'entity_type_note' => 'Cooperative',
            'industry_type' => 'Other', 'industry_type_note' => 'Beekeeping',
        ])->fresh();

        $this->assertSame('Cooperative', $submission->entity_type_note);
        $this->assertSame('Beekeeping', $submission->industry_type_note);
    }

    #[Test]
    public function details_left_from_an_earlier_other_are_not_kept_for_a_listed_type(): void
    {
        $submission = $this->submission([
            'entity_type' => 'C-Corporation', 'entity_type_note' => 'Cooperative',
            'industry_type' => 'Real Estate (Non-Lending)', 'industry_type_note' => 'Beekeeping',
        ])->fresh();

        $this->assertNull($submission->entity_type_note);
        $this->assertNull($submission->industry_type_note);
    }

    #[Test]
    public function the_partner_sees_the_details(): void
    {
        $data = (new EmbedFormSubmissionResource($this->submission([
            'entity_type' => 'Other', 'entity_type_note' => 'Cooperative',
            'industry_type' => 'Other', 'industry_type_note' => 'Beekeeping',
        ])->fresh()))->resolve(Request::create('/'));

        $this->assertSame('Cooperative', $data['entity_type_note']);
        $this->assertSame('Beekeeping', $data['industry_type_note']);
    }
}
