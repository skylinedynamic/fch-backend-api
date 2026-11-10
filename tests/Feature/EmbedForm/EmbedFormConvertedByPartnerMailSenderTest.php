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
 * BUG-062: the partner (not the host) converts a submission from its own
 * embedded form. The host's copy of "converted to a client" must still come
 * from the host company - its mailbox, branding and sender name - like the
 * host's copy of the new-submission notice, never from the partner's.
 */
class EmbedFormConvertedByPartnerMailSenderTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Partner $partner;

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
            'company_name' => 'Wanda Lending',
            'full_name' => 'Wanda Lending',
            'email' => 'wanda@example.test',
        ]);

        $submission = EmbedFormSubmission::create([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $this->partner->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.test',
        ]);

        // Wanda's own login converts it.
        $owner = User::create([
            'name' => 'Wanda Owner',
            'email' => 'wanda-owner@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::PARTNER,
            'partner_id' => $this->partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
        ]);

        $owner = User::with('permissions', 'roles')->findOrFail($owner->id);
        foreach (['view application', 'convert application'] as $name) {
            $owner->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Passport::actingAs(User::with('permissions', 'roles', 'partner')->findOrFail($owner->id));

        $this->postJson("/api/v1/common/embed-forms/submissions/{$submission->id}/convert-to-borrower")
            ->assertOk();
    }

    private function senderOf(EmbedFormConverted $mail): ?int
    {
        return (new \ReflectionProperty($mail, 'mailPartnerId'))->getValue($mail);
    }

    private function brandOf(EmbedFormConverted $mail): ?int
    {
        $mail->envelope();

        return (new \ReflectionProperty($mail, 'dynamicData'))->getValue($mail)['partner_id'] ?? null;
    }

    #[Test]
    public function the_hosts_copy_comes_from_and_is_branded_as_the_host(): void
    {
        Mail::assertQueued(EmbedFormConverted::class, fn (EmbedFormConverted $mail) => $mail->hasTo('host@example.test')
            && $mail->forStaff
            && $this->senderOf($mail) === $this->host->id
            && $this->brandOf($mail) === $this->host->id);
    }

    #[Test]
    public function the_hosts_copy_is_never_sent_from_the_partner(): void
    {
        Mail::assertNotQueued(EmbedFormConverted::class, fn (EmbedFormConverted $mail) => $mail->hasTo('host@example.test')
            && ($this->senderOf($mail) === $this->partner->id || $this->brandOf($mail) === $this->partner->id));
    }

    #[Test]
    public function the_partners_copy_and_the_applicants_email_come_from_the_partner(): void
    {
        Mail::assertQueued(EmbedFormConverted::class, fn (EmbedFormConverted $mail) => $mail->hasTo('wanda@example.test')
            && $mail->forStaff
            && $this->senderOf($mail) === $this->partner->id);

        Mail::assertQueued(EmbedFormConverted::class, fn (EmbedFormConverted $mail) => $mail->hasTo('maria@example.test')
            && !$mail->forStaff
            && $this->senderOf($mail) === $this->partner->id);
    }
}
