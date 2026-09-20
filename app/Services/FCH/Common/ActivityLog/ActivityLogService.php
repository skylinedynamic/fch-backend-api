<?php

namespace App\Services\FCH\Common\ActivityLog;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\ActivityLog\ActivityLogResource;
use App\Mail\ActivityLog\ActivityLogMail;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Appointment\Appointment;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplication;
use App\Models\Common\AppConfiguration;
use App\Models\Common\Reminder;
use App\Models\Employee\Employee;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Invoice\Invoice;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerDocument;
use App\Models\Payment\Payment;
use App\Models\User\User;
use App\Services\FCH\Common\Reminder\ReminderService;
use App\Traits\PaginatesApi;
use Illuminate\Support\Carbon;
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
        $query = ActivityLog::with(['causer', 'subject']);

        $user = Auth::user();

        if (!$user) {
            return ApiResponse::success([], __('activity_log.listed'));
        }

        // 1. Scoping by User (Security)
        $isHost = $user->user_type === User::ADMIN || $user->user_type === User::EMPLOYEE || (isset($user->partner) && (int) ($user->partner->is_host ?? 0) === 1);

    
        if ($isHost) {
            // Host Admin sees everything, no scope applied
        } elseif (isset($user->partner)) {
            // Partner. partner_id says whose timeline a row belongs to, not who
            // may read it — on its own it would hand the partner the host's
            // private notes and the host's emails to the borrower. Beyond their
            // own entries they see only what was explicitly addressed to them.
            $query->where(function ($q) use ($user) {
                $q->where('causer_id', $user->id)
                    ->orWhere(function ($subQ) use ($user) {
                        $subQ->where('properties->partner_id', $user->partner_id)
                             ->where(function ($q2) {
                                 $q2->whereNull('properties->is_internal')
                                    ->orWhere('properties->is_internal', false)
                                    ->orWhere('properties->is_internal', '0');
                             })
                             ->where(function ($q3) {
                                 $q3->where('properties->send_to_host', '1')
                                    ->orWhere('properties->visible_to_host', '1');
                             });
                    });
            });
        } elseif (isset($user->borrower)) {
            // Borrower
            $query->where(function ($q) use ($user) {
                // Borrower sees activities they caused
                $q->where('causer_id', $user->id)
                  // Or activities explicitly sent/visible to borrower
                  ->orWhere('properties->send_to_borrower', '1')
                  ->orWhere('properties->visible_to_borrower', '1')
                  // Or system logs (hide manual custom logs meant only for host/partner)
                  ->orWhere(function ($subQ) {
                      $subQ->whereNotIn('log_name', ['email', 'activity', 'log'])
                           ->where(function ($q2) {
                               $q2->whereNull('properties->is_internal')
                                  ->orWhere('properties->is_internal', false)
                                  ->orWhere('properties->is_internal', '0');
                           });
                  });
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

        if (!empty($filters['funding_request_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('subject_type', FundingRequest::class)
                    ->where('subject_id', $filters['funding_request_id']);
            });
        }

        if (!empty($filters['funding_application_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('subject_type', FundingApplication::class)
                    ->where('subject_id', $filters['funding_application_id']);
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
                $subjectType = Lead::class;
                $subjectId = $data['lead_id'];
            } elseif (!empty($data['borrower_id'])) {
                $subjectType = Borrower::class;
                $subjectId = $data['borrower_id'];
            } elseif (!empty($data['partner_id'])) {
                $subjectType = Partner::class;
                $subjectId = $data['partner_id'];
            } elseif (!empty($data['funding_request_id'])) {
                $subjectType = FundingRequest::class;
                $subjectId = $data['funding_request_id'];
            } elseif (!empty($data['funding_application_id'])) {
                $subjectType = FundingApplication::class;
                $subjectId = $data['funding_application_id'];
            }

            // Sync targets
            $targetPartnerId = $data['partner_id'] ?? null;
            $targetBorrowerId = $data['borrower_id'] ?? null;

            // partner_id is the timeline this entry belongs to — the partner who
            // owns the record — not whoever happens to be writing it. Reading it
            // off the caller first meant a host acting on a partner's request
            // stamped its own partner id, so the owning partner never saw the
            // entry (its own emails, status changes and notifications included).
            if (!$targetPartnerId) {
                if ($subjectType === Lead::class) {
                    $lead = Lead::find($subjectId);
                    if ($lead && $lead->partner_id)
                        $targetPartnerId = $lead->partner_id;
                } elseif ($subjectType === Borrower::class) {
                    $borrower = Borrower::find($subjectId);
                    if ($borrower && $borrower->partner_id)
                        $targetPartnerId = $borrower->partner_id;
                } elseif ($subjectType === FundingRequest::class) {
                    $fundingRequest = FundingRequest::find($subjectId);
                    if ($fundingRequest) {
                        if ($fundingRequest->partner_id) $targetPartnerId = $fundingRequest->partner_id;
                        if ($fundingRequest->borrower_id && !$targetBorrowerId) $targetBorrowerId = $fundingRequest->borrower_id;
                    }
                } elseif ($subjectType === FundingApplication::class) {
                    $app = FundingApplication::find($subjectId);
                    if ($app) {
                        if ($app->partner_id) $targetPartnerId = $app->partner_id;
                        if ($app->borrower_id && !$targetBorrowerId) $targetBorrowerId = $app->borrower_id;
                    }
                }
            }

            // Only when the subject has no partner of its own does the caller's
            // partner apply (e.g. a host-only record with nobody else attached).
            $targetPartnerId = $targetPartnerId ?? Auth::user()?->partner_id;


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
                    'location' => $data['location'] ?? null,
                    'duration' => $data['duration'] ?? null,
                    'activity_date' => $this->localToUtc($data['activity_date'] ?? null),
                    'assigned_to' => $data['assigned_to'] ?? null,
                    'to_emails' => $data['to_emails'] ?? null,
                    'partner_id' => $targetPartnerId,
                    'borrower_id' => $targetBorrowerId,
                    'send_to_host' => $data['send_to_host'] ?? null,
                    'send_to_borrower' => $data['send_to_borrower'] ?? null,
                    'visible_to_host' => $data['visible_to_host'] ?? null,
                    'visible_to_borrower' => $data['visible_to_borrower'] ?? null,
                    'remind_me' => filter_var($data['remind_me'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'remind_at' => filter_var($data['remind_me'] ?? false, FILTER_VALIDATE_BOOLEAN)
                        ? $this->localToUtc($data['remind_at'] ?? null)
                        : null,
                    'reminder_preset' => $data['reminder_preset'] ?? '30_minutes',
                    'reminder_time_value' => $data['reminder_time_value'] ?? '30',
                    'reminder_time_unit' => $data['reminder_time_unit'] ?? 'Minutes',
                    // activity_date / remind_at above are UTC; rows without this flag hold local wall-clock values
                    'dates_utc' => true,
                ],
            ];

            $log = ActivityLog::create($logData);

            // Handle Reminders
            $reminder = app(ReminderService::class)->createFromActivity(
                $data,
                $subjectId,
                $subjectType,
                $targetBorrowerId,
                $targetPartnerId,
                $attachmentFiles
            );

            if ($reminder) {
                $reminder->update(['activity_log_id' => $log->id]);

                $props = $log->properties;
                if ($props instanceof \Illuminate\Support\Collection) {
                    $props = $props->toArray();
                }
                $props['reminder_id'] = $reminder->id;
                $log->properties = $props;
                $log->save();
            }

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
        string $logType = 'activity',
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
            'causer_type' => Auth::check() ? get_class(Auth::user()) : User::class,
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
            $descriptionAction = 'limited access edition started';
            $customSubject = $customSubject ?? config('activity_messages.subjects.borrower_free_trial', 'Client Limited Access Edition Started');
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
            $descPattern = config('activity_messages.descriptions.borrower_free_trial', 'Client profile limited access edition started for :display_name.');
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
     * Update a manually-created activity log (log_name = 'activity' only).
     */
    public function update(int $id, array $data): ActivityLog
    {
        return DB::transaction(fn () => $this->performUpdate($id, $data));
    }

    private function performUpdate(int $id, array $data): ActivityLog
    {
        $log = ActivityLog::lockForUpdate()->findOrFail($id);

        $logType = strtolower($log->log_name ?? '');
        if ($logType !== 'activity' && $logType !== ActivityLog::TYPE_ACTIVITY) {
            throw new \Exception('Only manual activity entries can be edited.', 403);
        }

        // You may only change what you logged yourself — another user's entry
        // stays read-only, however it is reached.
        if (!$log->isOwnedBy(Auth::user())) {
            throw new \Exception('You can only edit activities you created.', 403);
        }

        $props = $log->properties;
        if ($props instanceof \Illuminate\Support\Collection) {
            $props = $props->toArray();
        } elseif (!is_array($props)) {
            $props = [];
        }

        // Resolve before the subject is overwritten — the legacy fallback matches on it
        $existingReminder = $this->findLinkedReminder($log, $props);

        if (isset($data['subject'])) {
            $props['subject'] = $data['subject'];
            $log->event = $data['subject'];
        }

        if (isset($data['description'])) {
            $props['description'] = $data['description'];
            $log->description = $data['description'];
        }

        if (isset($data['reference'])) {
            $props['reference'] = $data['reference'];
        }

        // Older rows stored the wall-clock value as entered — normalise them to UTC once
        if (empty($props['dates_utc'])) {
            $props['activity_date'] = $this->localToUtc($props['activity_date'] ?? null);
            $props['remind_at'] = $this->localToUtc($props['remind_at'] ?? null);
            $props['dates_utc'] = true;
        }

        if (!empty($data['activity_date'])) {
            $props['activity_date'] = $this->localToUtc($data['activity_date']);
        }

        // --- Attachments Handling ---
        $hasAttachmentChanges = false;
        $retainedFiles = [];

        if (array_key_exists('existing_attachments', $data)) {
            $hasAttachmentChanges = true;
            $existing = $data['existing_attachments'];
            if (is_string($existing)) {
                $existing = json_decode($existing, true) ?: [];
            }
            if (is_array($existing)) {
                $retainedFiles = $existing;
            }
        } else {
            $retainedFiles = $props['attachments']['files'] ?? [];
        }

        $newAttachmentFiles = [];
        if (isset($data['attachments']) && is_array($data['attachments'])) {
            foreach ($data['attachments'] as $file) {
                if ($file instanceof \Illuminate\Http\UploadedFile) {
                    $hasAttachmentChanges = true;
                    $path = $file->store('attachments', 'public');
                    $newAttachmentFiles[] = [
                        'name' => $file->getClientOriginalName(),
                        'path' => 'storage/' . $path,
                    ];
                }
            }
        }

        if ($hasAttachmentChanges) {
            $allFiles = array_merge($retainedFiles, $newAttachmentFiles);
            $props['attachments'] = [
                'count' => count($allFiles),
                'files' => $allFiles,
            ];
        }

        // --- Reminder Handling ---
        if (array_key_exists('remind_me', $data)) {
            $remindMe = filter_var($data['remind_me'], FILTER_VALIDATE_BOOLEAN);
            $props['remind_me'] = $remindMe;
            $props['reminder_preset'] = $data['reminder_preset'] ?? ($props['reminder_preset'] ?? '30_minutes');
            $props['reminder_time_value'] = $data['reminder_time_value'] ?? ($props['reminder_time_value'] ?? '30');
            $props['reminder_time_unit'] = $data['reminder_time_unit'] ?? ($props['reminder_time_unit'] ?? 'Minutes');

            if ($remindMe && !empty($data['remind_at'])) {
                $remindAtUtc = Carbon::parse($data['remind_at'], $this->requestTimezone())->utc();
                $props['remind_at'] = $remindAtUtc->format('Y-m-d H:i:s');
                $referenceDateUtc = !empty($props['activity_date'])
                    ? Carbon::parse($props['activity_date'], 'UTC')
                    : null;
                $reminderAttachments = $props['attachments']['files'] ?? [];

                if ($existingReminder) {
                    $existingReminder->update([
                        'reference' => $props['reference'] ?? $existingReminder->reference,
                        'set_date_time' => $referenceDateUtc ?? $existingReminder->set_date_time,
                        'summary' => $props['subject'] ?? $existingReminder->summary,
                        'message' => $props['description'] ?? $existingReminder->message,
                        'reminder_date_time' => $remindAtUtc,
                        'send_at' => $remindAtUtc,
                        'status' => Reminder::STATUS_PENDING,
                        'attachments' => $reminderAttachments,
                        'activity_log_id' => $log->id,
                    ]);
                    \App\Jobs\Reminder\DispatchReminderDueJob::dispatch($existingReminder->id, $remindAtUtc)
                        ->delay($remindAtUtc)
                        ->afterCommit();
                    $props['reminder_id'] = $existingReminder->id;
                } else {
                    $newReminder = app(ReminderService::class)->createFromActivity(
                        [
                            'remind_me' => true,
                            'remind_at' => $data['remind_at'],
                            // createFromActivity expects the user's local time
                            'activity_date' => $this->utcToLocal($props['activity_date'] ?? null),
                            'reference' => $props['reference'] ?? null,
                            'subject' => $props['subject'] ?? null,
                            'description' => $props['description'] ?? null,
                            'is_reminder' => true,
                        ],
                        $log->subject_id,
                        $log->subject_type,
                        $log->borrower_id,
                        $log->partner_id,
                        $reminderAttachments
                    );
                    if ($newReminder) {
                        $newReminder->update(['activity_log_id' => $log->id]);
                        $props['reminder_id'] = $newReminder->id;
                    }
                }
            } else {
                // Reminder switched off — drop it so it no longer shows in the reminders list
                $props['remind_at'] = null;
                $existingReminder?->delete();
                $props['reminder_id'] = null;
            }
        } elseif ($existingReminder && $existingReminder->status === Reminder::STATUS_PENDING) {
            $referenceDateUtc = !empty($props['activity_date'])
                ? Carbon::parse($props['activity_date'], 'UTC')
                : null;
            $existingReminder->update([
                'reference' => $props['reference'] ?? $existingReminder->reference,
                'set_date_time' => $referenceDateUtc ?? $existingReminder->set_date_time,
                'summary' => $props['subject'] ?? $existingReminder->summary,
                'message' => $props['description'] ?? $existingReminder->message,
                'attachments' => $props['attachments']['files'] ?? $existingReminder->attachments,
                'activity_log_id' => $log->id,
            ]);
        }

        $log->properties = $props;
        $log->save();

        return $log->fresh(['causer', 'subject']);
    }

    /**
     * Delete a manually-created activity log (log_name = 'activity' only).
     */
    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $log = ActivityLog::lockForUpdate()->findOrFail($id);

            $logType = strtolower($log->log_name ?? '');
            if ($logType !== 'activity' && $logType !== ActivityLog::TYPE_ACTIVITY) {
                throw new \Exception('Only manual activity entries can be deleted.', 403);
            }

            if (!$log->isOwnedBy(Auth::user())) {
                throw new \Exception('You can only delete activities you created.', 403);
            }

            $props = $log->properties;
            if ($props instanceof \Illuminate\Support\Collection) {
                $props = $props->toArray();
            }

            $this->findLinkedReminder($log, is_array($props) ? $props : [])?->delete();

            $log->delete();
        });
    }

    private function requestTimezone(): string
    {
        return request()->header('X-Timezone') ?: config('app.timezone');
    }

    /**
     * The frontend sends date/times as wall-clock values in the viewer's timezone.
     */
    private function localToUtc(?string $value): ?string
    {
        return empty($value)
            ? null
            : Carbon::parse($value, $this->requestTimezone())->utc()->format('Y-m-d H:i:s');
    }

    private function utcToLocal(?string $value): ?string
    {
        return empty($value)
            ? null
            : Carbon::parse($value, 'UTC')->setTimezone($this->requestTimezone())->format('Y-m-d H:i:s');
    }

    /**
     * Reminder belonging to a manual activity: by the stored reminder_id, or —
     * for activities created before reminder_id was stored — the reminder on the
     * same subject with the same summary (same match ActivityLogResource uses).
     */
    private function findLinkedReminder(ActivityLog $log, array $props): ?Reminder
    {
        if (!empty($props['reminder_id'])) {
            return Reminder::find($props['reminder_id']);
        }

        if (!$log->subject_id || !$log->subject_type || empty($props['subject'])) {
            return null;
        }

        return Reminder::where('entity_id', $log->subject_id)
            ->where('entity_type', $log->subject_type)
            ->where('summary', $props['subject'])
            ->whereIn('status', [Reminder::STATUS_PENDING, Reminder::STATUS_SENT])
            ->latest()
            ->first();
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
                $log->subject_type === BorrowerDocument::class ||
                (isset($file['path']) && str_contains((string) $file['path'], '/borrowers/'));

            if ($isBorrowerDocument) {
                $document = BorrowerDocument::withTrashed()->find($documentId);
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
                $document = PartnerDocument::withTrashed()->find($documentId);
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
