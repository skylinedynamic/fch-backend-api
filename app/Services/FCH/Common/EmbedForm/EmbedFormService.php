<?php

namespace App\Services\FCH\Common\EmbedForm;

use App\Helpers\ApiResponse;
use App\Models\Borrower\Borrower;
use App\Models\EmbedForm\EmbedFormAttachment;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Services\FCH\Common\Borrower\BorrowerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;


class EmbedFormService
{
    /**
     * Resolve an EmbedFormConfig by public key. Returns null if not found or inactive.
     */
    public function resolveConfig(string $publicKey): ?EmbedFormConfig
    {
        return EmbedFormConfig::with('partner')
            ->where('public_key', $publicKey)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get or create an EmbedFormConfig for the given partner.
     */
    public function getOrCreateConfig(int $partnerId): EmbedFormConfig
    {
        return EmbedFormConfig::firstOrCreate(
            ['partner_id' => $partnerId],
            [
                'public_key' => EmbedFormConfig::generatePublicKey(),
                'form_title' => 'Personal Loan Application',
                'notification_email' => null,
                'is_active' => true,
            ]
        );
    }

    /**
     * Update config settings for a partner.
     */
    public function updateConfig(int $partnerId, array $data): EmbedFormConfig
    {
        $config = $this->getOrCreateConfig($partnerId);
        $config->update(array_filter([
            'form_title' => $data['form_title'] ?? null,
            'allowed_domains' => $data['allowed_domains'] ?? null,
            'notification_email' => $data['notification_email'] ?? null,
            'is_active' => $data['is_active'] ?? null,
        ], fn($v) => !is_null($v)));

        return $config->fresh();
    }

    /**
     * Regenerate the public key for a partner's embed form.
     */
    public function regenerateKey(int $partnerId): EmbedFormConfig
    {
        $config = $this->getOrCreateConfig($partnerId);
        $config->update(['public_key' => EmbedFormConfig::generatePublicKey()]);
        return $config->fresh();
    }

    /**
     * Store a new submission from the public form.
     */
    public function storeSubmission(EmbedFormConfig $config, array $data, Request $request): EmbedFormSubmission
    {
        DB::beginTransaction();
        try {
            $ssnEncrypted = null;
            if (!empty($data['ssn'])) {
                $ssnEncrypted = Crypt::encryptString($data['ssn']);
            }

            $submission = EmbedFormSubmission::create([
                'submission_uid' => (string) Str::uuid(),
                'partner_id' => $config->partner_id,
                'form_config_id' => $config->id,

                // Personal
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'birthday' => $data['birthday'] ?? null,
                'ssn_encrypted' => $ssnEncrypted,
                'credit_score' => $data['credit_score'] ?? null,
                'desired_funding_amount' => $data['desired_funding_amount'] ?? null,

                // Living
                'home_address' => $data['home_address'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'monthly_housing_payment' => $data['monthly_housing_payment'] ?? null,
                'housing_status' => $data['housing_status'] ?? null,

                // Employer
                'employer_name' => $data['employer_name'] ?? null,
                'employer_phone' => $data['employer_phone'] ?? null,
                'employer_address' => $data['employer_address'] ?? null,
                'occupation' => $data['occupation'] ?? null,
                'annual_income' => $data['annual_income'] ?? null,

                // Referral
                'sales_rep' => $data['sales_rep'] ?? null,
                'referral_source' => $data['referral_source'] ?? null,
                'status' => EmbedFormSubmission::STATUS_NEW,
                'ip_address' => $request->ip(),
                'origin_url' => $request->headers->get('referer'),
            ]);

            // Store uploaded files
            $fileFields = ['paystub_document', 'credit_report_document'];
            foreach ($fileFields as $fieldName) {
                if ($request->hasFile($fieldName)) {
                    $files = $request->file($fieldName);
                    if (!is_array($files)) {
                        $files = [$files];
                    }
                    foreach ($files as $file) {
                        $this->storeAttachment($submission, $file, $fieldName);
                    }
                }
            }

            DB::commit();

            // Send notification email
            $this->sendNotificationEmail($submission, $config);

            return $submission->load('attachments');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error(__('embed_form.log_store_failed'), ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    /**
     * Store a single file attachment.
     */
    protected function storeAttachment(EmbedFormSubmission $submission, UploadedFile $file, string $fieldName): void
    {
        $directory = "embed_forms/{$submission->partner_id}/{$submission->id}";
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs($directory, $filename, 'public');

        EmbedFormAttachment::create([
            'submission_id' => $submission->id,
            'partner_id' => $submission->partner_id,
            'field_name' => $fieldName,
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'created_at' => now(),
        ]);
    }

    /**
     * Send notification email to partner.
     */
    protected function sendNotificationEmail(EmbedFormSubmission $submission, EmbedFormConfig $config): void
    {
        $notifyEmail = $config->notification_email
            ?: ($config->partner?->contact_person_email ?: $config->partner?->email);

        if (!$notifyEmail) {
            return;
        }

        try {
            Mail::to($notifyEmail)->send(
                new \App\Mail\EmbedForm\NewSubmissionMail($submission)
            );
        } catch (\Exception $e) {
            Log::error(__('embed_form.log_notification_failed'), ['error' => $e->getMessage()]);
        }
    }

    /**
     * Paginated submissions for a partner (DataTable).
     */
    public function getSubmissions(int $partnerId, array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = EmbedFormSubmission::with('attachments')
            ->where('partner_id', $partnerId);

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'LIKE', "%{$s}%")
                    ->orWhere('last_name', 'LIKE', "%{$s}%")
                    ->orWhere('email', 'LIKE', "%{$s}%")
                    ->orWhere('phone', 'LIKE', "%{$s}%")
                    ->orWhere('employer_name', 'LIKE', "%{$s}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $perPage = $filters['per_page'] ?? 15;
        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Update a submission's status.
     */
    public function updateStatus(EmbedFormSubmission $submission, string $status): EmbedFormSubmission
    {
        $submission->update(['status' => $status]);
        return $submission->fresh(['attachments']);
    }

    /**
     * Convert an EmbedFormSubmission to a Borrower.
     */
    public function convertToBorrower(EmbedFormSubmission $submission, array $data = []): array
    {
        DB::beginTransaction();
        try {
            if (empty($submission->email)) {
                throw new \InvalidArgumentException(__('embed_form.email_required_conversion'));
            }

            if (Borrower::where('email', $submission->email)->exists()) {
                throw new \InvalidArgumentException(__('embed_form.borrower_exists_email'));
            }

            $borrowerService = app(BorrowerService::class);

            $ssnDecrypted = null;
            if ($submission->ssn_encrypted) {
                try {
                    $ssnDecrypted = Crypt::decryptString($submission->ssn_encrypted);
                } catch (\Throwable $e) {
                    $ssnDecrypted = null;
                }
            }

            $borrowerData = [
                'first_name' => $submission->first_name,
                'last_name' => $submission->last_name,
                'email' => $submission->email,
                'phone_number' => $submission->phone,
                'business_name' => $submission->employer_name ?: "{$submission->first_name} {$submission->last_name}",
                'business_email' => $submission->email,
                'business_phone' => $submission->employer_phone ?: $submission->phone,
                'home_address' => $submission->home_address,
                'city' => $submission->city,
                'state' => $submission->state,
                'postal_code' => $submission->postal_code,
                'country' => 'United States',
                'country_code' => 'US',
                'last_4_ssn' => $ssnDecrypted ? substr($ssnDecrypted, -4) : null,
                'status' => Borrower::STATUS_ONBOARDING,
                'partner_id' => $submission->partner_id ?: (auth()->user()?->partner_id ?? null),
                'plan_id' => $data['plan_id'] ?? null,
                'enrollment_payment' => $data['enrollment_amount'] ?? ($data['payment_amount'] ?? null),
                'is_free_trail' => $data['is_free_trail'] ?? null,
                'assign_id' => $data['salesperson_id'] ?? null,
            ];

            $result = $borrowerService->store($borrowerData);
            $borrower = $result['borrower'] ?? $result;

            $submission->update([
                'status' => EmbedFormSubmission::STATUS_CONVERTED,
            ]);

            DB::commit();
            return [
                'borrower' => $borrower,
                'id' => is_object($borrower) ? $borrower->id : ($result['borrower_id'] ?? null),
                'invoice_id' => $result['invoice_id'] ?? null,
                'payment_amount' => $result['payment_amount'] ?? ($data['enrollment_amount'] ?? 0),
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error(__('embed_form.log_conversion_failed'), ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Securely download an embed form attachment with tenant RBAC scoping.
     * Ensures auth user partner_id and form partner_id strictly match (unless host admin).
     */
    public function downloadAttachment(int $id): StreamedResponse|JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return ApiResponse::unauthenticated();
        }

        $userPartnerId = $user->partner_id ?? $user->entity_id;
        $isHost = (bool) ($user->is_host || $user->partner?->is_host);

        $attachment = EmbedFormAttachment::with('submission')->find($id);

        if (!$attachment) {
            return ApiResponse::notFound(__('embed_form.file_not_found'));
        }

        // Verify attachment and its submission/form partner ID matches the authenticated user's partner ID
        $formPartnerId = $attachment->partner_id ?: $attachment->submission?->partner_id;

        if (!$isHost && (empty($userPartnerId) || empty($formPartnerId) || (int) $formPartnerId !== (int) $userPartnerId)) {
            return ApiResponse::forbidden(__('embed_form.unauthorized_attachment'));
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($attachment->file_path)) {
            return ApiResponse::notFound(__('embed_form.file_not_exists'));
        }

        return $disk->download($attachment->file_path, $attachment->original_name);
    }
}

