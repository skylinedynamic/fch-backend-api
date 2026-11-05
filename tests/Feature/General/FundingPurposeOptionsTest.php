<?php

namespace Tests\Feature\General;

use App\Http\Controllers\Api\V1\Global\General\FundingPurposeController;
use App\Services\Global\General\FundingPurposeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Request 2026-11-05: the funding application's Purpose of Funds list comes
 * from the API - GET /api/v1/common/funding-purposes, the one list in
 * config/funding_application.php (`options.purposes_of_funds`), the same one
 * the embed form shows - instead of a copy in each portal.
 */
#[CoversClass(FundingPurposeController::class)]
#[CoversClass(FundingPurposeService::class)]
class FundingPurposeOptionsTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    #[Test]
    public function every_portal_gets_the_purposes_in_their_order(): void
    {
        config(['funding_application.options.purposes_of_funds' => ['Working Capital', 'Furniture, Fixtures & Equipment (FF&E)', 'Other']]);
        $host = $this->makeHostPartner();
        $partner = $this->makeWhiteLabelPartner();

        foreach ([$this->makePartnerAdmin($host), $this->makePartnerAdmin($partner), $this->makeBorrowerUser($partner)] as $user) {
            $this->actingAsUser($user);

            $this->getJson('/api/v1/common/funding-purposes')
                ->assertOk()
                ->assertExactJson([
                    'status' => true,
                    'message' => __('application.funding_purposes_fetched'),
                    'data' => ['Working Capital', 'Furniture, Fixtures & Equipment (FF&E)', 'Other'],
                ]);
        }
    }

    #[Test]
    public function it_is_the_list_the_embed_form_shows(): void
    {
        $this->getJson('/api/v1/common/funding-purposes')
            ->assertOk()
            ->assertJsonPath('data', config('funding_application.options.purposes_of_funds'));
    }

    /** Public reference data, like common/locations and common/funding-types. */
    #[Test]
    public function a_visitor_who_is_not_signed_in_gets_them_too(): void
    {
        $this->getJson('/api/v1/common/funding-purposes')->assertOk();
    }

    #[Test]
    public function a_failure_loading_them_is_a_server_error_and_logged(): void
    {
        Log::spy();
        $this->mock(FundingPurposeService::class, function ($service) {
            $service->shouldReceive('purposes')->andThrow(new RuntimeException('config gone'));
        });

        $this->getJson('/api/v1/common/funding-purposes')
            ->assertStatus(500)
            ->assertJsonPath('message', __('application.funding_purposes_fetch_failed'));

        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'config gone'))->once();
    }
}
