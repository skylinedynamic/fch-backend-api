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
        $query = ActivityLog::with(['creator', 'assignedUser'])
            ->where('partner_id', Auth::user()->partner->id);
        // Searc
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
            $data['type']       = ActivityLog::TYPE_ACTIVITY;
            $data['partner_id'] = Auth::user()->partner_id;
            $data['created_by'] = Auth::id();
            $data['updated_by'] = Auth::id();
            $data['description'] = $data['summary'] ?? 'Activity';

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
            $data['type']        = ActivityLog::TYPE_EMAIL;
            $data['partner_id']  = Auth::user()->partner_id;
            $data['created_by']  = Auth::id();
            $data['updated_by']  = Auth::id();
            $data['status']      = 'sent';
            $data['description'] = $data['subject'] ?? 'Email';

            $log = ActivityLog::create($data);

            // Send actual email to each recipient
            $recipients = $data['to_emails'] ?? [];
            $subject    = $data['subject'];
            $body       = $data['message_body'];

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
        return ActivityLog::with(['creator', 'assignedUser', 'lead', 'partner', 'borrower'])
            ->findOrFail($id);
    }
}
