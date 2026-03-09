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
        $query = ActivityLog::with(['creator', 'assignedUser', 'partner', 'borrower', 'targetUser']);
        $user = Auth::user();

        if (!$user) {
            return ApiResponse::success([], __('activity_log.listed'));
        }

        // 1. Scoping by User (Security)
        if (isset($user->partner) && (int) ($user->partner->is_host ?? 0) !== 1) {
            // Non-admins see logs they created OR logs created FOR them (target)
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhere('created_to', $user->id);
            });
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
            $attachments = $data['attachments'] ?? [];
            if (!is_array($attachments)) {
                $attachments = [$attachments];
            }
            
            $attachmentNames = [];
            $attachmentFiles = [];
            
            foreach ($attachments as $file) {
                if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                    $originalName = $file->getClientOriginalName();
                    $fileName = time() . '_' . str_replace(' ', '_', $originalName);
                    $path = $file->storeAs('attachments', $fileName, 'public');
                    
                    $attachmentNames[] = $originalName;
                    $attachmentFiles[] = [
                        'name' => $originalName,
                        'path' => 'storage/' . $path
                    ];
                }
            }
            $attachmentsCount = count($attachmentFiles);

            $createdTo = $data['created_to'] ?? null;
            $targetPartnerId = null;

            if (($data['entity_type'] ?? null) === \App\Models\Partner\Partner::class && !empty($data['entity_id'])) {
                $targetPartnerId = $data['entity_id'];
            } elseif (!empty($data['partner_id'])) {
                $targetPartnerId = $data['partner_id'];
            }

            if (!$createdTo && $targetPartnerId) {
                $partner = \App\Models\Partner\Partner::find($targetPartnerId);
                if ($partner && $partner->partnerUser) {
                    $createdTo = $partner->partnerUser->id;
                }
            }

            // Strictly enforce null created_to for Borrower and Lead events as requested
            if (
                ($data['entity_type'] ?? null) === \App\Models\Borrower\Borrower::class ||
                ($data['entity_type'] ?? null) === \App\Models\Lead\Lead::class
            ) {
                $createdTo = null;
            }

            $logData = [
                'log_type'    => $logType,
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id'   => $data['entity_id'] ?? null,
                'partner_id'  => $data['partner_id'] ?? Auth::user()->partner_id,
                'borrower_id' => $data['borrower_id'] ?? null,
                'created_by'  => Auth::id(),
                'created_to'  => $createdTo,
                'updated_by'  => Auth::id(),
                'status'      => $data['status'] ?? ($logType === ActivityLog::TYPE_EMAIL ? ActivityLog::STATUS_SENT : 'sent'),
                'properties'  => [
                    'subject'       => $data['subject'] ?? null,
                    'description'   => $data['description'] ?? null,
                    'attachments'   => [
                        'count' => $attachmentsCount,
                        'files' => $attachmentFiles,
                    ],
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
                    $decoded = json_decode($recipients, true);
                    $recipients = is_array($decoded) ? $decoded : array_map('trim', explode(',', $recipients));
                }
                
                $subject = $data['subject'];
                $body    = $data['description'] ?? '';

                foreach ($recipients as $recipient) {
                    if (empty($recipient)) continue;

                    Mail::send('emails.activity_log_mail', [
                        'subject'           => $subject,
                        'body'              => $body,
                        'attachments_count' => $attachmentsCount,
                        'attachment_names'  => $attachmentNames,
                    ], function ($message) use ($recipient, $subject, $attachments) {
                        $message->to($recipient)->subject($subject);
                        
                        foreach ($attachments as $file) {
                            if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                                $message->attach($file->getRealPath(), [
                                    'as'   => $file->getClientOriginalName(),
                                    'mime' => $file->getMimeType(),
                                ]);
                            }
                        }
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
        $query = ActivityLog::with(['creator', 'assignedUser', 'partner', 'borrower', 'targetUser']);
        $user = Auth::user();

        if (!$user) {
            throw new \Exception("Unauthorized access", 401);
        }

        // 1. Scoping by User (Security)
        if (isset($user->partner) && (int) ($user->partner->is_host ?? 0) !== 1) {
            // Non-admins see logs they created OR logs created FOR them (target)
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                  ->orWhere('created_to', $user->id);
            });
        }

        return $query->findOrFail($id);
    }

    /**
     * Log system-generated events directly.
     */
    public function logSystemEvent(string $entityType, int $entityId, ?int $partnerId, ?int $borrowerId, string $subject, string $description, string $logType = ActivityLog::TYPE_ACTIVITY, ?int $createdTo = null): void
    {
        try {
            // Resolution Logic: If it's a Partner event, resolve the Partner's main User ID for created_to
            $finalPartnerId = $partnerId;
            $finalCreatedTo = $createdTo;

            if (!$finalCreatedTo && $finalPartnerId) {
                $partner = \App\Models\Partner\Partner::find($finalPartnerId);
                if ($partner && $partner->partnerUser) {
                    $finalCreatedTo = $partner->partnerUser->id;
                }
            }

            // Strictly enforce null for Lead and Borrower create/update events as requested
            if (in_array($logType, [
                ActivityLog::LOG_TYPE_LEAD_CREATE, 
                ActivityLog::LOG_TYPE_LEAD_UPDATE, 
                ActivityLog::LOG_TYPE_BORROWER_CREATE, 
                ActivityLog::LOG_TYPE_BORROWER_UPDATE,
                ActivityLog::LOG_TYPE_INVOICE_CREATE // borrower payment context
            ])) {
                $finalPartnerId = null; 
                $finalCreatedTo  = null;
            }

            ActivityLog::create([
                'log_type'    => $logType,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'partner_id'  => $finalPartnerId,
                'borrower_id' => $borrowerId,
                'created_by'  => Auth::id() ?? 1,
                'created_to'  => $finalCreatedTo,
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

    /**
     * Download activity log attachment.
     * Verifies that the path belongs to the specific log ID.
     */
    public function downloadAttachment(int $logId, string $requestedPath)
    {
        $log = ActivityLog::findOrFail($logId);
        
        // Extract files from properties (Handle both new 'files' and legacy 'names' structures)
        $attachments = $log->properties['attachments'] ?? [];
        $files = $attachments['files'] ?? ($attachments['names'] ?? []);
        
        // Security check: Find the matching file record by either path or filename
        $fileRecord = collect($files)->first(function ($file) use ($requestedPath) {
            if (is_array($file)) {
                return ($file['path'] ?? null) === $requestedPath || ($file['name'] ?? null) === $requestedPath;
            }
            // Fallback for legacy string-only data
            return $file === $requestedPath;
        });
        
        if (!$fileRecord) {
            throw new \Exception("Unauthorized: File does not belong to this activity log.", 403);
        }

        $actualPath = is_array($fileRecord) ? $fileRecord['path'] : $fileRecord;

        // Convert storage/ prefix to work with public disk
        $relativePath = str_replace('storage/', '', $actualPath);
        
        if (!\Illuminate\Support\Facades\Storage::disk('public')->exists($relativePath)) {
            throw new \Exception("File not found on server", 404);
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->download($relativePath);
    }
}
