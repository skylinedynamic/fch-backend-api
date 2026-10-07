<?php

namespace Tests\Feature\FundingRequest;

use App\Mail\FundingRequest\UnderwritingStatusUpdated;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The status-update mail is queued, so it is rendered by a worker with no
 * authenticated user and no request Origin. Its branding must come from the
 * partner resolved when it was built - not from getConfigPartner(), which in
 * a worker answers "host" for everyone.
 */
#[CoversClass(UnderwritingStatusUpdated::class)]
class UnderwritingStatusUpdatedMailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_worker_renders_it_under_the_brand_resolved_when_it_was_built(): void
    {
        $certified = new Partner(['is_host' => false, 'partner_type' => Partner::PARTNER_TYPE_CERTIFIED, 'company_name' => 'Certified Co']);
        $certified->id = 5;

        $request = new FundingRequest(['status' => FundingRequest::STATUS_APPROVED]);
        $request->setRelation('partner', $certified);
        $request->setRelation('borrower', null);
        $request->setRelation('fundingSequence', null);

        $mail = new UnderwritingStatusUpdated($request);
        // What the request that built it resolved (getConfigPartner() there).
        $mail->setMailPartnerId(77);

        $this->mock(AppConfigurationService::class)
            ->shouldReceive('getBrandingSettings')->once()->with(77)->andReturn([]);

        // No authenticated user from here on - as in a queue worker.
        $mail->build();
    }
}
