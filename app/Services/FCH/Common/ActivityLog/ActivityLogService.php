<?php

namespace App\Services\FCH\Common\ActivityLog;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\ActivityLog\ActivityLogResource;
use App\Models\ActivityLog\ActivityLog;
use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ActivityLogService
{
    use PaginatesApi;

    /**
     * Return latest 15 activity logs without pagination.
     */
    public function list(array $filters)
    {
        $query = ActivityLog::with(['creator', 'assignedUser', 'partner', 'borrower']);
        $user = Auth::user();

        // 1. Scoping by User Type (Security)
        if ($user->partner->is_host !== 1) {
            // Non-admins can only see logs they created or for their specific partner
            $query->where('created_by', $user->id);
            if ($user->partner_id) {
                $query->where('partner_id', $user->partner_id);
            }
        }

        $logs = $query->orderBy('id', 'desc')->limit(15)->get();

        return ApiResponse::success(
            ActivityLogResource::collection($logs),
            __('activity_log.listed')
        );
    }

    /**
     * Store a new Activity Log (Activity or Email).
     */
    public function store(array $data): ActivityLog
    {
        DB::beginTransaction();
        try {
            $logType = $data['log_type'] ?? ActivityLog::TYPE_ACTIVITY;
            
            $logData = [
                'log_type'    => $logType,
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id'   => $data['entity_id'] ?? null,
                'partner_id'  => $data['partner_id'] ?? Auth::user()->partner_id,
                'borrower_id' => $data['borrower_id'] ?? null,
                'created_by'  => Auth::id(),
                'updated_by'  => Auth::id(),
                'status'      => $data['status'] ?? ($logType === ActivityLog::TYPE_EMAIL ? ActivityLog::STATUS_SENT : 'sent'),
                'properties'  => [
                    'subject'       => $data['subject'] ?? null,
                    'description'   => $data['description'] ?? null,
                    'attachments'   => $data['attachments'] ?? null,
                    
                    // Activity specific
                    'reference'     => $data['reference'] ?? null,
                    'category'      => $data['category'] ?? null,
                    'activity_date' => $data['activity_date'] ?? null,
                    'assigned_to'   => $data['assigned_to'] ?? null,
                    
                    // Email specific
                    'to_emails'     => $data['to_emails'] ?? null,
                ],
            ];

            $log = ActivityLog::create($logData);

            // Handle Email Sending
            if ($logType === ActivityLog::TYPE_EMAIL) {
                $recipients = $data['to_emails'] ?? [];
                if (is_string($recipients)) {
                    $recipients = json_decode($recipients, true) ?? [];
                }
                $subject = $data['subject'];
                $body    = $data['description'] ?? '';

                foreach ($recipients as $recipient) {
                    Mail::html($body, function ($mail) use ($recipient, $subject) {
                        $mail->to($recipient)->subject($subject);
                    });
                }
            }

            DB::commit();

            $load = $logType === ActivityLog::TYPE_EMAIL ? ['creator'] : ['creator', 'assignedUser'];
            return $log->fresh($load);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Activity Log storage failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Find by ID.
     */
    public function find(int $id): ActivityLog
    {
        $query = ActivityLog::with(['creator', 'assignedUser', 'partner', 'borrower']);
        $user = Auth::user();

        if ($user->user_type !== 'Admin' && $user->partner_id) {
            $query->where('partner_id', $user->partner_id);
        }

        return $query->findOrFail($id);
    }

    /**
     * Log system-generated events directly.
     */
    public function logSystemEvent(string $entityType, int $entityId, ?int $partnerId, ?int $borrowerId, string $subject, string $description, string $logType = ActivityLog::TYPE_ACTIVITY): void
    {
        try {
            ActivityLog::create([
                'log_type'    => $logType,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'partner_id'  => $partnerId,
                'borrower_id' => $borrowerId,
                'created_by'  => Auth::id() ?? 1,
                'updated_by'  => Auth::id() ?? 1,
                'properties'  => [
                    'subject'     => $subject,
                    'description' => $description,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('System Activity log failed', ['error' => $e->getMessage()]);
        }
    }
}
