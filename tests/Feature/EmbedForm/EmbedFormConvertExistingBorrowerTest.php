<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationDocument;
use App\Models\EmbedForm\EmbedFormAttachment;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Converting a submission whose email already belongs to one of the partner's
 * borrowers, through the route the admin portal calls.
 *
 * The partner is asked first: the API answers 409 with who the borrower is, the
 * portal shows them, and only a second call with confirm_existing_borrower
 * converts - onto that borrower, without creating another.
 *
 * The 409 never came: the exception was thrown under a class name with its
 * namespace separators lost (AppSupportmbedFormxisting...), so PHP failed with
 * "Class not found" and the partner got a 500 instead of the question.
 */
class EmbedFormConvertExistingBorrowerTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;

    private Borrower $borrower;

    private EmbedFormSubmission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();
        Notification::fake();

        $this->partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ]);

        $this->submission = EmbedFormSubmission::create([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $this->partner->id,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ]);

        Passport::actingAs(User::create([
            'name' => 'Host Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::PARTNER,
            'partner_id' => $this->partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
        ]));
    }

    private function convert(array $body = [])
    {
        return $this->postJson("/api/v1/common/embed-forms/submissions/{$this->submission->id}/convert-to-borrower", $body);
    }

    private function upload(): string
    {
        $path = "embed_forms/{$this->partner->id}/{$this->submission->id}/" . Str::uuid() . '.pdf';
        Storage::disk('public')->put($path, 'statement');
        EmbedFormAttachment::create([
            'submission_id' => $this->submission->id,
            'partner_id' => $this->partner->id,
            'field_name' => 'documents',
            'original_name' => 'statement.pdf',
            'file_path' => $path,
        ]);

        return $path;
    }

    #[Test]
    public function the_partner_is_asked_to_confirm_the_existing_borrower(): void
    {
        $this->convert()
            ->assertStatus(409)
            ->assertJsonPath('code', 'existing_borrower_confirmation_required')
            ->assertJsonPath('data.existing_borrower.id', $this->borrower->id);
    }

    #[Test]
    public function nothing_is_created_or_moved_until_the_partner_confirms(): void
    {
        $upload = $this->upload();

        $this->convert()->assertStatus(409);

        $this->assertSame(1, Borrower::count());
        $this->assertSame(0, FundingApplication::count());
        $this->assertSame(0, FundingApplicationDocument::count());
        $this->assertNotSame(EmbedFormSubmission::STATUS_CONVERTED, $this->submission->fresh()->status);
        Storage::disk('public')->assertExists($upload);
    }

    #[Test]
    public function once_confirmed_the_application_goes_to_the_existing_borrower(): void
    {
        $this->upload();

        $applicationId = $this->convert(['confirm_existing_borrower' => true])
            ->assertOk()
            ->assertJsonPath('data.is_existing_borrower', true)
            ->json('data.funding_application_id');

        $this->assertSame(1, Borrower::count());
        $this->assertSame($this->borrower->id, FundingApplication::findOrFail($applicationId)->borrower_id);
        $this->assertSame($this->borrower->id, $this->submission->fresh()->converted_borrower_id);
        $this->assertCount(1, Storage::disk('public')->allFiles("funding-application/{$this->borrower->id}/FA-{$applicationId}"));
    }
    // -- Only the form's own partner's borrower -------------------------------

    #[Test]
    public function another_partners_borrower_cannot_be_converted_onto(): void
    {
        $other = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Other Lending',
            'full_name' => 'Other Lending',
            'email' => 'other@example.test',
        ]);
        $this->borrower->update(['partner_id' => $other->id]);

        $this->convert(['confirm_existing_borrower' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', __('embed_form.borrower_other_partner'));

        $this->assertSame(0, FundingApplication::count());
        $this->assertNull($this->submission->fresh()->converted_borrower_id);
    }

    #[Test]
    public function a_borrower_with_no_partner_is_not_this_partners_borrower(): void
    {
        $this->borrower->forceFill(['partner_id' => null])->save();

        $this->convert(['confirm_existing_borrower' => true])->assertStatus(422);

        $this->assertSame(0, FundingApplication::count());
    }
}
