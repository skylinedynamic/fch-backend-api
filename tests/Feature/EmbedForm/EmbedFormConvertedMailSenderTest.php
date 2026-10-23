<?php

namespace Tests\Feature\EmbedForm;

use App\Mail\EmbedForm\EmbedFormConverted;
use App\Models\Common\Permission;
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
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Whose company each "converted to a client" email comes from when the host
 * converts a submission from another partner's embedded form.
 *
 *   applicant - the partner's (the applicant applied through the partner)
 *   partner   - the partner's
 *   host      - the host's own: its mailbox, branding and sender name, not
 *               the partner's
 */
class EmbedFormConvertedMailSenderTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Partner $partner;

    private EmbedFormSubmission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Mail::fake();
        Notification::fake();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'FCH Host',
            'full_name' => 'FCH Host',
            'email' => 'host@example.test',
        ]);

        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Acme Lending',
            'full_name' => 'Acme Lending',
            'email' => 'partner@example.test',
        ]);

        $this->submission = EmbedFormSubmission::create([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $this->partner->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.test',
        ]);

        $owner = User::create([
            'name' => 'Host Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::PARTNER,
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
        ]);

        $owner = User::with('permissions', 'roles')->findOrFail($owner->id);
        foreach (['view application', 'convert application'] as $name) {
            $owner->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Passport::actingAs(User::with('permissions', 'roles', 'partner')->findOrFail($owner->id));

        $this->postJson("/api/v1/common/embed-forms/submissions/{$this->submission->id}/convert-to-borrower")
            ->assertOk();
    }

    private function senderOf(EmbedFormConverted $mail): ?int
    {
        return (new \ReflectionProperty($mail, 'mailPartnerId'))->getValue($mail);
    }

    #[Test]
    public function the_hosts_email_comes_from_the_host_company(): void
    {
        Mail::assertQueued(EmbedFormConverted::class, fn (EmbedFormConverted $mail) => $mail->hasTo('host@example.test')
            && $mail->forStaff
            && $this->senderOf($mail) === $this->host->id);
    }

    #[Test]
    public function the_hosts_email_is_branded_as_the_host(): void
    {
        Mail::assertQueued(EmbedFormConverted::class, function (EmbedFormConverted $mail) {
            if (!$mail->hasTo('host@example.test')) {
                return false;
            }
            $mail->envelope();

            return (new \ReflectionProperty($mail, 'dynamicData'))->getValue($mail)['partner_id'] === $this->host->id;
        });
    }

    #[Test]
    public function the_partners_email_comes_from_the_partner_company(): void
    {
        Mail::assertQueued(EmbedFormConverted::class, fn (EmbedFormConverted $mail) => $mail->hasTo('partner@example.test')
            && $mail->forStaff
            && $this->senderOf($mail) === $this->partner->id);
    }

    #[Test]
    public function a_new_client_is_still_announced_as_converted_to_a_client(): void
    {
        Mail::assertQueued(EmbedFormConverted::class, fn (EmbedFormConverted $mail) => $mail->forStaff
            && $mail->envelope()->subject === 'Applicant converted to a client — Maria Santos'
            && str_contains($mail->render(), 'has been converted to a client'));

        Mail::assertQueued(EmbedFormConverted::class, fn (EmbedFormConverted $mail) => !$mail->forStaff
            && str_contains($mail->render(), 'is now open'));
    }

    #[Test]
    public function the_applicants_email_comes_from_the_partner_company(): void
    {
        Mail::assertQueued(EmbedFormConverted::class, fn (EmbedFormConverted $mail) => $mail->hasTo('maria@example.test')
            && !$mail->forStaff
            && $this->senderOf($mail) === $this->partner->id);
    }
}
