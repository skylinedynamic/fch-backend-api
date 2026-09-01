<?php

namespace App\Services\FCH\Common\Reminder;

use App\Models\Borrower\Borrower;
use App\Models\Common\Reminder;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class ReminderService
{
    /**
     * Get paginated list of reminders for a user.
     */
    public function getReminders(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = $filters['per_page'] ?? 15;
        $sortBy = $filters['sort_by'] ?? 'set_date_time';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;
        $referenceType = $filters['reference_type'] ?? null;
        $status = $filters['status'] ?? null;
        $search = $filters['search'] ?? null;
        
        $validSortColumns = ['created_at', 'send_at', 'status', 'summary', 'set_date_time', 'reference'];
        if (!in_array($sortBy, $validSortColumns)) {
            $sortBy = 'set_date_time'; // Default to the Activity Date & Time
        }
        
        $query = Reminder::where('created_by', $user->entity_id)
                         ->where('created_by_entity_type', $user->entity_type)
                         ->dateBetween($startDate, $endDate)
                         ->referenceType($referenceType)
                         ->statusFilter($status)
                         ->search($search);
            
        return $query->orderBy($sortBy, $sortOrder)->paginate($perPage);
    }

    /**
     * Get due reminders for a user.
     */
    public function getDueReminders(User $user): Collection
    {
        return Reminder::where('created_by', $user->entity_id)
            ->where('created_by_entity_type', $user->entity_type)
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->due();
                })->orWhere('status', Reminder::STATUS_SENT);
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Dismiss a reminder.
     */
    public function dismissReminder(User $user, int $id): ?Reminder
    {
        $reminder = Reminder::where('id', $id)
            ->where('created_by', $user->entity_id)
            ->where('created_by_entity_type', $user->entity_type)
            ->first();

        if (!$reminder) {
            return null;
        }

        $reminder->update([
            'status' => Reminder::STATUS_CANCELLED
        ]);

        return $reminder;
    }

    /**
     * Bulk dismiss reminders.
     */
    public function bulkDismissReminders(User $user, array $ids): int
    {
        return Reminder::whereIn('id', $ids)
            ->where('created_by', $user->entity_id)
            ->where('created_by_entity_type', $user->entity_type)
            ->update([
                'status' => Reminder::STATUS_CANCELLED
            ]);
    }

    /**
     * Dismiss all pending reminders for a user.
     */
    public function dismissAllReminders(User $user): int
    {
        return Reminder::where('created_by', $user->entity_id)
            ->where('created_by_entity_type', $user->entity_type)
            ->where('status', Reminder::STATUS_PENDING)
            ->update([
                'status' => Reminder::STATUS_CANCELLED
            ]);
    }

    /**
     * Snooze a reminder.
     */
    public function snoozeReminder(User $user, int $id, int $minutes): ?Reminder
    {
        $reminder = Reminder::where('id', $id)
            ->where('created_by', $user->entity_id)
            ->where('created_by_entity_type', $user->entity_type)
            ->first();

        if (!$reminder) {
            return null;
        }

        // Add minutes to the current time and update the reminder
        $snoozeTime = now()->addMinutes($minutes);
        $reminder->reminder_date_time = $snoozeTime;
        $reminder->send_at = $snoozeTime;
        $reminder->is_reminder = true;
        $reminder->status = Reminder::STATUS_PENDING;
        $reminder->save();

        return $reminder;
    }

    /**
     * Create a reminder from an activity log.
     */
    public function createFromActivity(
        array $data,
        $subjectId,
        $subjectType,
        $targetBorrowerId = null,
        $targetPartnerId = null,
        $attachmentFiles = []
    ): ?Reminder {
        $remindMe = filter_var($data['remind_me'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$remindMe || empty($data['remind_at'])) {
            return null;
        }

        // Convert remind_at to UTC using the user's timezone
        $timezone = request()->header('X-Timezone', config('app.timezone'));
        $remindAtUtc = Carbon::parse($data['remind_at'], $timezone)->setTimezone('UTC');
        
        $currentUser = auth()->user();

        // Convert activity_date to UTC using the user's timezone if it exists
        $referenceDateUtc = null;
        if (!empty($data['activity_date'])) {
            $referenceDateUtc = Carbon::parse($data['activity_date'], $timezone)->setTimezone('UTC');
        }

        return Reminder::create([
            'entity_id' => $subjectId,
            'entity_type' => $subjectType,
            'created_by' => $currentUser ? $currentUser->entity_id : null,
            'created_by_entity_type' => $currentUser ? $currentUser->entity_type : null,
            'reference' => $data['reference'] ?? null,
            'set_date_time' => $referenceDateUtc,
            'summary' => $data['subject'] ?? $data['event'] ?? null,
            'message' => $data['description'] ?? null,
            'is_reminder' => filter_var($data['is_reminder'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'reminder_date_time' => $remindAtUtc,
            'attachments' => $attachmentFiles,
            'status' => Reminder::STATUS_PENDING,
            'send_at' => $remindAtUtc,
        ]);
    }

    /**
     * Download a reminder attachment file by path.
     *
     * @param  int    $reminderId
     * @param  string $path  The stored attachment path
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     * @throws \Exception
     */
    public function downloadAttachment(int $reminderId, string $path)
    {
        $reminder = Reminder::find($reminderId);

        if (!$reminder) {
            throw new \Exception('Reminder not found', 404);
        }

        $attachments = $reminder->attachments ?? [];

        // Find matching attachment by exact path or by basename
        $attachment = collect($attachments)->first(function ($att) use ($path) {
            return ($att['path'] ?? '') === $path
                || basename($att['path'] ?? '') === basename($path);
        });

        if (!$attachment) {
            throw new \Exception('Attachment not found in this reminder', 404);
        }

        $filePath = $attachment['path'];
        $fileName = $attachment['name'] ?? basename($filePath);

        // Strip leading "storage/" prefix to get the relative path
        $cleanPath = ltrim(preg_replace('#^storage/#', '', $filePath), '/');

        // Try public storage symlink path first, then actual storage path
        $absPath = public_path('storage/' . $cleanPath);
        if (!file_exists($absPath)) {
            $absPath = storage_path('app/public/' . $cleanPath);
        }

        if (!file_exists($absPath)) {
            throw new \Exception("File does not exist on server: {$cleanPath}", 404);
        }

        return response()->download($absPath, $fileName);
    }
}
