<?php

namespace App\Services\FCH\Common\ActivityLog;

use App\Mail\ActivityLog\TaskAssignedMail;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Notification\Notifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells the staff member a Task activity was assigned to - in the bell and by
 * email - whether or not the activity also set a reminder.
 *
 * Nobody is told about a task they assigned to themselves. A failure is logged
 * and swallowed: the activity is already saved, and a notification problem
 * must never undo or fail it.
 */
class TaskAssignmentNotifier
{
    /** Subject model => notification key suffix and portal path. */
    private const RECORDS = [
        Partner::class => ['partner', '/partners/'],
        Borrower::class => ['client', '/clients/'],
        Lead::class => ['lead', '/leads/'],
        FundingApplication::class => ['funding_application', '/intake-review/funding-application/'],
        FundingRequest::class => ['funding_request', '/intake-review/funding-request/'],
    ];

    public function __construct(private readonly Notifier $notifier)
    {
    }

    public function notify(ActivityLog $log): void
    {
        try {
            $task = $log->properties['task'] ?? null;
            $record = self::RECORDS[$log->subject_type] ?? null;

            if (!is_array($task) || !$record || empty($task['assigned_to'])) {
                return;
            }

            $assignee = User::find($task['assigned_to']);
            if (!$assignee || (int) $assignee->id === (int) $log->causer_id) {
                return;
            }

            [$recordKey, $path] = $record;
            $path .= $log->subject_id;
            $assignerName = $log->causer?->name ?: __('activity_log.task_assigned_someone');
            $subject = (string) ($log->properties['subject'] ?? $log->event);
            $recordLabel = $this->recordLabel($recordKey, $log);
            $dueLocal = ActivityLog::taskDueDateLocal($task['due_date'] ?? null);
            $dueDate = $dueLocal ? Carbon::parse($dueLocal)->format('M j, Y g:i A') : null;
            $priorityLabel = ActivityLog::TASK_PRIORITY_LABELS[$task['priority'] ?? ''] ?? null;
            $statusLabel = ActivityLog::TASK_STATUS_LABELS[$task['status'] ?? ''] ?? null;

            $mail = $assignee->email
                ? (new TaskAssignedMail([
                    'assignee_name' => $assignee->name,
                    'assigner_name' => $assignerName,
                    'subject' => $subject,
                    'description' => $this->plainText($log->properties['description'] ?? null),
                    'record' => $recordLabel,
                    'due_date' => $dueDate,
                    'priority' => $task['priority'] ?? null,
                    'priority_label' => $priorityLabel,
                    'status' => $task['status'] ?? null,
                    'status_label' => $statusLabel,
                    'path' => $path,
                ], $assignee->partner_id))->to($assignee->email)
                : null;

            $this->notifier->send('activity_log.task_assigned.' . $recordKey, $assignee, [
                'id' => $log->subject_id,
                'subject' => $subject,
                'assigned_by' => $assignerName,
                'record' => $recordLabel,
                'due_date' => $dueDate ?? '-',
                'priority' => $priorityLabel ?? '-',
                'status' => $statusLabel ?? '-',
            ], mail: $mail);
        } catch (Throwable $e) {
            Log::error(__('activity_log.task_assigned_notify_failed', [
                'id' => $log->id,
                'error' => $e->getMessage(),
            ]));
        }
    }

    /** "client Jane Doe", "funding request #12" - what the task is about. */
    private function recordLabel(string $recordKey, ActivityLog $log): string
    {
        $subject = $log->subject;

        $name = match (true) {
            $subject instanceof Borrower => personName($subject->first_name, $subject->middle_name, $subject->last_name),
            $subject instanceof Lead => $subject->company_name ?: $subject->contact_person,
            $subject instanceof Partner => $subject->company_name,
            default => null,
        };

        return __('activity_log.task_assigned_record_' . $recordKey, [
            'name' => $name ?: '#' . $log->subject_id,
            'id' => $log->subject_id,
        ]);
    }

    /** The activity's rich-text description as a short plain-text excerpt. */
    private function plainText(?string $html): ?string
    {
        if (!$html) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));

        return $text === '' ? null : Str::limit($text, 500);
    }
}
