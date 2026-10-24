<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Common\Permission;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET submissions/{id}/conversion-preview: what converting would do, asked
 * before the Convert modal is shown, so it can say up front whether the
 * submission joins an existing client or creates a new one. It writes nothing.
 */
class EmbedFormConversionPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;

    private EmbedFormSubmission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = $this->makePartner('Acme Funding', false);
        $this->submission = $this->makeSubmission($this->partner, 'maria@example.test');

        $this->actingAsPartnerOwner($this->partner, ['view application', 'convert application']);
    }

    private function makePartner(string $name, bool $isHost): Partner
    {
        return Partner::create([
            'is_host' => $isHost,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => $name,
            'full_name' => $name,
            'email' => Str::slug($name) . '@example.test',
        ]);
    }

    private function makeSubmission(Partner $partner, ?string $email): EmbedFormSubmission
    {
        return EmbedFormSubmission::create([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => $email,
        ]);
    }

    private function actingAsPartnerOwner(Partner $partner, array $permissions): void
    {
        $owner = User::create([
            'name' => $partner->company_name . ' Owner',
            'email' => 'owner-' . $partner->id . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::PARTNER,
            'partner_id' => $partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
        ]);

        $owner = User::with('permissions', 'roles')->findOrFail($owner->id);
        foreach ($permissions as $name) {
            $owner->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Passport::actingAs(User::with('permissions', 'roles', 'partner')->findOrFail($owner->id));
    }

    private function preview(?int $id = null)
    {
        $id ??= $this->submission->id;

        return $this->getJson("/api/v1/common/embed-forms/submissions/{$id}/conversion-preview");
    }

    #[Test]
    public function a_new_email_previews_creating_a_new_client(): void
    {
        $this->preview()
            ->assertOk()
            ->assertJsonPath('data.mode', EmbedFormService::CONVERSION_MODE_NEW)
            ->assertJsonPath('data.existing_borrower', null);
    }

    #[Test]
    public function an_existing_clients_email_previews_using_that_client(): void
    {
        $borrower = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.test',
        ]);

        $this->preview()
            ->assertOk()
            ->assertJsonPath('data.mode', EmbedFormService::CONVERSION_MODE_EXISTING)
            ->assertJsonPath('data.existing_borrower.id', $borrower->id)
            ->assertJsonPath('data.existing_borrower.email', 'maria@example.test');
    }

    #[Test]
    public function previewing_writes_nothing(): void
    {
        $this->preview()->assertOk();

        $this->assertSame(0, Borrower::count());
        $this->assertSame(0, FundingApplication::count());
        $this->assertNotSame(EmbedFormSubmission::STATUS_CONVERTED, $this->submission->fresh()->status);
    }

    #[Test]
    public function a_staff_email_cannot_be_converted(): void
    {
        $this->submission->update(['email' => 'owner-' . $this->partner->id . '@example.test']);

        $this->preview()->assertStatus(422);
    }

    #[Test]
    public function another_partners_client_cannot_be_converted(): void
    {
        $other = $this->makePartner('Other Partner', false);
        Borrower::create([
            'partner_id' => $other->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.test',
        ]);

        $this->preview()->assertStatus(422);
    }

    #[Test]
    public function an_already_converted_submission_is_refused(): void
    {
        $this->submission->update(['status' => EmbedFormSubmission::STATUS_CONVERTED]);

        $this->preview()->assertStatus(422);
    }

    #[Test]
    public function another_partners_submission_is_forbidden(): void
    {
        $other = $this->makePartner('Other Partner', false);
        $theirs = $this->makeSubmission($other, 'someone@example.test');

        $this->preview($theirs->id)->assertForbidden();
    }

    #[Test]
    public function an_unknown_submission_is_not_found(): void
    {
        $this->preview(999999)->assertNotFound();
    }

    #[Test]
    public function it_needs_the_convert_application_permission(): void
    {
        $this->actingAsPartnerOwner($this->makePartner('No Perm Co', false), ['view application']);

        $this->preview()->assertForbidden();
    }
}
