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
        $query = ActivityLog::with(['creator', 'assignedUser']);

        // Explicit entity filters take priority
        if (!empty($filters['borrower_id'])) {
            $query->where('borrower_id', $filters['borrower_id']);
        } elseif (!empty($filters['partner_id'])) {
            $query->where('partner_id', $filters['partner_id']);
        } else {
            // No explicit entity - scope to authenticated user's partner
            if (Auth::user()->user_type !== 'Admin' && Auth::user()->partner_id) {
                $query->where('partner_id', Auth::user()->partner_id);
            }
        }

        if (!empty($filters['log_name'])) {
            $query->where('log_name', $filters['log_name']);
        }

        $logs = $query->orderBy('created_at', 'desc')->limit(15)->get();

        return ApiResponse::success(
            ActivityLogResource::collection($logs),
            __('activity_log.listed')
        );
    }

    /**
     * Create Activity log entry.
     */
    public function createActivity(array $data): ActivityLog
    {
        DB::beginTransaction();
        try {
            $data['log_name']   = ActivityLog::TYPE_ACTIVITY;
            $data['partner_id'] = $data['partner_id'] ?? Auth::user()->partner_id;
            $data['created_by'] = Auth::id();
            $data['updated_by'] = Auth::id();

            $log = ActivityLog::create($data);
            DB::commit();

            return $log->fresh(['creator', 'assignedUser']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Activity creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Create Email log entry and send the email.
     */
    public function createEmail(array $data): ActivityLog
    {
        DB::beginTransaction();
        try {
            $data['log_name']    = ActivityLog::TYPE_EMAIL;
            $data['partner_id']  = $data['partner_id'] ?? Auth::user()->partner_id;
            $data['created_by']  = Auth::id();
            $data['updated_by']  = Auth::id();
            $data['status']      = ActivityLog::STATUS_SENT;

            $log = ActivityLog::create($data);

            // Send actual email to each recipient
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

            DB::commit();

            return $log->fresh(['creator']);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Email log creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Find by ID.
     */
    public function find(int $id): ActivityLog
    {
        return ActivityLog::with(['creator', 'assignedUser', 'partner', 'borrower'])
            ->findOrFail($id);
    }
}
