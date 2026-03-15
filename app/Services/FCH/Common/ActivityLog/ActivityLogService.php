<?php

namespace App\Services\FCH\Common\ActivityLog;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\ActivityLog\ActivityLogResource;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
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
     * List activity logs with filtering for Partner, Lead, and Client.
     */
    public function list(array $filters)
    {
        Log::info('ActivityLogService@list filters:', $filters);

        $query = ActivityLog::with(['causer', 'assignedUser', 'partner', 'borrower', 'targetUser']);
        
        $user = Auth::user();

        if (!$user) {
            return ApiResponse::success([], __('activity_log.listed'));
        }

        // 1. Scoping by User (Security)
        if (isset($user->partner) && (int) ($user->partner->is_host ?? 0) !== 1) {
            $query->where(function ($q) use ($user) {
                $q->where('causer_id', $user->id)
                    ->orWhere('created_to', $user->id)
                    ->orWhere('partner_id', $user->partner_id);
            });
        }

        // 2. Explicit Filtering
        if (!empty($filters['partner_id'])) {
            $pId = (int)$filters['partner_id'];
            $query->where(function ($q) use ($pId) {
                $q->where('partner_id', $pId)
                    ->orWhere(function ($sq) use ($pId) {
                        $sq->where('subject_type', Partner::class)
                            ->where('subject_id', $pId);
                    });
            });
        }

        if (!empty($filters['borrower_id'])) {
            $bId = (int)$filters['borrower_id'];
            $query->where(function ($q) use ($bId) {
                $q->where('borrower_id', $bId)
                    ->orWhere(function ($sq) use ($bId) {
                        $sq->where('subject_type', Borrower::class)
                            ->where('subject_id', $bId);
                    });
            });
        }

        if (!empty($filters['lead_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('subject_type', Lead::class)
                    ->where('subject_id', $filters['lead_id']);
            });
        }

        // Debug Final SQL
        Log::info('ActivityLog Final SQL:', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
            'filters' => $filters
        ]);

        $limit = $filters['limit'] ?? 15;
        $page  = $filters['page'] ?? 1;

        $logs = $query->orderBy('id', 'desc')->paginate($limit, ['*'], 'page', $page);

        return $this->respondWithPaginationResource(
            $logs, 
            ActivityLogResource::class, 
            __('activity_log.listed')
        );
    }

    /**
     * Store a new custom Activity Log (Manual Activity, Email, Call).
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
            $targetPartnerId = $data['partner_id'] ?? Auth::user()->partner_id ?? null;
            $targetBorrowerId = $data['borrower_id'] ?? null;
            $entityType = $data['entity_type'] ?? null;
            $entityId = $data['entity_id'] ?? null;

            // Resolve Lead ID if provided explicitly
            if (!empty($data['lead_id'])) {
                $entityType = \App\Models\Lead\Lead::class;
                $entityId = $data['lead_id'];
                
                // If logging for a lead, try to find the partner it belongs to for the timeline
                if (!$targetPartnerId) {
                    $lead = \App\Models\Lead\Lead::find($entityId);
                    if ($lead && $lead->partner_id) {
                        $targetPartnerId = $lead->partner_id;
                    }
                }
            }

            // Resolve Partner User for created_to if needed
            if (!$createdTo && $targetPartnerId) {
                $partner = \App\Models\Partner\Partner::find($targetPartnerId);
                if ($partner && $partner->partnerUser) {
                    $createdTo = $partner->partnerUser->id;
                }
            }

            // Enforce null created_to for certain modules if required by business logic
            if ($entityType === \App\Models\Borrower\Borrower::class || $entityType === \App\Models\Lead\Lead::class) {
                $createdTo = null;
            }

            $logData = [
                'log_type'     => $logType,
                'subject_type' => $entityType,
                'subject_id'   => $entityId,
                'partner_id'   => $targetPartnerId,
                'borrower_id'  => $targetBorrowerId,
                'causer_id'    => Auth::id(),
                'causer_type'  => Auth::check() ? get_class(Auth::user()) : null,
                'created_to'   => $createdTo,
                'status'       => $data['status'] ?? ($logType === ActivityLog::TYPE_EMAIL ? ActivityLog::STATUS_SENT : 'log'),
                'description'  => $data['description'] ?? $data['subject'] ?? 'Activity Log',
                'properties'   => [
                    'subject'       => $data['subject'] ?? null,
                    'description'   => $data['description'] ?? null,
                    'attachments'   => [
                        'count' => $attachmentsCount,
                        'files' => $attachmentFiles,
                    ],
                    // Custom fields for Timeline icons
                    'reference'     => $data['reference'] ?? null,
                    'category'      => $data['category'] ?? null,
                    'activity_date' => $data['activity_date'] ?? null,
                    'assigned_to'   => $data['assigned_to'] ?? null,
                    'to_emails'     => $data['to_emails'] ?? null,
                ],
            ];

            $log = ActivityLog::create($logData);

            // Handle Email Sending if log_type is email
            if ($logType === ActivityLog::TYPE_EMAIL) {
                $this->sendEmail($data, $attachmentNames, $attachments);
            }

            DB::commit();

            $load = $logType === ActivityLog::TYPE_EMAIL ? ['causer'] : ['causer', 'assignedUser', 'partner', 'borrower', 'targetUser'];
            return $log->fresh($load);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Activity Log storage failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Log a system-triggered event (e.g., Partner Update, Lead Status Change).
     */
    public function logSystemEvent(
        ?string $entityType,
        ?int $entityId,
        ?int $partnerId,
        ?int $borrowerId,
        string $subject,
        string $description,
        string $logType = ActivityLog::LOG_TYPE_ACTIVITY,
        ?int $createdTo = null,
        array $properties = []
    ): ActivityLog {
        $mergedProperties = array_merge([
            'subject'     => $subject,
            'description' => $description,
            'event_label' => $subject,
        ], $properties);

        return ActivityLog::create([
            'log_type'     => $logType,
            'subject_type' => $entityType,
            'subject_id'   => $entityId,
            'partner_id'   => $partnerId,
            'borrower_id'  => $borrowerId,
            'causer_id'    => Auth::id() ?: 1, // Fallback to system user
            'causer_type'  => Auth::check() ? get_class(Auth::user()) : \App\Models\User\User::class,
            'created_to'   => $createdTo,
            'status'       => 'log',
            'event'        => $subject, // Human-readable subject
            'description'  => $description, // Detailed description for UI gray box
            'properties'   => $mergedProperties,
        ]);
    }

    /**
     * Log an event focused on a model (Employee, User, etc.) with automatic data resolution.
     */
    public function logModelEvent(
        $model,
        string $logType,
        ?int $createdTo = null,
        array $extraProperties = [],
        ?string $customSubject = null,
        ?string $customDescription = null
    ): ActivityLog {
        $entityType = get_class($model);
        $entityId   = $model->id;
        $partnerId  = $model->partner_id ?? null;
        $borrowerId = $model->borrower_id ?? null;

        // Resolve Partner ID for User if missing
        if (!$partnerId && $model instanceof \App\Models\User\User) {
            $partnerId = $model->partner_id;
        }

        // Handle User specifics for created_to
        if ($model instanceof \App\Models\User\User) {
            $createdTo = $createdTo ?? $model->id;
        }

        // Generate default descriptions if not provided
        $modelName = class_basename($model);
        if ($modelName === 'Borrower') $modelName = 'Client';
        
        // Fetch from config
        $actionMap = config('activity_messages.actions', []);
        
        // Infer action from logType (e.g., employee_create -> Created)
        $actionParts = explode('_', $logType);
        $actionRaw = end($actionParts);

        // Determine Action and Subject
        if ($logType === ActivityLog::LOG_TYPE_BATCH_PROCESS) {
             $action = $actionMap['batch'] ?? 'Created (Batch)';
             $descriptionAction = 'generated via batch process';
             $customSubject = $customSubject ?? str_replace(':model', $modelName, config('activity_messages.subjects.batch_process'));
        } elseif ($logType === ActivityLog::LOG_TYPE_PERMISSION_UPDATE) {
             $action = $actionMap['update'] ?? 'Updated';
             $descriptionAction = $actionMap['permissions'] ?? 'permissions updated';
             $customSubject = $customSubject ?? config('activity_messages.subjects.permission_update');
        } elseif ($logType === ActivityLog::LOG_TYPE_CHECKLIST_UPDATE) {
             $action = $actionMap['update'] ?? 'Updated';
             $descriptionAction = $actionMap['checklist'] ?? 'checklist updated';
             $customSubject = $customSubject ?? config('activity_messages.subjects.checklist_update');
        } elseif ($logType === ActivityLog::LOG_TYPE_LEAD_DETAILS_UPDATE) {
             $action = $actionMap['update'] ?? 'Updated';
             $descriptionAction = 'details updated';
             $customSubject = $customSubject ?? config('activity_messages.subjects.lead_details_update');
        } elseif ($logType === ActivityLog::LOG_TYPE_LEAD_CONTACT_UPDATE) {
             $action = $actionMap['update'] ?? 'Updated';
             $descriptionAction = 'contact updated';
             $customSubject = $customSubject ?? config('activity_messages.subjects.lead_contact_update');
        } elseif ($logType === ActivityLog::LOG_TYPE_EMAIL_UPDATE) {
             $action = 'Updated';
             $descriptionAction = 'contact email updated';
             $customSubject = $customSubject ?? str_replace(':model', $modelName, config('activity_messages.subjects.email_update', ':model Contact Information Updated'));
        } else {
             $action = $actionMap[$actionRaw] ?? trim(ucwords($actionRaw));
             $descriptionAction = strtolower($action);
        }

        $subjectPattern = config('activity_messages.subjects.default', ':model :action');
        $subject = $customSubject ?? str_replace([':model', ':action'], [$modelName, $action], $subjectPattern);
        
        $displayName = $model->name 
            ?? $model->full_name 
            ?? $model->company_name 
            ?? $model->contact_person
            ?? $model->subject
            ?? (isset($model->first_name) ? "{$model->first_name} {$model->last_name}" : ($model->customer_name ?? 'Record'));
        
        // Resolve Final Description logic
        $descDefaultPattern = config('activity_messages.descriptions.default', ':model profile :action for :display_name');
        if ($logType === ActivityLog::LOG_TYPE_PERMISSION_UPDATE) {
            $descPattern = config('activity_messages.descriptions.permission_update', 'New permissions assigned to role: :display_name');
            $description = $customDescription ?? str_replace(':display_name', $displayName, $descPattern);
        } elseif ($logType === ActivityLog::LOG_TYPE_CHECKLIST_UPDATE) {
            $description = $customDescription ?? config('activity_messages.descriptions.checklist_update', 'The borrower document checklist was updated with new requirements.');
        } elseif ($logType === ActivityLog::LOG_TYPE_LEAD_DETAILS_UPDATE) {
            $descPattern = config('activity_messages.descriptions.lead_details_update', 'Lead profile details were updated for :display_name.');
            $description = $customDescription ?? str_replace(':display_name', $displayName, $descPattern);
        } elseif ($logType === ActivityLog::LOG_TYPE_LEAD_CONTACT_UPDATE) {
            $descPattern = config('activity_messages.descriptions.lead_contact_update', 'Lead contact information was updated for :display_name.');
            $description = $customDescription ?? str_replace(':display_name', $displayName, $descPattern);
        } elseif ($logType === ActivityLog::LOG_TYPE_EMAIL_UPDATE) {
            $descPattern = config('activity_messages.descriptions.email_update', ':model contact email was updated to :new_email for :display_name.');
            $newEmail = $extraProperties['new_email'] ?? 'N/A';
            $description = $customDescription ?? str_replace(
                [':model', ':new_email', ':display_name'], 
                [$modelName, $newEmail, $displayName], 
                $descPattern
            );
        } else {
            $description = $customDescription ?? str_replace(
                [':model', ':action', ':display_name'], 
                [$modelName, $descriptionAction, $displayName], 
                $descDefaultPattern
            );
        }

        // Specialized descriptions per Model Type (overrides default if applicable)
        if ($model instanceof \App\Models\Invoice\Invoice) {
            if ($logType === ActivityLog::LOG_TYPE_BATCH_PROCESS) {
                $batchDescPattern = config('activity_messages.descriptions.batch_invoice', 'System generated a renewal invoice (:invoice_id) for user :display_name via batch process.');
                $description = $customDescription ?? str_replace(
                    [':invoice_id', ':display_name'], 
                    [$model->invoice_id, $displayName], 
                    $batchDescPattern
                );
            } else {
                 $invoiceDescPattern = config('activity_messages.descriptions.invoice', ':model (:invoice_id) :action for :display_name');
                 $description = $customDescription ?? str_replace(
                    [':model', ':invoice_id', ':action', ':display_name'], 
                    [$modelName, $model->invoice_id, $descriptionAction, $displayName], 
                    $invoiceDescPattern
                );
            }
        } elseif ($model instanceof \App\Models\Appointment\Appointment) {
            $timeStr = !empty($model->appointment_date) ? \Carbon\Carbon::parse($model->appointment_date)->format('M d, Y h:i A') : 'N/A';
            $entity = $model->entity;
            $entityName = $entity?->full_name ?? $entity?->name ?? $entity?->company_name ?? (isset($entity->first_name) ? "{$entity->first_name} {$entity->last_name}" : 'Client');
            
            $appointmentDescPattern = config('activity_messages.descriptions.appointment', 'Appointment (:subject) :action for :entity_name on :time.');
            $description = $customDescription ?? str_replace(
                [':subject', ':action', ':entity_name', ':time'], 
                [$model->subject, $descriptionAction, $entityName, $timeStr], 
                $appointmentDescPattern
            );
        } elseif ($model instanceof \App\Models\Payment\Payment) {
            $currency = $model->currency ?? 'USD';
            $paymentDescPattern = config('activity_messages.descriptions.payment', ':model of :amount :currency :action successfully.');
            $description = $customDescription ?? str_replace(
                [':model', ':amount', ':currency', ':action'], 
                [$modelName, $model->amount, $currency, $descriptionAction], 
                $paymentDescPattern
            );
        }

        // Default properties
        $properties = array_merge([
            'model_status' => $model->job_status ?? $model->status ?? null,
        ], $extraProperties);

        return $this->logSystemEvent(
            $entityType,
            $entityId,
            $partnerId,
            $borrowerId,
            $subject,
            $description,
            $logType,
            $createdTo,
            $properties
        );
    }

    private function sendEmail(array $data, array $attachmentNames, array $attachments): void
    {
        $recipients = $data['to_emails'] ?? [];
        if (is_string($recipients)) {
            $decoded = json_decode($recipients, true);
            $recipients = is_array($decoded) ? $decoded : array_map('trim', explode(',', $recipients));
        }
        
        $subject = $data['subject'] ?? 'New Email from Activity Log';
        $body    = $data['description'] ?? '';

        foreach ($recipients as $recipient) {
            if (empty($recipient)) continue;

            Mail::send('emails.activity_log_mail', [
                'subject'           => $subject,
                'body'              => $body,
                'attachments_count' => count($attachments),
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

    /**
     * Find by ID.
     */
    public function find(int $id): ActivityLog
    {
        $query = ActivityLog::with(['causer', 'assignedUser', 'partner', 'borrower', 'targetUser']);
        $user = Auth::user();

        if (!$user) {
            throw new \Exception("Unauthorized access", 401);
        }

        return $query->findOrFail($id);
    }

    /**
     * Download attachment.
     * Accurate Code: Robustly handles:
     * - Regular storage attachments (email logs)
     * - Partner document links (document upload logs)
     * Supports both old relative paths and new full URLs.
     */
    public function downloadAttachment(int $logId, string $path)
    {
        $log = ActivityLog::findOrFail($logId);
        $files = $this->getLogFiles($log);

        // Accurate Code: match by path OR by URL (extremely resilient matching)
        $file = collect($files)->first(function ($f) use ($path) {
            $fPath = $f['path'] ?? '';
            return $fPath === $path 
                || (isset($f['id']) && str_contains($path, (string)$f['id']))
                || ($fPath && str_contains($fPath, $path))
                || ($fPath && str_contains($path, $fPath))
                || urldecode($fPath) === urldecode($path);
        });

        if (!$file) {
            throw new \Exception("File not found in this activity log", 404);
        }

        // Accurate Code: Resolve Partner Document ID (even if missing from properties)
        $partnerDocumentId = $file['id'] ?? null;
        if (!$partnerDocumentId && preg_match('/documents\/download\/(\d+)/', $path, $matches)) {
            $partnerDocumentId = $matches[1];
        }

        if ($partnerDocumentId) {
            $document = \App\Models\Partner\PartnerDocument::withTrashed()->find($partnerDocumentId);
            if (!$document) {
                throw new \Exception("Partner document record not found", 404);
            }

            // Get the raw path from DB
            $rawPath = $document->getRawOriginal('file_path');
            
            // Accurate Code: Check multiple possible locations for maximum robustness
            $possiblePaths = [
                public_path('storage/partners/' . $document->partner_id . '/' . $rawPath),
                storage_path('app/public/partners/' . $document->partner_id . '/' . $rawPath),
                public_path('storage/partners/' . $document->partner_id . '/documents/' . $rawPath),
                storage_path('app/public/partners/' . $document->partner_id . '/documents/' . $rawPath),
            ];

            foreach ($possiblePaths as $absPath) {
                // Normalize slashes for Windows/Unix compatibility
                $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absPath);
                if (file_exists($normalized)) {
                    return response()->download($normalized, $document->document_name);
                }
            }

            throw new \Exception("File '{$rawPath}' does not exist on server at expected locations", 404);
        }

        // Fallback: standard storage attachment (email logs)
        $absolutePath = public_path($file['path']);
        if (!file_exists($absolutePath)) {
             // check storage/app/public too if it's a storage link
             $cleanPath = ltrim(str_replace('storage/', '', $file['path']), '/');
             $storagePath = storage_path('app/public/' . $cleanPath);
             if (file_exists($storagePath)) {
                 return response()->download($storagePath);
             }
            throw new \Exception("Attachment file does not exist on server", 404);
        }

        return response()->download($absolutePath);
    }

    private function getLogFiles(ActivityLog $log): array
    {
        return $log->properties['attachments']['files'] ?? [];
    }
}
