<?php

namespace Tests\Feature\General;

use App\Http\Controllers\Api\V1\Borrower\ActivityLog\BorrowerActivityLogController;
use App\Http\Controllers\Api\V1\Borrower\Funding\FundingController;
use App\Http\Controllers\Api\V1\Borrower\PreQualification\PreQualificationController;
use App\Http\Controllers\Api\V1\FCH\Common\CommonController;
use App\Http\Controllers\Api\V1\FCH\Global\UserDetailController;
use App\Http\Controllers\Api\V1\Global\Branding\BrandingController;
use App\Http\Controllers\Api\V1\Global\General\LocationController;
use App\Http\Controllers\Api\V1\Global\Website\EmbedForm\PublicEmbedFormController;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesFundingTypes;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The small read-only endpoints the portals and the embed form load on start:
 * branding, locations, the signed-in user's details, the embed form's config
 * and per-step check, the borrower dashboard lists, the realtime channel
 * check.
 */
#[CoversClass(BrandingController::class)]
#[CoversClass(LocationController::class)]
#[CoversClass(UserDetailController::class)]
#[CoversClass(PublicEmbedFormController::class)]
#[CoversClass(BorrowerActivityLogController::class)]
#[CoversClass(CommonController::class)]
#[CoversClass(FundingController::class)]
#[CoversClass(PreQualificationController::class)]
class SmallEndpointsApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;
    use CreatesFundingTypes;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
    }

    #[Test]
    public function branding_loads_without_signing_in(): void
    {
        $this->getJson('/api/v1/common/branding')->assertOk();

        $css = $this->get('/api/branding.css')->assertOk();
        $this->assertStringContainsString('text/css', $css->headers->get('Content-Type'));
    }

    #[Test]
    public function locations_list_and_filter_by_country(): void
    {
        $all = $this->getJson('/api/v1/common/locations')->assertOk()->json('data');
        $this->assertNotEmpty($all);

        $code = $all[0]['code'];
        $one = $this->getJson("/api/v1/common/locations?country={$code}")->assertOk()->json('data');
        $this->assertCount(1, $one);
    }

    #[Test]
    public function the_signed_in_users_details_load(): void
    {
        $user = $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->getJson('/api/v1/common/user-details')
            ->assertOk()
            ->assertJsonPath('data.user.is_host', false)
            ->assertJsonPath('data.user.is_refresh', false);
    }

    #[Test]
    public function the_embed_form_loads_by_its_key_and_checks_each_step(): void
    {
        EmbedFormConfig::create(['partner_id' => $this->partner->id, 'public_key' => 'live-key', 'form_title' => 'Apply', 'is_active' => true]);
        EmbedFormConfig::create(['partner_id' => $this->makeCertifiedPartner()->id, 'public_key' => 'off-key', 'form_title' => 'Off', 'is_active' => false]);

        $this->getJson('/api/v1/embed/config/live-key')->assertOk();
        $this->getJson('/api/v1/embed/config/off-key')->assertNotFound();
        $this->getJson('/api/v1/embed/config/no-such-key')->assertNotFound();

        $this->postJson('/api/v1/embed/validate-step/live-key/1', [])->assertUnprocessable();
        $this->postJson('/api/v1/embed/validate-step/live-key/99', [])->assertNotFound();
    }

    /**
     * The public weekly executive summary was removed (findings D11): it
     * served platform revenue, partner payouts and client names with their
     * commissions to anyone, signed in or not, and nothing used it.
     */
    #[Test]
    public function the_weekly_summary_no_longer_exists(): void
    {
        $this->getJson('/api/v1/common/weekly-summary')->assertNotFound();
        $this->assertFalse(class_exists('App\Http\Controllers\Api\V1\FCH\Admin\WeeklySummary\WeeklySummaryController'));
        $this->assertFalse(class_exists('App\Services\FCH\Admin\WeeklySummary\WeeklySummaryService'));
    }

    #[Test]
    public function the_borrower_dashboard_lists_load(): void
    {
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->getJson('/api/v1/borrower/activity-logs')->assertOk();
        $this->getJson('/api/v1/borrower/borrower-theme')->assertOk();
        $this->getJson('/api/v1/borrower/funding')->assertOk();
    }

    #[Test]
    public function the_funding_step_of_the_pre_qualification_saves_onto_the_draft(): void
    {
        $this->actingAsUser($this->makeBorrowerUser($this->partner));
        $band = array_key_first(config('prequalification_aue.personal_credit_bands', []));
        // The purpose is a lender product category (request 2026-11-05).
        $this->createFundingTypes(['Working Capital']);

        $this->postJson('/api/v1/borrower/pre-qualification/funding-info', [
            'funding_requested_amount' => 50000,
            'funding_urgency' => 'Within 30 days',
            'purpose_of_funding' => 'Working Capital',
            'personal_credit_score' => $band,
            'estimated_business_credit_score' => '50-79',
            'existing_business_debt' => false,
        ])->assertOk()->assertJsonPath('data.funding_requested_amount', '$50,000.00');

        $this->postJson('/api/v1/borrower/pre-qualification/funding-info', ['funding_requested_amount' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('funding_requested_amount');
    }

    #[Test]
    public function a_user_may_listen_only_on_their_own_channel(): void
    {
        config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
            'driver' => 'pusher', 'key' => 'k', 'secret' => 's', 'app_id' => '1', 'options' => ['cluster' => 'mt1'],
        ]]);
        // Channels are registered on the broadcaster that was the default at
        // boot (null in the suite), so register them again on this one.
        require base_path('routes/channels.php');
        $me = $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $other = $this->makePartnerAdmin($this->partner);

        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$me->id}"])->assertOk();
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$other->id}"])->assertForbidden();
    }
}
