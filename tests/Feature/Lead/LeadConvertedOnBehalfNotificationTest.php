<?php

namespace Tests\Feature\Lead;

use App\Http\Controllers\Api\V1\FCH\Common\Lead\LeadController;
use App\Mail\Lead\LeadConvertedOnBehalfMail;
use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\FCH\Common\Notification\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The host converting another partner's lead does so on that partner's behalf,
 * so it tells that partner - in its bell and by email from the host - that its
 * lead is now its borrower. No one is told when a partner, or the host,
 * converts its own lead.
 */
#[CoversClass(LeadController::class)]
class LeadConvertedOnBehalfNotificationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const TYPE = 'partner.lead.converted_on_behalf';

    private Partner $host;
    private Partner $wlp;
    private User $hostAdmin;
    private User $wlpAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Storage::fake('public');
        $this->host = $this->makeHostPartner(['company_name' => 'Host Co']);
        $this->wlp = $this->makeWhiteLabelPartner(['company_name' => 'WLP Co', 'email' => 'owner@wlp.example.test']);
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
        $this->wlpAdmin = $this->makePartnerAdmin($this->wlp);
    }

    private function leadOf(Partner $partner, array $attributes = []): Lead
    {
        return Lead::create(array_merge([
            'partner_id' => $partner->id,
            'company_name' => 'Lead Co',
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'phone_number' => '(555) 123-4567',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => 'New',
            'probability' => 'High',
        ], $attributes));
    }

    private function convert(Lead $lead): Borrower
    {
        $this->postJson("/api/v1/common/leads/{$lead->id}/convert", [
            'is_free_trail' => true,
            'free_trail_end_date' => now()->addDays(14)->toDateString(),
        ])->assertOk();

        return Borrower::where('email', $lead->email)->firstOrFail();
    }

    private function bellFor(User $user): ?array
    {
        $bell = null;
        Notification::assertSentTo($user, DynamicNotification::class, function (DynamicNotification $n) use ($user, &$bell) {
            if ($n->typeKey !== self::TYPE) {
                return false;
            }
            $bell = $n->toArray($user);

            return true;
        });

        return $bell;
    }

    #[Test]
    public function the_lead_partner_is_told_in_its_bell_that_the_host_converted_its_lead(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $borrower = $this->convert($this->leadOf($this->wlp));

        $bell = $this->bellFor($this->wlpAdmin);
        $this->assertSame("/clients/{$borrower->id}", $bell['url']);
        $this->assertStringContainsString('Host Co', $bell['body']);
        $this->assertStringContainsString('Lee Lead', $bell['body']);
        Notification::assertNotSentTo($this->hostAdmin, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === self::TYPE);
    }

    #[Test]
    public function the_lead_partner_is_emailed_from_the_host(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $borrower = $this->convert($lead = $this->leadOf($this->wlp));

        Mail::assertQueued(LeadConvertedOnBehalfMail::class, function (LeadConvertedOnBehalfMail $mail) use ($lead, $borrower) {
            return $mail->hasTo('owner@wlp.example.test')
                && $mail->host->is($this->host)
                && $mail->lead->is($lead)
                && $mail->borrower->is($borrower);
        });
        Mail::assertQueued(LeadConvertedOnBehalfMail::class, 1);
    }

    #[Test]
    public function the_email_is_host_branded_greets_the_partner_by_name_and_says_the_lead_is_now_its_borrower(): void
    {
        $this->wlp->update(['full_name' => 'Ryan Mcmillan', 'company_name' => 'Ryan and Mcmillan LLC', 'partner_domain' => 'https://portal.wlp.example.test']);
        $this->actingAsUser($this->hostAdmin);

        $this->convert($this->leadOf($this->wlp));

        Mail::assertQueued(LeadConvertedOnBehalfMail::class, function (LeadConvertedOnBehalfMail $mail) {
            $html = $mail->render();

            return $mail->mailPartnerId() === $this->host->id
                && str_contains($html, 'Hello Ryan Mcmillan,')
                && !str_contains($html, 'Hello Ryan and Mcmillan LLC')
                && str_contains($html, 'Lee Lead is now your borrower and has been added to your Clients.')
                && !str_contains($html, 'listed in your portal')
                && str_contains($html, 'Host Co');
        });
    }

    #[Test]
    public function the_email_has_no_button_or_portal_link(): void
    {
        $this->wlp->update(['partner_domain' => 'https://portal.wlp.example.test']);
        $this->actingAsUser($this->hostAdmin);

        $borrower = $this->convert($this->leadOf($this->wlp));

        Mail::assertQueued(LeadConvertedOnBehalfMail::class, function (LeadConvertedOnBehalfMail $mail) use ($borrower) {
            $html = $mail->render();

            return !str_contains($html, 'View borrower')
                && !str_contains($html, "clients/{$borrower->id}");
        });
    }

    #[Test]
    public function a_partner_with_no_name_is_greeted_by_its_company(): void
    {
        $this->wlp->update(['full_name' => '', 'company_name' => 'WLP Co']);
        $this->actingAsUser($this->hostAdmin);

        $this->convert($this->leadOf($this->wlp));

        Mail::assertQueued(LeadConvertedOnBehalfMail::class, fn (LeadConvertedOnBehalfMail $mail) => str_contains($mail->render(), 'Hello WLP Co,'));
    }

    #[Test]
    public function the_hosts_staff_converting_it_tells_the_partner_too(): void
    {
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->convert($this->leadOf($this->wlp));

        $this->assertNotNull($this->bellFor($this->wlpAdmin));
        Mail::assertQueued(LeadConvertedOnBehalfMail::class, 1);
    }

    #[Test]
    public function no_one_is_told_when_a_partner_or_the_host_converts_its_own_lead(): void
    {
        $this->actingAsUser($this->wlpAdmin);
        $this->convert($this->leadOf($this->wlp));

        $this->actingAsUser($this->hostAdmin);
        $this->convert($this->leadOf($this->host));

        Mail::assertNotQueued(LeadConvertedOnBehalfMail::class);
        foreach ([$this->wlpAdmin, $this->hostAdmin] as $user) {
            Notification::assertNotSentTo($user, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === self::TYPE);
        }
    }

    #[Test]
    public function a_failed_notice_never_undoes_the_conversion(): void
    {
        $notifier = app(Notifier::class);
        $this->mock(Notifier::class)->shouldReceive('send')->andReturnUsing(
            fn (string $type, ...$args) => $type === self::TYPE ? throw new \RuntimeException('mail down') : $notifier->send($type, ...$args)
        );
        $this->actingAsUser($this->hostAdmin);

        $borrower = $this->convert($lead = $this->leadOf($this->wlp));

        $this->assertSame($this->wlp->id, $borrower->partner_id);
        $this->assertSame(Lead::BORROWER_TYPE_LEAD, $lead->fresh()->convert_to);
    }
}
