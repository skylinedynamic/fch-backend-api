<?php

namespace App\Services\FCH\Common\Application;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\FundingApplication;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use Illuminate\Support\Facades\Log;

/**
 * The audit trail for who touched a funding application after it was signed.
 *
 * An edit to a submitted application is a compliance event, not just a save: the
 * borrower attested to specific figures, and somebody changed them. Every one of
 * these is recorded with the actor and the exact fields that moved, so a later
 * question of "what did the borrower actually sign for" has an answer.
 *
 * Logging never fails a save. If the activity log is unavailable the edit still
 * goes through and the failure is written to the application log instead - losing
 * the borrower's work to protect the audit trail would be the worse trade.
 */
class FundingApplicationAuditLogger
{
    public const ACTOR_BORROWER = 'borrower';
    public const ACTOR_PARTNER = 'partner';

    public function __construct(private ActivityLogService $activityLog)
    {
    }

    /**
     * A real field change to a signed application, which just cost the borrower
     * their signature.
     *
     * @param  array<int, string>  $changedFields
     */
    public function logEditRequiringResign(
        FundingApplication $application,
        array $changedFields,
        string $actor,
        string $previousStatus
    ): void {
        $actorName = $this->actorName($actor);
        $fieldList = $this->humanizeFields($changedFields);
        $count = count($changedFields);

        $description = $actor === self::ACTOR_PARTNER
            ? "Partner {$actorName} edited {$count} field(s) on a submitted application ({$fieldList}). "
                . "The borrower's signature has been cleared and the application returned to "
                . FundingApplication::resignTargetStatus() . ' - borrower re-signature required.'
            : "Borrower {$actorName} edited {$count} field(s) on a submitted application ({$fieldList}). "
                . 'The previous signature has been cleared - re-signature required before the '
                . 'application returns to review.';

        $this->write(
            $application,
            'Funding Application Edited - Re-signature Required',
            $description,
            [
                'visible_to_borrower' => '1',
                'actor' => $actor,
                'actor_name' => $actorName,
                'changed_fields' => $changedFields,
                'changed_field_count' => $count,
                'previous_status' => $previousStatus,
                'new_status' => $application->status,
                'requires_resign' => true,
            ]
        );
    }

    /**
     * An edit that was refused because the application has moved past the
     * borrower's and partner's reach. Worth recording: a refused write attempt on
     * a file that is already with a lender is exactly the kind of thing someone
     * asks about afterwards.
     */
    public function logBlockedEdit(FundingApplication $application, string $actor): void
    {
        $actorName = $this->actorName($actor);

        $this->write(
            $application,
            'Funding Application Edit Blocked',
            ucfirst($actor) . " {$actorName} attempted to edit this application while it is "
                . "'{$application->status}'. Applications in this status are read-only for "
                . 'borrowers and partners; no changes were saved.',
            [
                'visible_to_borrower' => '1',
                'actor' => $actor,
                'actor_name' => $actorName,
                'blocked_status' => $application->status,
            ]
        );
    }

    /**
     * The application was signed and submitted. `$wasResubmission` separates a
     * first submission from a borrower signing again after an edit cleared the
     * previous signature.
     */
    public function logSubmitted(
        FundingApplication $application,
        string $signerName,
        bool $wasResubmission,
        string $actor = self::ACTOR_BORROWER
    ): void {
        $subject = $wasResubmission
            ? 'Funding Application Re-signed and Re-submitted'
            : 'Funding Application Submitted';

        $description = $wasResubmission
            ? "Funding Application re-signed and re-submitted by {$signerName} after an edit "
                . 'invalidated the previous signature.'
            : "Funding Application submitted by {$signerName}.";

        $this->write($application, $subject, $description, [
            'visible_to_borrower' => '1',
            'actor' => $actor,
            'actor_name' => $signerName,
            'is_resubmission' => $wasResubmission,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function write(FundingApplication $application, string $subject, string $description, array $properties): void
    {
        try {
            $this->activityLog->logModelEvent(
                $application,
                ActivityLog::TYPE_ACTIVITY,
                $properties,
                $subject,
                $description
            );
        } catch (\Throwable $e) {
            Log::error('Failed to write funding application activity log', [
                'application_id' => $application->id,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function actorName(string $actor): string
    {
        $user = auth()->user();

        if ($user) {
            $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

            if ($name !== '') {
                return $name;
            }

            if (! empty($user->name)) {
                return $user->name;
            }
        }

        return $actor === self::ACTOR_PARTNER ? 'Partner' : 'Borrower';
    }

    /**
     * Column names are what the audit needs, but a human reads the log - turn
     * `business_phone_no` into `Business Phone No`.
     *
     * @param  array<int, string>  $fields
     */
    private function humanizeFields(array $fields): string
    {
        if ($fields === []) {
            return 'none';
        }

        return implode(', ', array_map(
            static fn (string $field) => ucwords(str_replace('_', ' ', $field)),
            $fields
        ));
    }
}
