<?php

namespace Tests\Feature\Partner;

use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-027, partner side: like a client, a partner on a paid plan can't be
 * put back on the free trial through the API (the edit form only offers the
 * trial while the partner is on it). Trial → paid and plain edits stay.
 */
#[CoversClass(UpdatePartnerRequest::class)]
class PartnerPaidToTrialBlockedTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->hostAdmin = $this->makePartnerAdmin($this->makeHostPartner());
        $this->actingAsUser($this->hostAdmin);
    }

    #[Test]
    public function a_paid_partner_cannot_be_put_on_the_free_trial(): void
    {
        $partner = $this->makeWhiteLabelPartner(['is_free_trail' => false]);

        $this->putJson("/api/v1/admin/partners/{$partner->id}", [
            'is_free_trail' => true,
            'free_trail_end_date' => now()->addDays(14)->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_free_trail' => __('partner.validation.paid_to_trial_not_allowed')]);

        $this->assertFalse((bool) $partner->fresh()->is_free_trail);
    }

    #[Test]
    public function a_paid_partner_saved_as_paid_still_saves(): void
    {
        $partner = $this->makeWhiteLabelPartner(['is_free_trail' => false]);

        $this->putJson("/api/v1/admin/partners/{$partner->id}", ['full_name' => 'Renamed Partner', 'is_free_trail' => false])->assertOk();

        $this->assertSame('Renamed Partner', $partner->fresh()->full_name);
    }

    #[Test]
    public function a_trial_partner_kept_on_the_trial_still_saves(): void
    {
        $partner = $this->makeWhiteLabelPartner(['is_free_trail' => true, 'free_trail_end_date' => now()->addDays(10)->toDateString()]);

        $this->putJson("/api/v1/admin/partners/{$partner->id}", [
            'full_name' => 'Still Trial',
            'is_free_trail' => true,
            'free_trail_end_date' => now()->addDays(20)->toDateString(),
        ])->assertOk();

        $this->assertTrue((bool) $partner->fresh()->is_free_trail);
    }

    #[Test]
    public function a_trial_partner_moving_to_paid_is_not_refused_as_paid_to_trial(): void
    {
        $partner = $this->makeWhiteLabelPartner(['is_free_trail' => true, 'free_trail_end_date' => now()->addDays(10)->toDateString()]);

        $this->putJson("/api/v1/admin/partners/{$partner->id}", ['is_free_trail' => false])
            ->assertJsonMissingValidationErrors('is_free_trail');
    }
}
