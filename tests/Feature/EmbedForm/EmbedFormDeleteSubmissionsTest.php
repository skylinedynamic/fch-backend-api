<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Controllers\Api\V1\FCH\Common\EmbedForm\EmbedFormController;
use App\Models\EmbedForm\EmbedFormAttachment;
use App\Models\EmbedForm\EmbedFormCoBorrower;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Embedded Form › Loan Applications: deleting the applications ticked in the
 * list. Only those not yet converted: a converted one is the record behind a
 * client and a funding application, so a selection that includes one is
 * refused whole and those rows are named.
 */
#[CoversClass(EmbedFormController::class)]
class EmbedFormDeleteSubmissionsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const ROUTE = '/api/v1/common/embed-forms/submissions/delete';

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->actingAsUser($this->makePartnerAdmin($this->partner));
    }

    private function submission(array $attributes = [], ?Partner $partner = null): EmbedFormSubmission
    {
        $partner ??= $this->partner;

        return EmbedFormSubmission::create(array_merge([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => Str::random(8) . '@example.test',
            'status' => EmbedFormSubmission::STATUS_NEW,
        ], $attributes));
    }

    #[Test]
    public function the_selected_applications_are_deleted_with_their_files_and_co_borrowers(): void
    {
        $first = $this->submission();
        $second = $this->submission(['status' => EmbedFormSubmission::STATUS_REJECTED]);
        $kept = $this->submission();
        $path = "embed_forms/{$this->partner->id}/{$first->id}/statement.pdf";
        Storage::disk('public')->put($path, 'statement');
        EmbedFormAttachment::create([
            'submission_id' => $first->id,
            'partner_id' => $this->partner->id,
            'field_name' => 'documents',
            'original_name' => 'statement.pdf',
            'file_path' => $path,
        ]);
        EmbedFormCoBorrower::create(['submission_id' => $first->id, 'first_name' => 'Co', 'last_name' => 'Owner']);

        $this->postJson(self::ROUTE, ['ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertSame([$kept->id], EmbedFormSubmission::pluck('id')->all());
        $this->assertSame(0, EmbedFormAttachment::count());
        $this->assertSame(0, EmbedFormCoBorrower::count());
        Storage::disk('public')->assertMissing($path);
    }

    #[Test]
    public function a_selection_with_a_converted_application_is_refused_and_nothing_is_deleted(): void
    {
        $open = $this->submission();
        $converted = $this->submission(['status' => EmbedFormSubmission::STATUS_CONVERTED]);

        $this->postJson(self::ROUTE, ['ids' => [$open->id, $converted->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.converted.0.id', $converted->id);

        $this->assertSame(2, EmbedFormSubmission::count());
    }

    #[Test]
    public function another_partners_applications_are_not_touched(): void
    {
        $theirs = $this->submission([], $this->makeCertifiedPartner());

        $this->postJson(self::ROUTE, ['ids' => [$theirs->id]])->assertNotFound();

        $this->assertTrue(EmbedFormSubmission::whereKey($theirs->id)->exists());
    }

    #[Test]
    public function the_selection_is_required(): void
    {
        $this->postJson(self::ROUTE, ['ids' => []])->assertUnprocessable();
    }
}
