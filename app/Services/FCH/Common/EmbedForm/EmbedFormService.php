<?php

namespace App\Services\FCH\Common\EmbedForm;

use App\Helpers\ApiResponse;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationCoBorrower;
use App\Models\Borrower\FundingApplicationDocument;
use App\Mail\EmbedForm\EmbedFormConverted;
use App\Models\EmbedForm\EmbedFormAttachment;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Borrower\BorrowerService;
use App\Services\FCH\Common\Setting\EmailConfigurationService;
use App\Support\EmbedForm\EmbedFormLocation;
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
     * Is this submission coming from a page the partner allowed?
     *
     * "Allowed Domains" has been collected on the configuration screen since the
     * feature shipped but was never actually checked anywhere - any site could
     * embed any partner's form. This is that check.
     *
     * The host is read from Origin first and Referer second. A cross-origin
     * iframe POST always carries Origin; Referer is the fallback for a
     * same-origin post and for browsers that trim it. Both are set by the
     * browser, not the proxy, so they survive the proxy path unchanged - unlike
     * Host, which is why that is deliberately not used here.
     *
     * An empty list means unrestricted, matching what the configuration screen
     * tells the partner. The host the form is SERVED from is always allowed, so
     * the Direct Shareable Link keeps working even when the list names only the
     * partner's website. That host is now the partner's own domain rather than
     * the API's - a partner with an allow-list would otherwise have their own
     * link refused the moment it moved domains. Partner 17 is the live example:
     * their list names `fourcorneracademy.com` while their form is published on
     * `admin.fourcorneracademy.com`, and a subdomain is deliberately not treated
     * as the listed host.
     */
    public function isOriginAllowed(EmbedFormConfig $config, Request $request): bool
    {
        $allowed = $config->allowed_domains ?? [];

        if (empty($allowed) || !is_array($allowed)) {
            return true;
        }

        $source = $request->headers->get('Origin') ?: $request->headers->get('Referer');

        // No Origin and no Referer means this is not a browser posting from a
        // page - a direct API call, or a stripped-down client. With a domain
        // allow-list configured, that is refused rather than waved through.
        if (!$source) {
            return false;
        }

        $requestHost = $this->normalizeHost($source);

        if (!$requestHost) {
            return false;
        }

        $permitted = [
            // Where the form is published for this partner.
            $this->normalizeHost(EmbedFormLocation::forPartner($config->partner)->baseUrl()),
            // The API's own host, which served the form before the link moved.
            // Kept so links already pasted into partner sites are not refused
            // the day this ships.
            $this->normalizeHost(config('app.url')),
        ];

        foreach ($allowed as $domain) {
            $permitted[] = $this->normalizeHost($domain);
        }

        return in_array($requestHost, array_filter($permitted), true);
    }

    /**
     * Reduce a URL or bare domain to a comparable lowercase host.
     */
    protected function normalizeHost(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $value = trim($value);

        // parse_url only finds a host when a scheme is present, and partners
        // type bare domains as often as full URLs.
        if (!preg_match('#^https?://#i', $value)) {
            $value = 'https://' . $value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (!$host) {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Where this partner's form is published, or why it cannot be.
     */
    public function locationFor(int $partnerId): EmbedFormLocation
    {
        return EmbedFormLocation::forPartner(Partner::find($partnerId));
    }

    /**
     * This partner's config, without creating one.
     *
     * The settings screen calls this so a partner who cannot yet publish is
     * shown the warning rather than handed a form whose link goes nowhere.
     */
    public function findConfig(int $partnerId): ?EmbedFormConfig
    {
        return EmbedFormConfig::with('partner')->where('partner_id', $partnerId)->first();
    }

    /**
     * Get or create an EmbedFormConfig for the given partner.
     *
     * Refuses to create one for a partner with no address to publish it at. A
     * White-Label partner's form lives on a domain of its own and nowhere else,
     * so until one exists the form has no URL - and a config row created anyway
     * would hand out a key that resolves to nothing.
     *
     * @throws \DomainException when the partner has nowhere to publish.
     */
    public function getOrCreateConfig(int $partnerId): EmbedFormConfig
    {
        $existing = $this->findConfig($partnerId);

        if ($existing) {
            return $existing;
        }

        $location = $this->locationFor($partnerId);

        if (!$location->isAvailable()) {
            throw new \DomainException($location->warning());
        }

        return EmbedFormConfig::create([
            'partner_id' => $partnerId,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Business Funding Application',
            'notification_email' => null,
            'is_active' => true,
        ]);
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
     *
     * The payload mirrors the borrower portal's funding application, so the
     * write is grouped by the same steps the applicant filled in.
     */
    public function storeSubmission(EmbedFormConfig $config, array $data, Request $request): EmbedFormSubmission
    {
        DB::beginTransaction();
        try {
            $submission = EmbedFormSubmission::create(array_merge(
                [
                    'submission_uid' => (string) Str::uuid(),
                    'partner_id' => $config->partner_id,
                    'form_config_id' => $config->id,
                    'status' => EmbedFormSubmission::STATUS_NEW,
                    'ip_address' => $request->ip(),
                    'origin_url' => $request->headers->get('referer'),
                    'sales_rep_user_id' => $data['sales_rep_user_id'] ?? null,
                    // Denormalised alongside the id so the name a partner saw at
                    // submission time survives the user being renamed or removed.
                    'sales_rep' => $this->resolveSalesRepName($data['sales_rep_user_id'] ?? null),
                    'referral_source' => $data['referral_source'] ?? null,
                    'referral_source_other' => ($data['referral_source'] ?? null) === config('funding_application.embed_form.referral_source_other')
                        ? ($data['referral_source_other'] ?? null)
                        : null,
                ],
                $this->businessInfoAttributes($data),
                $this->ownershipAttributes($data),
                $this->financialAttributes($data),
                $this->fundingRequestAttributes($data),
                $this->disclosureAttributes($data),
                $this->federalComplianceAttributes($data),
                $this->attestationAttributes($data, $request),
            ));

            $this->storeCoBorrowers($submission, $data['co_borrowers'] ?? []);
            $this->storeDocuments($submission, $request);

            DB::commit();

            // Send notification email
            $this->sendNotificationEmail($submission, $config);

            return $submission->load(['attachments.docType', 'coBorrowers']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error(__('embed_form.log_store_failed'), ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    /**
     * Business Information step.
     */
    protected function businessInfoAttributes(array $data): array
    {
        return [
            'full_name' => $data['full_name'] ?? null,
            'business_name' => $data['business_name'] ?? null,
            'business_email' => $data['business_email'] ?? null,
            // Stored E.164 so the same number is one value however it was typed.
            'business_phone_no' => normalizePhoneE164($data['business_phone_no'] ?? null)
                ?: ($data['business_phone_no'] ?? null),
            'business_country' => $data['business_country'] ?? null,
            'business_country_code' => $data['business_country_code'] ?? null,
            'business_address' => $data['business_address'] ?? null,
            'business_city' => $data['business_city'] ?? null,
            'business_state' => $data['business_state'] ?? null,
            'business_postal_code' => $data['business_postal_code'] ?? null,
            'industry_type' => $data['industry_type'] ?? null,
            'entity_type' => $data['entity_type'] ?? null,
            'years_in_business' => $data['years_in_business'] ?? null,
            'number_of_employees' => $data['number_of_employees'] ?? null,
        ];
    }

    /**
     * Ownership Information step.
     *
     * The SSN is encrypted at rest: this endpoint is unauthenticated, so the
     * plaintext only reappears when a partner converts the submission.
     */
    protected function ownershipAttributes(array $data): array
    {
        return [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => normalizePhoneE164($data['phone'] ?? null) ?: ($data['phone'] ?? null),
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'ssn_encrypted' => !empty($data['ssn']) ? Crypt::encryptString($data['ssn']) : null,
            'citizenship_status' => $data['citizenship_status'] ?? null,
            'street_address' => $data['street_address'] ?? null,
            'country' => $data['country'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip_code' => $data['zip_code'] ?? null,
            'ownership' => $data['ownership'] ?? null,
            'has_co_borrower' => !empty($data['co_borrowers']),
        ];
    }

    /**
     * Financial Information step.
     */
    protected function financialAttributes(array $data): array
    {
        $hasDebt = filter_var($data['has_business_debt'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'monthly_revenue' => $data['monthly_revenue'] ?? null,
            'annual_revenue' => $data['annual_revenue'] ?? null,
            'business_bank_name' => $data['business_bank_name'] ?? null,
            'business_checking_start_date' => $data['business_checking_start_date'] ?? null,
            // The applicant gives one number. It is stored as the number, and
            // mirrored into the column the portal's funding application reads,
            // so the conversion has the string that side requires without the
            // applicant being asked for a range as well.
            'credit_score' => $data['credit_score'] ?? null,
            'self_reported_personal_credit_score' => isset($data['credit_score'])
                ? (string) $data['credit_score']
                : null,
            'self_reported_business_credit_score' => $data['self_reported_business_credit_score'] ?? null,
            'has_business_debt' => $hasDebt,
            // A balance only means anything alongside a "yes", and leaving a
            // stale figure behind would misstate the applicant's position.
            'debt_balance' => $hasDebt ? ($data['debt_balance'] ?? null) : null,
        ];
    }

    /**
     * Funding Request step.
     */
    protected function fundingRequestAttributes(array $data): array
    {
        return [
            'requested_amount' => $data['requested_amount'] ?? null,
            'purpose_of_funds' => $data['purpose_of_funds'] ?? null,
            'purpose_note' => $data['purpose_note'] ?? null,
            // Left for a partner to set during review - a public applicant has
            // no approved funding to name.
            'approved_funding' => null,
            'preferred_funding_type' => $data['preferred_funding_type'] ?? null,
            'timeframe_needed' => $data['timeframe_needed'] ?? null,
            'collateral_available' => $data['collateral_available'] ?? null,
        ];
    }

    /**
     * Disclosures and consent step.
     */
    protected function disclosureAttributes(array $data): array
    {
        return [
            'credit_pull_authorization' => filter_var($data['credit_pull_authorization'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'terms_conditions_acceptance' => filter_var($data['terms_conditions_acceptance'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Federal Compliance Disclosures step.
     */
    protected function federalComplianceAttributes(array $data): array
    {
        $flags = [
            'filed_for_bankruptcy',
            'any_pending_lawsuits',
            'has_federal_loan',
            'has_federal_debt',
            'has_criminal_offense',
            'has_parole_or_probation',
            'suspended_or_debarred',
            'any_outstanding_judgments_business',
            'any_outstanding_judgments_owner',
        ];

        $attributes = [];
        foreach ($flags as $flag) {
            $attributes[$flag] = filter_var($data[$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $attributes['federal_compliance_disclosures_explanation'] =
            $data['federal_compliance_disclosures_explanation'] ?? null;

        return $attributes;
    }

    /**
     * Attestation and authorization step.
     *
     * The four consents are stored individually AND rolled into signature_data
     * with the moment each was given, because that record is what makes the
     * e-signature defensible later.
     */
    protected function attestationAttributes(array $data, Request $request): array
    {
        $consents = [
            'esign_consent',
            'terms_consent',
            'credit_consent',
            'legal_binding_consent',
        ];

        $attributes = [];
        $allAccepted = true;

        foreach ($consents as $consent) {
            $accepted = filter_var($data[$consent] ?? false, FILTER_VALIDATE_BOOLEAN);
            $attributes[$consent] = $accepted;
            $allAccepted = $allAccepted && $accepted;
        }

        // consents_data carries the per-checkbox timestamps the form recorded as
        // the applicant ticked them. It is applicant-supplied, so it is decoded
        // defensively and the server's own view of the signing is added
        // alongside rather than trusting it wholesale.
        $clientConsents = null;
        if (!empty($data['consents_data'])) {
            $decoded = json_decode((string) $data['consents_data'], true);
            $clientConsents = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        $attributes['signature_name'] = $data['signature_name'] ?? null;
        $attributes['attestation_authorization'] = $allAccepted;
        $attributes['application_version'] = $data['application_version'] ?? null;
        $attributes['submission_timezone'] = $data['timezone'] ?? null;
        $attributes['signature_data'] = [
            'signature_name' => $data['signature_name'] ?? null,
            'signed_at' => now()->toIso8601String(),
            'timezone' => $data['timezone'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'application_version' => $data['application_version'] ?? null,
            'client_consents' => $clientConsents,
        ];

        return $attributes;
    }

    /**
     * Display name of the selected sales rep, if any.
     *
     * The id is already validated against the owning partner's active users, so
     * this only has to turn it into a name.
     */
    protected function resolveSalesRepName($userId): ?string
    {
        if (!$userId) {
            return null;
        }

        $user = \App\Models\User\User::find($userId);

        if (!$user) {
            return null;
        }

        return $user->name ?: null;
    }

    /**
     * Persist the co-borrowers added on the ownership step.
     */
    protected function storeCoBorrowers(EmbedFormSubmission $submission, array $coBorrowers): void
    {
        foreach ($coBorrowers as $coBorrower) {
            if (empty($coBorrower['first_name']) && empty($coBorrower['last_name'])) {
                continue;
            }

            $submission->coBorrowers()->create([
                'first_name' => $coBorrower['first_name'] ?? null,
                'last_name' => $coBorrower['last_name'] ?? null,
                'title_role' => $coBorrower['title_role'] ?? null,
                'ownership_percentage' => $coBorrower['ownership_percentage'] ?? null,
                'email' => $coBorrower['email'] ?? null,
                'mobile_phone' => normalizePhoneE164($coBorrower['mobile_phone'] ?? null)
                    ?: ($coBorrower['mobile_phone'] ?? null),
                'home_phone' => normalizePhoneE164($coBorrower['home_phone'] ?? null)
                    ?: ($coBorrower['home_phone'] ?? null),
                'date_of_birth' => $coBorrower['date_of_birth'] ?? null,
                'ssn_encrypted' => !empty($coBorrower['ssn']) ? Crypt::encryptString($coBorrower['ssn']) : null,
                'street_address' => $coBorrower['street_address'] ?? null,
                'country' => $coBorrower['country'] ?? null,
                'city' => $coBorrower['city'] ?? null,
                'state' => $coBorrower['state'] ?? null,
                'zip_code' => $coBorrower['zip_code'] ?? null,
                'drivers_license_number' => $coBorrower['drivers_license_number'] ?? null,
                'drivers_license_state' => $coBorrower['drivers_license_state'] ?? null,
            ]);
        }
    }

    /**
     * Store uploads, one bucket per borrower document type.
     *
     * Files arrive as `documents[<doc type id>][]`, which is how a document type
     * added in the admin appears on the form without a code change here.
     */
    protected function storeDocuments(EmbedFormSubmission $submission, Request $request): void
    {
        $documents = $request->file('documents');

        if (!is_array($documents)) {
            return;
        }

        $docTypes = BorrowerDocType::query()->pluck('name', 'id');

        foreach ($documents as $docTypeId => $files) {
            $docTypeId = (int) $docTypeId;

            if (!$docTypes->has($docTypeId)) {
                continue;
            }

            foreach (is_array($files) ? $files : [$files] as $file) {
                if ($file instanceof UploadedFile) {
                    $this->storeAttachment($submission, $file, $docTypes[$docTypeId], $docTypeId);
                }
            }
        }
    }

    /**
     * Store a single file attachment.
     */
    protected function storeAttachment(
        EmbedFormSubmission $submission,
        UploadedFile $file,
        string $fieldName,
        ?int $docTypeId = null
    ): void {
        $directory = "embed_forms/{$submission->partner_id}/{$submission->id}";
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs($directory, $filename, 'public');

        EmbedFormAttachment::create([
            'submission_id' => $submission->id,
            'partner_id' => $submission->partner_id,
            'field_name' => $fieldName,
            'borrower_doc_type_id' => $docTypeId,
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
        $partner = $config->partner;

        // The form's own inbox if one is set, otherwise the partner's account
        // address. NOT contact_person_email: that is the partner's own staff
        // contact, not the address the platform notifies.
        $notifyEmail = $config->notification_email ?: $partner?->email;

        if (!$notifyEmail) {
            return;
        }

        // A White-Label partner sends under its own credentials or not at all.
        // MailConfig would otherwise resolve the host's mailbox for it, and mail
        // leaving under the host's name is the one thing the tier exists to
        // prevent - better to send nothing and say so in the log.
        if ($this->isWhiteLabelPartner($partner) && !$this->hasOwnMailConfiguration($partner)) {
            Log::info(__('embed_form.log_notification_skipped_no_mail_config'), [
                'partner_id' => $partner->id,
                'submission_uid' => $submission->submission_uid,
            ]);

            return;
        }

        try {
            // The template counts attachments and co-borrowers, and lazy loading
            // is disabled outside production - so load them before handing the
            // model to the mailable rather than letting the view trip over it.
            $submission->loadMissing(['attachments', 'coBorrowers']);

            Mail::to($notifyEmail)->send(
                new \App\Mail\EmbedForm\NewSubmissionMail($submission)
            );
        } catch (\Exception $e) {
            Log::error(__('embed_form.log_notification_failed'), ['error' => $e->getMessage()]);
        }
    }

    /**
     * NOTE the order. `is_host` is tested first because the host partner is
     * itself stored with `partner_type = 'White-Label Partner (WL)'`, so a
     * type-first check would treat the platform's own form as white-label and
     * silence its notifications.
     */
    protected function isWhiteLabelPartner(?Partner $partner): bool
    {
        return $partner
            && !$partner->is_host
            && $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL;
    }

    /**
     * Whether this partner can send mail under its own credentials.
     *
     * Mirrors the first branch of EmailConfigurationService::resolveFor() - a row
     * exists as soon as the settings screen is opened, so having rows is not the
     * same as being able to connect, and the partner must have switched its own
     * configuration on.
     */
    protected function hasOwnMailConfiguration(Partner $partner): bool
    {
        $emailConfigurations = app(EmailConfigurationService::class);
        $own = $emailConfigurations->getConfigurations($partner->id)->first();

        return $own
            && !empty($own->enable_custom_configuration)
            && $emailConfigurations->isUsable($own);
    }

    /**
     * Paginated submissions for a partner (DataTable).
     */
    public function getSubmissions(int $partnerId, array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = EmbedFormSubmission::with('attachments.docType', 'coBorrowers', 'convertedBorrower', 'salesRepUser')
            ->where('partner_id', $partnerId);

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'LIKE', "%{$s}%")
                    ->orWhere('last_name', 'LIKE', "%{$s}%")
                    ->orWhere('email', 'LIKE', "%{$s}%")
                    ->orWhere('phone', 'LIKE', "%{$s}%")
                    ->orWhere('business_name', 'LIKE', "%{$s}%")
                    ->orWhere('business_email', 'LIKE', "%{$s}%")
                    // Kept so submissions taken before the funding application
                    // rewrite are still findable by the employer they named.
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
        return $submission->fresh(['attachments.docType', 'coBorrowers', 'convertedBorrower']);
    }

    /**
     * Convert an EmbedFormSubmission to a Free Trial Borrower.
     *
     * Embed form applicants are always enrolled on a free trial, so no plan or
     * enrollment payment is involved — the borrower is created as active with no
     * trial end date (unlimited access until manually upgraded or suspended).
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

            // Embed form conversions are always free trial enrollments with no expiry.
            //
            // The business and address fields fall back to their pre-rewrite
            // consumer counterparts so a submission taken before the funding
            // application rewrite still converts with the data it does have.
            $borrowerData = [
                'first_name' => $submission->first_name,
                'last_name' => $submission->last_name,
                'email' => $submission->email,
                'phone_number' => $submission->phone,
                'business_name' => $submission->business_name
                    ?: ($submission->employer_name ?: "{$submission->first_name} {$submission->last_name}"),
                'business_email' => $submission->business_email ?: $submission->email,
                'business_phone' => $submission->business_phone_no
                    ?: ($submission->employer_phone ?: $submission->phone),
                'home_address' => $submission->street_address ?: $submission->home_address,
                'city' => $submission->city,
                'state' => $submission->state,
                'postal_code' => $submission->zip_code ?: $submission->postal_code,
                'country' => $submission->country ?: 'United States',
                'country_code' => 'US',
                'last_4_ssn' => $ssnDecrypted ? substr($ssnDecrypted, -4) : null,
                'status' => Borrower::STATUS_ACTIVE,
                'partner_id' => $submission->partner_id ?: (auth()->user()?->partner_id ?? null),
                'plan_id' => null,
                'enrollment_payment' => null,
                'is_free_trail' => true,
                'free_trail_end_date' => null,
                'assign_id' => $data['salesperson_id'] ?? null,
            ];

            $result = $borrowerService->store($borrowerData);
            $borrower = $result['borrower'] ?? $result;
            $borrowerId = is_object($borrower) ? $borrower->id : ($result['borrower_id'] ?? null);

            // The applicant already filled in a whole funding application on the
            // public form. Carrying it across means partner staff review a real
            // application instead of re-keying it from the submission detail page.
            $fundingApplication = $borrowerId
                ? $this->createFundingApplication($submission, (int) $borrowerId, $ssnDecrypted)
                : null;

            $submission->update([
                'status' => EmbedFormSubmission::STATUS_CONVERTED,
                'converted_borrower_id' => $borrowerId,
                'converted_funding_application_id' => $fundingApplication?->id,
            ]);

            DB::commit();

            // Notified only after the transaction has landed, so nobody is told
            // about a conversion that then rolled back.
            if ($fundingApplication) {
                $this->notifyConverted($submission, $fundingApplication);
            }

            return [
                'borrower' => $borrower,
                'id' => $borrowerId,
                'funding_application' => $fundingApplication,
                'funding_application_id' => $fundingApplication?->id,
                'is_free_trail' => true,
                'free_trail_end_date' => null,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error(__('embed_form.log_conversion_failed'), ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Build a real funding application from a converted submission.
     *
     * Every column on embedded_form_submissions that came from the funding
     * application steps is named after its funding_applications counterpart, so
     * this is a straight copy rather than a mapping table. The step flags are
     * all set: the applicant answered every step on the public form, and leaving
     * them false would show the borrower a half-finished wizard.
     *
     * Status is DRAFT rather than PENDING - a partner should look the
     * application over before it enters underwriting.
     */
    protected function createFundingApplication(
        EmbedFormSubmission $submission,
        int $borrowerId,
        ?string $ssnDecrypted
    ): ?FundingApplication {
        $copied = [
            // Business Information
            'full_name', 'business_name', 'business_email', 'business_phone_no',
            'business_country', 'business_country_code', 'business_address',
            'business_city', 'business_state', 'business_postal_code',
            'industry_type', 'entity_type', 'years_in_business', 'number_of_employees',

            // Ownership Information
            'first_name', 'last_name', 'date_of_birth', 'citizenship_status',
            'street_address', 'country', 'city', 'state', 'zip_code',
            'ownership', 'has_co_borrower',

            // Financial Information
            'monthly_revenue', 'annual_revenue', 'business_bank_name',
            'business_checking_start_date', 'self_reported_personal_credit_score',
            'self_reported_business_credit_score', 'has_business_debt', 'debt_balance',

            // Funding Request
            'requested_amount', 'purpose_of_funds', 'purpose_note', 'approved_funding',
            'preferred_funding_type', 'timeframe_needed', 'collateral_available',

            // Disclosures and consent
            'credit_pull_authorization', 'terms_conditions_acceptance',

            // Federal Compliance Disclosures
            'filed_for_bankruptcy', 'any_pending_lawsuits', 'has_federal_loan',
            'has_federal_debt', 'has_criminal_offense', 'has_parole_or_probation',
            'suspended_or_debarred', 'any_outstanding_judgments_business',
            'any_outstanding_judgments_owner', 'federal_compliance_disclosures_explanation',

            // Attestation and authorization
            'attestation_authorization', 'signature_data',
        ];

        $attributes = [];
        foreach ($copied as $column) {
            $attributes[$column] = $submission->getAttribute($column);
        }

        $application = FundingApplication::create(array_merge($attributes, [
            'borrower_id' => $borrowerId,
            'partner_id' => $submission->partner_id,
            'status' => FundingApplication::DRAFT,

            // phone_number and ssn are the two columns whose names differ, and
            // the SSN is the one value that has to be decrypted on the way out.
            'phone_number' => $submission->phone,
            'ssn' => $ssnDecrypted,

            'is_business_info_saved' => true,
            'is_ownership_info_saved' => true,
            'is_financial_info_saved' => true,
            'is_funding_request_saved' => true,
            'is_disclosures_saved' => true,
            'is_federal_compliance_saved' => true,
        ]));

        $this->copyCoBorrowers($submission, $application);
        $this->copyDocuments($submission, $application, $borrowerId);

        return $application->fresh();
    }

    /**
     * Tell everyone the conversion concerns that it happened.
     *
     * FCH Admin (the host) and, where the client belongs to one, their
     * WLP/partner; plus the applicant, who otherwise sees nothing between
     * submitting the form and being contacted.
     *
     * Failures are logged, never thrown: an email problem must not undo a
     * conversion that has already been committed.
     */
    protected function notifyConverted(
        EmbedFormSubmission $submission,
        FundingApplication $application
    ): void {
        $recipients = [];

        $host = Partner::where('is_host', 1)->first();
        if ($host && !empty($host->email)) {
            $recipients[] = $host->email;
        }

        $partner = $submission->partner;
        if ($partner && !$partner->is_host && !empty($partner->email)) {
            $recipients[] = $partner->email;
        }

        foreach (array_unique($recipients) as $email) {
            try {
                Mail::to($email)->send(new EmbedFormConverted($submission, $application, true));
            } catch (\Throwable $e) {
                Log::error('Failed to notify staff of an embed form conversion', [
                    'submission_id' => $submission->id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($submission->email)) {
            try {
                Mail::to($submission->email)->send(
                    new EmbedFormConverted($submission, $application, false)
                );
            } catch (\Throwable $e) {
                Log::error('Failed to send conversion notice to embed form applicant', [
                    'submission_id' => $submission->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Copy the submission's co-borrowers onto the funding application.
     */
    protected function copyCoBorrowers(EmbedFormSubmission $submission, FundingApplication $application): void
    {
        foreach ($submission->coBorrowers as $coBorrower) {
            $ssn = null;
            if ($coBorrower->ssn_encrypted) {
                try {
                    $ssn = Crypt::decryptString($coBorrower->ssn_encrypted);
                } catch (\Throwable $e) {
                    $ssn = null;
                }
            }

            FundingApplicationCoBorrower::create([
                'funding_application_id' => $application->id,
                'first_name' => $coBorrower->first_name,
                'last_name' => $coBorrower->last_name,
                'title_role' => $coBorrower->title_role,
                'ownership_percentage' => $coBorrower->ownership_percentage,
                'email' => $coBorrower->email,
                'mobile_phone' => $coBorrower->mobile_phone,
                'home_phone' => $coBorrower->home_phone,
                'date_of_birth' => $coBorrower->date_of_birth,
                'ssn' => $ssn,
                'street_address' => $coBorrower->street_address,
                'city' => $coBorrower->city,
                'state' => $coBorrower->state,
                'zip_code' => $coBorrower->zip_code,
                'drivers_license_number' => $coBorrower->drivers_license_number,
                'drivers_license_state' => $coBorrower->drivers_license_state,
            ]);
        }
    }

    /**
     * Copy the submission's uploads into the funding application's documents.
     *
     * The file is duplicated on disk rather than re-pointed: the submission
     * keeps its own record of exactly what was uploaded, and deleting a document
     * from the application must not empty the submission it came from.
     *
     * A file that has gone missing on disk is skipped rather than failing the
     * whole conversion - losing one attachment is not a reason to block a
     * borrower from being created.
     */
    protected function copyDocuments(
        EmbedFormSubmission $submission,
        FundingApplication $application,
        int $borrowerId
    ): void {
        $disk = Storage::disk('public');

        foreach ($submission->attachments as $attachment) {
            if (!$attachment->file_path || !$disk->exists($attachment->file_path)) {
                Log::warning(__('embed_form.log_funding_application_failed'), [
                    'submission_id' => $submission->id,
                    'attachment_id' => $attachment->id,
                    'reason' => 'file missing on disk',
                ]);
                continue;
            }

            $extension = pathinfo($attachment->original_name, PATHINFO_EXTENSION)
                ?: pathinfo($attachment->file_path, PATHINFO_EXTENSION);

            $destination = "funding_applications/{$borrowerId}/{$application->id}/"
                . Str::uuid() . ($extension ? '.' . $extension : '');

            $disk->copy($attachment->file_path, $destination);

            FundingApplicationDocument::create([
                'funding_application_id' => $application->id,
                'borrower_id' => $borrowerId,
                'borrower_doc_type_id' => $attachment->borrower_doc_type_id,
                'name' => $attachment->original_name,
                'path' => $destination,
                'disk' => 'public',
                'original_name' => $attachment->original_name,
                'extension' => $extension ?: null,
                'mime' => $attachment->mime_type,
                'size' => $attachment->file_size,
            ]);
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

