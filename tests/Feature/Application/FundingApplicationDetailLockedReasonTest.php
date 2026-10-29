<?php

namespace Tests\Feature\Application;

use App\Http\Resources\FCH\Common\Application\FundingApplicationResource;
use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * SCRUM-331: the funding application details page (both portals read
 * /common/request-application/funding-application/{id}) tells every viewer -
 * host, white-label partner, certified partner and the borrower - when the
 * application can't be edited any more, and why.
 */
#[CoversClass(FundingApplicationResource::class)]
class FundingApplicationDetailLockedReasonTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private ?Partner $host = null;

    public static function viewerProvider(): array
    {
        return [
            'host' => ['host'],
            'white-label partner' => ['white_label'],
            'certified partner' => ['certified'],
            'borrower' => ['borrower'],
        ];
    }

    public static function lockedStatusProvider(): array
    {
        return [
            'Matched to Lender' => [FundingApplication::MATCHED_TO_LENDER],
            'Funded' => [FundingApplication::FUNDED],
            'Withdrawn' => [FundingApplication::WITHDRAWN],
            'Closed - Error' => [FundingApplication::CLOSED_ERROR],
        ];
    }

    public static function editableStatusProvider(): array
    {
        return [
            'Approved (Final)' => [FundingApplication::APPROVED_FINAL],
            'Pending Clarification' => [FundingApplication::PENDING_CLARIFICATION],
            'Ready for Submission' => [FundingApplication::READY_FOR_SUBMISSION],
        ];
    }

    /**
     * An application in the given status, and a user of the given kind who may
     * open it: the host's staff open any book, a partner its own, a borrower
     * their own.
     *
     * @return array{0: User, 1: int}
     */
    private function viewerAndApplication(string $viewer, string $status): array
    {
        $host = $this->host ??= $this->makeHostPartner();
        // The host's staff and the borrower look at a certified partner's application.
        $partner = $viewer === 'white_label'
            ? $this->makeWhiteLabelPartner()
            : $this->makeCertifiedPartner();

        $borrowerUser = $this->makeBorrowerUser($partner);

        $applicationId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $borrowerUser->entity_id,
            'partner_id' => $partner->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = match ($viewer) {
            'host' => $this->makePartnerAdmin($host),
            'borrower' => $borrowerUser,
            default => $this->makePartnerAdmin($partner),
        };

        return [$user, $applicationId];
    }

    private function details(User $user, int $applicationId): array
    {
        $this->actingAsUser($user);

        return $this->getJson("/api/v1/common/request-application/funding-application/{$applicationId}")
            ->assertOk()
            ->json('data');
    }

    #[Test]
    #[DataProvider('viewerProvider')]
    public function every_viewer_is_told_a_locked_application_cannot_be_edited(string $viewer): void
    {
        foreach (self::lockedStatusProvider() as [$status]) {
            [$user, $applicationId] = $this->viewerAndApplication($viewer, $status);

            $data = $this->details($user, $applicationId);

            $this->assertFalse($data['is_editable'], "{$viewer} at {$status}");
            $this->assertSame(
                __('application.funding_application_locked', ['status' => $status]),
                $data['locked_reason'],
                "{$viewer} at {$status}"
            );
        }
    }

    #[Test]
    #[DataProvider('viewerProvider')]
    public function an_editable_application_carries_no_locked_reason(string $viewer): void
    {
        foreach (self::editableStatusProvider() as [$status]) {
            [$user, $applicationId] = $this->viewerAndApplication($viewer, $status);

            $data = $this->details($user, $applicationId);

            $this->assertTrue($data['is_editable'], "{$viewer} at {$status}");
            $this->assertNull($data['locked_reason'], "{$viewer} at {$status}");
        }
    }
}
