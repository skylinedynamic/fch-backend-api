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

    // Never written to the log in the clear - the entry is visible to the
    // borrower and to staff, and a changed SSN needs recording, not repeating.
    private const SENSITIVE_FIELD_MARKERS = ['ssn', 'ein', 'tax_id', 'date_of_birth', 'account_number', 'routing'];

    public function __construct(private ActivityLogService $activityLog)
    {
    }

    /**
     * A real field change to a signed application, which just cost the borrower
     * their signature.
     *
     * @param  array<int, string>  $changedFields
     * @param  array<string, array{from: mixed, to: mixed}>  $changes  old and new value per changed field
     */
    public function logEditRequiringResign(
        FundingApplication $application,
        array $changedFields,
        string $actor,
        string $previousStatus,
        array $changes = []
    ): void {
        $actorName = $this->actorName($actor);
        $loggedChanges = $this->presentableChanges($changes);
        $fieldList = $loggedChanges === []
            ? $this->humanizeFields($changedFields)
            : $this->describeChanges($loggedChanges);
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
                'changes' => $loggedChanges,
                'previous_status' => $previousStatus,
                'new_status' => $application->status,
                'requires_resign' => true,
            ]
        );
    }

    /**
     * A real field change to an application that has been submitted before but
     * carries no signature right now (a re-sign is already owed), so this edit
     * cost nothing further. Still recorded: every change to a submitted
     * application is listed, not only the first one after signing.
     *
     * @param  array<int, string>  $changedFields
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    public function logEdit(FundingApplication $application, array $changedFields, string $actor, array $changes = []): void
    {
        $actorName = $this->actorName($actor);
        $loggedChanges = $this->presentableChanges($changes);
        $fieldList = $loggedChanges === []
            ? $this->humanizeFields($changedFields)
            : $this->describeChanges($loggedChanges);
        $count = count($changedFields);

        $this->write(
            $application,
            'Funding Application Edited',
            ucfirst($actor) . " {$actorName} edited {$count} field(s) on the application ({$fieldList}).",
            [
                'visible_to_borrower' => '1',
                'actor' => $actor,
                'actor_name' => $actorName,
                'changed_fields' => $changedFields,
                'changed_field_count' => $count,
                'changes' => $loggedChanges,
                'requires_resign' => (bool) $application->requires_resign,
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
    /**
     * @param  array<string, mixed>|null  $previousSignature  the signature this one replaces, if any
     * @param  array<string, mixed>|null  $newSignature       the signature just given
     */
    public function logSubmitted(
        FundingApplication $application,
        string $signerName,
        bool $wasResubmission,
        string $actor = self::ACTOR_BORROWER,
        ?array $previousSignature = null,
        ?array $newSignature = null
    ): void {
        $subject = $wasResubmission
            ? 'Funding Application Re-signed and Re-submitted'
            : 'Funding Application Submitted';

        $description = $wasResubmission
            ? "Funding Application re-signed and re-submitted by {$signerName}. The new signature replaces the previous one."
            : "Funding Application submitted by {$signerName}.";

        // What was signed, old and new, kept on the entry itself: the image for
        // an e-signature, the typed name otherwise. The application only ever
        // holds its latest two, so this is the lasting record of each change.
        $signatures = array_filter([
            'previous' => $wasResubmission ? $this->signatureForLog($previousSignature) : null,
            'new' => $this->signatureForLog($newSignature),
        ]);

        $this->write($application, $subject, $description, [
            'visible_to_borrower' => '1',
            'actor' => $actor,
            'actor_name' => $signerName,
            'is_resubmission' => $wasResubmission,
            'signatures' => $signatures ?: null,
        ]);
    }

    /**
     * A signature reduced to what the log shows: how it was given, the name,
     * the image if it was an e-signature, and when.
     *
     * @param  array<string, mixed>|null  $signature
     * @return array<string, mixed>|null
     */
    private function signatureForLog(?array $signature): ?array
    {
        if (! $signature || (empty($signature['signature_name']) && empty($signature['signature_image']))) {
            return null;
        }

        $isEsign = ($signature['signature_type'] ?? 'typed') === 'esign' && ! empty($signature['signature_image']);

        return [
            'type' => $isEsign ? 'esign' : 'typed',
            'name' => $signature['signature_name'] ?? null,
            'image' => $isEsign ? $signature['signature_image'] : null,
            'signed_at' => $signature['signed_at'] ?? null,
            'signature_date' => $signature['signature_date'] ?? null,
        ];
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
     * The changes as they may be stored and shown: values flattened to text, and
     * sensitive ones replaced so the log records that they moved without
     * repeating them.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     * @return array<string, array{label: string, from: string, to: string}>
     */
    private function presentableChanges(array $changes): array
    {
        $presentable = [];

        foreach ($changes as $field => $change) {
            $sensitive = $this->isSensitive($field);

            $presentable[$field] = [
                'label' => ucwords(str_replace('_', ' ', $field)),
                'from' => $sensitive ? '[hidden]' : $this->displayValue($change['from'] ?? null),
                'to' => $sensitive ? '[hidden]' : $this->displayValue($change['to'] ?? null),
            ];
        }

        return $presentable;
    }

    /**
     * @param  array<string, array{label: string, from: string, to: string}>  $changes
     */
    private function describeChanges(array $changes): string
    {
        return implode('; ', array_map(
            static fn (array $change) => $change['from'] === '[hidden]'
                ? "{$change['label']}: changed"
                : "{$change['label']}: \"{$change['from']}\" to \"{$change['to']}\"",
            $changes
        ));
    }

    private function isSensitive(string $field): bool
    {
        foreach (self::SENSITIVE_FIELD_MARKERS as $marker) {
            if ($field === $marker || str_contains($field, $marker . '_') || str_ends_with($field, '_' . $marker)) {
                return true;
            }
        }

        return false;
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'empty';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value);
        }

        return (string) $value;
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
