<?php

namespace App\Services\FCH\Common\ActivityLog;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\ActivityLog\ActivityLogResource;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Models\Appointment\Appointment;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\ActivityLog\ActivityLogMail;
use Throwable;
use App\Models\Common\AppConfiguration;

class ActivityLogService
{
    use PaginatesApi;

    /**
     * List activity logs with filtering for Partner, Lead, and Client.
     */
    public function list(array $filters)
    {
        $query = ActivityLog::with(['causer', 'subject']);

        $user = Auth::user();

        if (!$user) {
            return ApiResponse::success([], __('activity_log.listed'));
        }

        // 1. Scoping by User (Security)
        if (isset($user->partner) && (int) ($user->partner->is_host ?? 0) !== 1) {
            $query->where(function ($q) use ($user) {
                $q->where('causer_id', $user->id)
                    ->orWhere('properties->partner_id', $user->partner_id);
            });
        }

        // 2. Explicit Filtering
        if (!empty($filters['partner_id'])) {
            $pId = (int) $filters['partner_id'];
            $query->where(function ($q) use ($pId) {
                $q->where('properties->partner_id', $pId)
                    ->orWhere('properties->partner_id', (string) $pId)
                    ->orWhere(function ($sq) use ($pId) {
                        $sq->where('subject_type', Partner::class)
                            ->where('subject_id', $pId);
                    });
            });
        }

        if (!empty($filters['borrower_id'])) {
            $bId = (int) $filters['borrower_id'];
            $query->where(function ($q) use ($bId) {
                $q->where('properties->borrower_id', $bId)
                    ->orWhere('properties->borrower_id', (string) $bId)
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

        $limit = $filters['limit'] ?? 15;
        $page = $filters['page'] ?? 1;

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

            $subjectType = $data['subject_type'] ?? null;
            $subjectId = $data['subject_id'] ?? null;

            // Resolve Subject ID and Type more comprehensively
            if (!empty($data['lead_id'])) {
                $subjectType = \App\Models\Lead\Lead::class;
                $subjectId = $data['lead_id'];
            } elseif (!empty($data['borrower_id'])) {
                $subjectType = \App\Models\Borrower\Borrower::class;
                $subjectId = $data['borrower_id'];
            } elseif (!empty($data['partner_id'])) {
                $subjectType = \App\Models\Partner\Partner::class;
                $subjectId = $data['partner_id'];
            }

            // Sync targets
            $targetPartnerId = $data['partner_id'] ?? Auth::user()->partner_id ?? null;
            $targetBorrowerId = $data['borrower_id'] ?? null;

            // If logging for a lead/borrower, try to find the partner it belongs to for the timeline
            if (!$targetPartnerId) {
                if ($subjectType === \App\Models\Lead\Lead::class) {
                    $lead = \App\Models\Lead\Lead::find($subjectId);
                    if ($lead && $lead->partner_id)
                        $targetPartnerId = $lead->partner_id;
                } elseif ($subjectType === \App\Models\Borrower\Borrower::class) {
                    $borrower = \App\Models\Borrower\Borrower::find($subjectId);
                    if ($borrower && $borrower->partner_id)
                        $targetPartnerId = $borrower->partner_id;
                }
            }


            $logData = [
                'log_name' => $logType,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'partner_id' => $targetPartnerId,
                'borrower_id' => $targetBorrowerId,
                'causer_id' => Auth::id(),
                'causer_type' => Auth::check() ? get_class(Auth::user()) : null,
                'status' => $data['status'] ?? ($logType === ActivityLog::TYPE_EMAIL ? ActivityLog::STATUS_SENT : 'log'),
                'event' => $data['subject'] ?? ($logType === ActivityLog::TYPE_EMAIL ? 'Email Sent' : 'Activity Created'),
                'description' => $data['description'] ?? $data['subject'] ?? 'Activity Log',
                'properties' => [
                    'subject' => $data['subject'] ?? null,
                    'description' => $data['description'] ?? null,
                    'attachments' => [
                        'count' => $attachmentsCount,
                        'files' => $attachmentFiles,
                    ],
                    // Custom fields for Timeline icons
                    'reference' => $data['reference'] ?? null,
                    'category' => $data['category'] ?? null,
                    'activity_date' => $data['activity_date'] ?? null,
                    'assigned_to' => $data['assigned_to'] ?? null,
                    'to_emails' => $data['to_emails'] ?? null,
                    'partner_id' => $targetPartnerId,
                    'borrower_id' => $targetBorrowerId,
                ],
            ];

            $log = ActivityLog::create($logData);

            // Handle Email Sending if log_type is email
            if ($logType === ActivityLog::TYPE_EMAIL) {
                $this->sendEmail($data, $attachmentNames, $attachments);
            }

            DB::commit();

            $load = $logType === ActivityLog::TYPE_EMAIL ? ['causer'] : ['causer', 'subject'];
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
        ?string $subjectType,
        ?int $subjectId,
        ?int $partnerId,
        ?int $borrowerId,
        string $subject,
        string $description,
        string $logType = ActivityLog::LOG_TYPE_ACTIVITY,
        array $properties = []
    ): ActivityLog {
        $mergedProperties = array_merge([
            'subject' => $subject,
            'description' => $description,
            'event_label' => $subject,
        ], $properties);

        return ActivityLog::create([
            'log_name' => $logType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'partner_id' => $partnerId,
            'borrower_id' => $borrowerId,
            'causer_id' => Auth::id() ?: 1, // Fallback to system user
            'causer_type' => Auth::check() ? get_class(Auth::user()) : \App\Models\User\User::class,
            'status' => 'log',
            'event' => $subject, // Human-readable subject
            'description' => $description, // Detailed description for UI gray box
            'properties' => $mergedProperties,
        ]);
    }

    /**
     * Log an event focused on a model (Employee, User, etc.) with automatic data resolution.
     */
    public function logModelEvent(
        $model,
        string $logType,
        array $extraProperties = [],
        ?string $customSubject = null,
        ?string $customDescription = null
    ): ActivityLog {
        $subjectType = get_class($model);
        $subjectId = $model->id;
        $partnerId = $model->partner_id ?? null;
        $borrowerId = $model->borrower_id ?? null;

        // Resolve ID attribution for images and scoping
        if (!$partnerId) {
            if ($model instanceof Partner) {
                $partnerId = $model->id;
            } elseif ($model instanceof User || $model instanceof Employee) {
                $partnerId = $model->partner_id;
            } elseif ($model instanceof Payment) {
                $partnerId = $model->partner_id;
                $borrowerId = $model->borrower_id;
            }
        }

        if (!$borrowerId && $model instanceof Borrower) {
            $borrowerId = $model->id;
            $partnerId = $partnerId ?: $model->partner_id;
        }


        // Generate default descriptions if not provided
        $modelName = class_basename($model);
        if ($modelName === 'Borrower')
            $modelName = 'Client';

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
        } elseif ($logType === ActivityLog::LOG_TYPE_PARTNER_CONTACT_UPDATE) {
            $action = $actionMap['update'] ?? 'Updated';
            $descriptionAction = 'contact updated';
            $customSubject = $customSubject ?? 'Partner Contact Information Updated';
        } elseif ($logType === ActivityLog::LOG_TYPE_BORROWER_CONTACT_UPDATE) {
            $action = $actionMap['update'] ?? 'Updated';
            $descriptionAction = 'contact updated';
            $customSubject = $customSubject ?? 'Client Contact Information Updated';
        } elseif ($logType === ActivityLog::LOG_TYPE_EMAIL_UPDATE) {
            $action = 'Updated';
            $descriptionAction = 'contact email updated';
            $customSubject = $customSubject ?? str_replace(':model', $modelName, config('activity_messages.subjects.email_update', ':model Contact Information Updated'));
        } elseif ($logType === ActivityLog::LOG_TYPE_TASK_VERIFICATION) {
            $action = 'Updated';
            $descriptionAction = 'tier tasks processed';
            $customSubject = $customSubject ?? __('verification_center.tier_task_updated');
        } elseif (in_array($logType, [ActivityLog::LOG_TYPE_PARTNER_PAYMENT, ActivityLog::LOG_TYPE_BORROWER_PAYMENT])) {
            $action = 'Paid';
            $descriptionAction = 'payment successful';
            $customSubject = $customSubject ?? ($logType === ActivityLog::LOG_TYPE_PARTNER_PAYMENT ? 'Partner Subscription Payment Successful' : 'Client Subscription Payment Successful');
        } elseif ($logType === ActivityLog::LOG_TYPE_BORROWER_FREE_TRIAL) {
            $action = 'Free Trial';
            $descriptionAction = 'free trial started';
            $customSubject = $customSubject ?? config('activity_messages.subjects.borrower_free_trial', 'Client Free Trial Started');
        } elseif ($logType === ActivityLog::LOG_TYPE_PARTNER_FREE_TRIAL) {
            $action = 'Free Trial';
            $descriptionAction = 'free trial started';
            $customSubject = $customSubject ?? config('activity_messages.subjects.partner_free_trial', 'Partner Free Trial Started');
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
        } elseif ($logType === ActivityLog::LOG_TYPE_TASK_VERIFICATION) {
            $description = $customDescription ?? __('verification_center.tasks_processed_desc');
        } elseif ($logType === ActivityLog::LOG_TYPE_BORROWER_FREE_TRIAL) {
            $descPattern = config('activity_messages.descriptions.borrower_free_trial', 'Client profile free trial started for :display_name.');
            $description = $customDescription ?? str_replace(':display_name', $displayName, $descPattern);
        } elseif ($logType === ActivityLog::LOG_TYPE_PARTNER_FREE_TRIAL) {
            $descPattern = config('activity_messages.descriptions.partner_free_trial', 'Partner profile free trial started for :display_name.');
            $description = $customDescription ?? str_replace(':display_name', $displayName, $descPattern);
        } elseif ($model instanceof Employee) {
            $descPattern = config('activity_messages.descriptions.employee_default', 'Employee profile :action for :display_name.');
            $description = $customDescription ?? str_replace(
                [':action', ':display_name'],
                [$descriptionAction, $displayName],
                $descPattern
            );
        } elseif ($model instanceof User) {
            $descPattern = config('activity_messages.descriptions.user_default', 'User account :action for :display_name.');
            $description = $customDescription ?? str_replace(
                [':action', ':display_name'],
                [$descriptionAction, $displayName],
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
        if ($model instanceof Invoice) {
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
        } elseif ($model instanceof Appointment) {
            $timeStr = !empty($model->appointment_date) ? \Carbon\Carbon::parse($model->appointment_date)->format('M d, Y h:i A') : 'N/A';
            $entity = $model->entity;
            $entityName = $entity?->full_name ?? $entity?->name ?? $entity?->company_name ?? (isset($entity->first_name) ? "{$entity->first_name} {$entity->last_name}" : 'Client');

            $appointmentDescPattern = config('activity_messages.descriptions.appointment', 'Appointment (:subject) :action for :entity_name on :time.');
            $description = $customDescription ?? str_replace(
                [':subject', ':action', ':entity_name', ':time'],
                [$model->subject, $descriptionAction, $entityName, $timeStr],
                $appointmentDescPattern
            );
        } elseif ($model instanceof Payment) {
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
            $subjectType,
            $subjectId,
            $partnerId,
            $borrowerId,
            $subject,
            $description,
            $logType,
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

        $emailData = [
            'subject' => $data['subject'] ?? 'New Email from Activity Log',
            'body' => $data['description'] ?? '',
        ];

        foreach ($recipients as $recipient) {
            if (empty($recipient)) {
                continue;
            }

            try {
                Mail::to($recipient)->send(new ActivityLogMail($emailData, $attachmentNames, $attachments));
            } catch (\Throwable $e) {
                Log::error('ActivityLog: Failed to send email to recipient', [
                    'recipient' => $recipient,
                    'subject' => $emailData['subject'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Find by ID.
     */
    public function find(int $id): ActivityLog
    {
        $query = ActivityLog::with(['causer', 'subject']);
        $user = Auth::user();

        if (!$user) {
            throw new \Exception("Unauthorized access", 401);
        }

        return $query->findOrFail($id);
    }

    /**
     * Download attachment.
     * - Regular storage attachments (email logs)
     * - Partner document links (document upload logs)
     * Supports both old relative paths and new full URLs.
     */
    public function downloadAttachment(int $logId, string $path)
    {
        $log = ActivityLog::findOrFail($logId);
        $files = $this->getLogFiles($log);

        // Match by path OR by URL (extremely resilient matching)
        $file = collect($files)->first(function ($f) use ($path) {
            $fPath = $f['path'] ?? '';
            return $fPath === $path
                || (isset($f['id']) && str_contains($path, (string) $f['id']))
                || ($fPath && str_contains($fPath, $path))
                || ($fPath && str_contains($path, $fPath))
                || urldecode($fPath) === urldecode($path);
        });

        if (!$file) {
            throw new \Exception("File not found in this activity log", 404);
        }

        // Resolve Document ID (even if missing from properties)
        $documentId = $file['id'] ?? null;
        if (!$documentId && preg_match('/documents\/download\/(\d+)/', $path, $matches)) {
            $documentId = $matches[1];
        }

        if ($documentId) {
            $possiblePaths = [];
            $rawPath = '';
            $fileName = '';

            // Determine whether this is a Borrower or Partner document
            $isBorrowerDocument =
                $log->subject_type === \App\Models\Borrower\BorrowerDocument::class ||
                (isset($file['path']) && str_contains((string) $file['path'], '/borrowers/'));

            if ($isBorrowerDocument) {
                $document = \App\Models\Borrower\BorrowerDocument::withTrashed()->find($documentId);
                if (!$document) {
                    throw new \Exception("Borrower document record not found", 404);
                }

                $rawPath = $document->getRawOriginal('file_path');
                $fileName = $document->file_name ?? basename($rawPath);

                $possiblePaths = [
                    public_path('storage/borrowers/' . $document->borrower_id . '/' . $rawPath),
                    storage_path('app/public/borrowers/' . $document->borrower_id . '/' . $rawPath),
                    public_path('storage/borrowers/' . $document->borrower_id . '/documents/' . $rawPath),
                    storage_path('app/public/borrowers/' . $document->borrower_id . '/documents/' . $rawPath),
                ];
            } else {
                $document = \App\Models\Partner\PartnerDocument::withTrashed()->find($documentId);
                if (!$document) {
                    throw new \Exception("Partner document record not found", 404);
                }

                $rawPath = $document->getRawOriginal('file_path');
                $fileName = $document->document_name ?? basename($rawPath);

                $possiblePaths = [
                    public_path('storage/partners/' . $document->partner_id . '/' . $rawPath),
                    storage_path('app/public/partners/' . $document->partner_id . '/' . $rawPath),
                    public_path('storage/partners/' . $document->partner_id . '/documents/' . $rawPath),
                    storage_path('app/public/partners/' . $document->partner_id . '/documents/' . $rawPath),
                ];
            }

            foreach ($possiblePaths as $absPath) {
                // Normalize slashes for Windows/Unix compatibility
                $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absPath);
                if (file_exists($normalized)) {
                    return response()->download($normalized, $fileName);
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
