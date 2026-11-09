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
 * /common/request-application/funding-application/{id}) tells the white-label
 * partner, the certified partner and the borrower when the application can't
 * be edited any more, and why.
 *
 * The host's team follows the same rule (decision 2026-11-03, replacing the
 * 2026-10-29 one that let it edit at any status): only the statuses in
 * config `statuses.editable` are editable, for everyone.
 */
#[CoversClass(FundingApplicationResource::class)]
class FundingApplicationDetailLockedReasonTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private ?Partner $host = null;

    public static function viewerProvider(): array
    {
        return self::lockedViewerProvider();
    }

    /** Everyone the status lock applies to - the host's team included. */
    public static function lockedViewerProvider(): array
    {
        return [
            'host on a certified partner\'s application' => ['host'],
            'host on a white-label partner\'s application' => ['host_on_white_label'],
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

    /**
     * The statuses at which everyone - host, white-label partner, certified
     * partner and borrower - may edit (decision 2026-10-29).
     */
    public static function editableStatusProvider(): array
    {
        return [
            'On Hold' => [FundingApplication::ON_HOLD],
            'Draft' => [FundingApplication::DRAFT],
            'Submitted - Pending Review' => [FundingApplication::PENDING],
            'Submitted (Received)' => [FundingApplication::SUBMITTED],
            'Underwriting Review' => [FundingApplication::UNDERWRITING_REVIEW],
            'Missing Documents' => [FundingApplication::MISSING_DOCUMENTS],
            'In Verification' => [FundingApplication::IN_VERIFICATION],
            'Pending Clarification' => [FundingApplication::PENDING_CLARIFICATION],
            'Ready for Submission' => [FundingApplication::READY_FOR_SUBMISSION],
            'Approved (Conditional)' => [FundingApplication::APPROVED_CONDITIONAL],
            'Approved (Final)' => [FundingApplication::APPROVED_FINAL],
        ];
    }

    #[Test]
    public function exactly_these_eleven_statuses_are_editable(): void
    {
        $this->assertSame(
            array_column(self::editableStatusProvider(), 0),
            FundingApplication::editableStatuses()
        );
    }

    #[Test]
    public function every_other_status_is_locked_for_the_partners_and_the_borrower(): void
    {
        $locked = array_diff(array_keys(config('funding_application.statuses.order')), FundingApplication::editableStatuses());

        $this->assertCount(15, $locked);
        foreach ($locked as $status) {
            $this->assertFalse((new FundingApplication(['status' => $status]))->isEditable(), $status);
        }
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
        $partner = in_array($viewer, ['white_label', 'host_on_white_label'], true)
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
            'host', 'host_on_white_label' => $this->makePartnerAdmin($host),
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
    #[DataProvider('lockedViewerProvider')]
    public function every_viewer_is_told_a_locked_application_cannot_be_edited(string $viewer): void
    {
        foreach (self::lockedStatusProvider() as [$status]) {
            [$user, $applicationId] = $this->viewerAndApplication($viewer, $status);

            $data = $this->details($user, $applicationId);

            $this->assertFalse($data['is_editable'], "{$viewer} at {$status}");
            // The borrower is pointed to their funding advisor; the host and the
            // partners are the advisors, so they are just told it is locked.
            $this->assertSame(
                $viewer === 'borrower'
                    ? __('application.funding_application_locked', ['status' => $status])
                    : __('application.funding_application_locked_staff', ['status' => $status]),
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
