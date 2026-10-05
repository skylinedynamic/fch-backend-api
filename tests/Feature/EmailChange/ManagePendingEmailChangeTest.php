<?php

namespace Tests\Feature\EmailChange;

use App\Http\Controllers\Api\V1\FCH\Common\Account\EmailChangeController;
use App\Mail\Account\EmailChangeConfirmationMail;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Global\Account\EmailChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An admin resending or withdrawing a pending email change from the edit
 * screen. Pending changes are account data, so they stay inside the partner
 * that owns the login — except for the host, which manages every partner.
 */
#[CoversClass(EmailChangeController::class)]
class ManagePendingEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private Partner $acme;
    private User $pending;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->host = $this->partner('Host LLC', ['is_host' => true]);
        $this->acme = $this->partner('Acme LLC');

        $this->pending = $this->login('old@example.test', $this->acme);
        app(EmailChangeService::class)->request($this->pending, 'new@example.test');
    }

    private function partner(string $name, array $attributes = []): Partner
    {
        return Partner::create(array_merge([
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => $name,
            'full_name' => "{$name} Owner",
            'email' => strtolower(str_replace(' ', '', $name)) . '@example.test',
        ], $attributes));
    }

    private function login(string $email, Partner $partner): User
    {
        return User::create([
            'name' => 'Pat Doe',
            'email' => $email,
            'password' => Hash::make('secret-password'),
            'status' => User::STATUS['active'],
            'user_type' => User::ADMIN,
            'partner_id' => $partner->id,
        ]);
    }

    #[Test]
    public function an_admin_can_resend_a_pending_change_in_their_own_partner(): void
    {
        Passport::actingAs($this->login('admin@acme.test', $this->acme));

        $this->postJson('/api/v1/common/email-change/resend', ['user_id' => $this->pending->id])->assertOk();

        // One from the original request, one from the resend.
        Mail::assertSent(EmailChangeConfirmationMail::class, 2);
    }

    #[Test]
    public function an_admin_can_cancel_a_pending_change_in_their_own_partner(): void
    {
        Passport::actingAs($this->login('admin@acme.test', $this->acme));

        $this->postJson('/api/v1/common/email-change/cancel', ['user_id' => $this->pending->id])->assertOk();

        $this->assertNull($this->pending->refresh()->pending_email);
    }

    #[Test]
    public function the_host_can_manage_a_pending_change_in_any_partner(): void
    {
        Passport::actingAs($this->login('admin@host.test', $this->host));

        $this->postJson('/api/v1/common/email-change/cancel', ['user_id' => $this->pending->id])->assertOk();

        $this->assertNull($this->pending->refresh()->pending_email);
    }

    #[Test]
    public function another_partner_cannot_touch_a_pending_change(): void
    {
        $other = $this->partner('Other LLC');
        Passport::actingAs($this->login('admin@other.test', $other));

        $this->postJson('/api/v1/common/email-change/cancel', ['user_id' => $this->pending->id])->assertForbidden();

        $this->assertSame('new@example.test', $this->pending->refresh()->pending_email);
    }

    #[Test]
    public function resending_when_nothing_is_pending_is_refused(): void
    {
        app(EmailChangeService::class)->cancel($this->pending);
        Passport::actingAs($this->login('admin@acme.test', $this->acme));

        $this->postJson('/api/v1/common/email-change/resend', ['user_id' => $this->pending->id])->assertStatus(422);
    }
}
