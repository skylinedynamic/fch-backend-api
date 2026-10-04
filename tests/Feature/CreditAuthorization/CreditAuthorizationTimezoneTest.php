<?php

namespace Tests\Feature\CreditAuthorization;

use App\Models\Borrower\Borrower;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The partner portal shows a client's authorisations in the viewer's own time
 * zone, converted here from the X-Timezone header - the browser only displays
 * what it is given. The stored values, and the PDF built from them, stay UTC.
 */
class CreditAuthorizationTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        $host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Four Corner Host',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $host->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ]);

        CreditAuthorizationConsent::create([
            'borrower_id' => $this->borrower->id,
            'authorization_version' => '1.0',
            'authorization_url' => 'https://example.test/disclosure',
            'credit_authorization_text' => 'I authorize a soft credit inquiry.',
            'e_consent_text' => 'I agree to sign electronically.',
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
            'consented_at' => Carbon::parse('2026-09-01 10:00:00', 'UTC'),
            'pulled_at' => Carbon::parse('2026-09-15 20:30:00', 'UTC'),
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0',
        ]);
    }

    #[Test]
    public function it_converts_the_dates_to_the_viewers_time_zone(): void
    {
        request()->headers->set('X-Timezone', 'Asia/Dhaka'); // UTC+6

        $row = CreditAuthorizationConsent::forBorrower($this->borrower->id)[0];

        $this->assertSame('Sep 01, 2026 04:00 PM', $row['consented_at']);
        // Crosses midnight, so the date changes too.
        $this->assertSame('Sep 16, 2026 02:30 AM', $row['pulled_at']);
    }

    #[Test]
    public function it_gives_the_latest_pull_in_the_viewers_time_zone(): void
    {
        CreditAuthorizationConsent::create([
            'borrower_id' => $this->borrower->id,
            'authorization_version' => '1.0',
            'authorization_url' => 'https://example.test/disclosure',
            'credit_authorization_text' => 'I authorize a soft credit inquiry.',
            'e_consent_text' => 'I agree to sign electronically.',
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
            // Agreed later, but pulled earlier: the latest pull is not
            // simply the newest row.
            'consented_at' => Carbon::parse('2026-09-10 10:00:00', 'UTC'),
            'pulled_at' => Carbon::parse('2026-09-11 10:00:00', 'UTC'),
        ]);
        request()->headers->set('X-Timezone', 'Asia/Dhaka');

        $this->assertSame(
            'Sep 16, 2026 02:30 AM',
            CreditAuthorizationConsent::lastPulledForBorrower($this->borrower->id)
        );
    }

    #[Test]
    public function it_has_no_latest_pull_when_nothing_was_pulled(): void
    {
        CreditAuthorizationConsent::query()->update(['pulled_at' => null]);

        $this->assertNull(CreditAuthorizationConsent::lastPulledForBorrower($this->borrower->id));
    }

    #[Test]
    public function it_leaves_a_missing_pull_empty(): void
    {
        CreditAuthorizationConsent::query()->update(['pulled_at' => null]);
        request()->headers->set('X-Timezone', 'Asia/Dhaka');

        $row = CreditAuthorizationConsent::forBorrower($this->borrower->id)[0];

        $this->assertNull($row['pulled_at']);
    }

    #[Test]
    public function it_keeps_the_stored_values_in_utc(): void
    {
        request()->headers->set('X-Timezone', 'Asia/Dhaka');
        CreditAuthorizationConsent::forBorrower($this->borrower->id);

        $consent = CreditAuthorizationConsent::first();

        $this->assertSame('2026-09-01 10:00:00', $consent->getRawOriginal('consented_at'));
    }
}
