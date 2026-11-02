<?php

namespace App\Services\FCH\Common\EmbedForm;

use App\Support\EmbedForm\AllowedDomain;
use App\Helpers\ApiResponse;
use App\Models\User\User;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationCoBorrower;
use App\Models\Borrower\FundingApplicationDocument;
use App\Exceptions\EmbedFormSubmissionConvertedException;
use App\Mail\EmbedForm\EmbedFormConverted;
use App\Models\EmbedForm\EmbedFormAttachment;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Rules\BusinessEin;
use App\Services\FCH\Common\Application\FundingApplicationDocumentService;
use App\Services\FCH\Common\Application\SignatureRecorder;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Services\FCH\Common\Borrower\BorrowerService;
use App\Services\FCH\Common\Notification\Notifier;
use App\Support\EmbedForm\EmbedFormLocation;
use App\Support\EmbedForm\ExistingBorrowerConfirmationRequired;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Mail\EmbedForm\NewSubmissionMail;
use Throwable;


class EmbedFormService
{
    public const EMAIL_AVAILABLE = 'available';
    public const EMAIL_BORROWER = 'borrower';
    public const EMAIL_BLOCKED = 'blocked';

    /** What converting a submission will do - see conversionPreview(). */
    public const CONVERSION_MODE_NEW = 'new_borrower';
    public const CONVERSION_MODE_EXISTING = 'existing_borrower';

    /** The client's Activity entry when a conversion fills its blank profile fields. */
    public const PROFILE_FILLED_EVENT = 'Profile Filled From Application';

    /** Profile fields that make up one address; filled together or not at all. */
    private const ADDRESS_GROUPS = [
        ['home_address', 'city', 'state', 'postal_code'],
        ['business_address', 'business_city', 'business_state', 'business_postal_code'],
    ];

    /**
     * Uploads copied into the funding application during the conversion under
     * way, as ['from' => embed form path, 'to' => funding application path].
     * The originals are deleted once it commits; the copies if it rolls back.
     *
     * @var array<int, array{from: string, to: string}>
     */
    private array $movedDocuments = [];

    /**
     * What an applicant's email already is in the system.
     *
     * available - nobody holds it.
     * borrower  - held by a Borrower (the only existing owner that is allowed).
     * blocked   - held by a Partner, Employee or plain User.
     *
     * @return array{status: string, borrower: ?Borrower, type: ?string}
     */
    public function classifyEmail(string $email): array
    {
        $email = trim($email);

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            $isBorrower = $user->entity_type === Borrower::class || $user->user_type === User::BORROWER;

            if (!$isBorrower) {
                return [
                    'status' => self::EMAIL_BLOCKED,
                    'borrower' => null,
                    'type' => $user->entity_name ?: ($user->user_type ?: User::USER),
                ];
            }

            $borrower = $user->entity instanceof Borrower
                ? $user->entity
                : Borrower::query()->where('email', $email)->first();

            return ['status' => self::EMAIL_BORROWER, 'borrower' => $borrower, 'type' => User::BORROWER];
        }

        $borrower = Borrower::query()->where('email', $email)->first();

        return $borrower
            ? ['status' => self::EMAIL_BORROWER, 'borrower' => $borrower, 'type' => User::BORROWER]
            : ['status' => self::EMAIL_AVAILABLE, 'borrower' => null, 'type' => null];
    }

    /**
     * Whether an existing borrower is someone other than this partner's client -
     * including a borrower with no partner at all. A borrower's email may only
     * be used on, and converted through, their own partner's embedded form.
     */
    public function belongsToAnotherPartner(?Borrower $borrower, ?int $partnerId): bool
    {
        return $borrower !== null && (int) $borrower->partner_id !== (int) $partnerId;
    }

    /**
     * What the partner is shown before agreeing to reuse an existing borrower,
     * including which of its blank profile fields the submission would fill
     * if they agree to that too (BUG-064).
     */
    public function existingBorrowerSummary(Borrower $borrower, ?EmbedFormSubmission $submission = null): array
    {
        return [
            'id' => $borrower->id,
            'name' => personName($borrower->first_name, null, $borrower->last_name),
            'email' => $borrower->email,
            'phone_number' => $borrower->phone_number,
            'business_name' => $borrower->business_name,
            'status' => $borrower->status,
            'is_free_trail' => (bool) $borrower->is_free_trail,
            'partner_name' => $borrower->partner?->company_name ?: $borrower->partner?->full_name,
            'funding_applications_count' => FundingApplication::query()->where('borrower_id', $borrower->id)->count(),
            'created_at' => $borrower->created_at?->toIso8601String(),
            'fields_to_fill' => $submission ? $this->profileFieldsToFill($borrower, $submission) : [],
        ];
    }

    /**
     * The blank profile fields a submission would fill on an existing borrower,
     * as {field, label, value} for the Convert dialog. An SSN or EIN shows only
     * its last four.
     *
     * @return list<array{field: string, label: string, value: string}>
     */
    public function profileFieldsToFill(Borrower $borrower, EmbedFormSubmission $submission): array
    {
        $fields = [];
        foreach ($this->blankProfileFields($borrower, $this->submissionProfile($submission)) as $field => $value) {
            $fields[] = [
                'field' => $field,
                'label' => ActivityLog::fieldLabel($field),
                'value' => $this->displayValue($field, (string) $value),
            ];
        }

        return $fields;
    }

    private function displayValue(string $field, string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);

        return match ($field) {
            'ssn' => '***-**-' . substr($digits, -4),
            'business_ein' => '**-***' . substr($digits, -4),
            default => $value,
        };
    }

    /** The borrower profile a submission describes, its SSN decrypted. */
    private function submissionProfile(EmbedFormSubmission $submission): array
    {
        $ssn = null;
        if ($submission->ssn_encrypted) {
            try {
                $ssn = Crypt::decryptString($submission->ssn_encrypted);
            } catch (Throwable $e) {
                $ssn = null;
            }
        }

        return $this->borrowerProfileFrom($submission, $ssn);
    }

    /**
     * The borrower a submission would be converted onto, or null when a new
     * one would be created. Throws InvalidArgumentException when the
     * submission cannot be converted at all (no email, a staff member's email,
     * another partner's client).
     */
    private function convertibleBorrower(EmbedFormSubmission $submission): ?Borrower
    {
        if (empty($submission->email)) {
            throw new \InvalidArgumentException(__('embed_form.email_required_conversion'));
        }

        $match = $this->classifyEmail($submission->email);

        // Partner / Employee / User: never convertible.
        if ($match['status'] === self::EMAIL_BLOCKED) {
            throw new \InvalidArgumentException(__('embed_form.email_belongs_to_staff', ['type' => $match['type']]));
        }

        $existingBorrower = $match['borrower'];

        // Only the form's own partner's client - checked again here for
        // submissions taken before the form refused it.
        if ($this->belongsToAnotherPartner($existingBorrower, $submission->partner_id)) {
            throw new \InvalidArgumentException(__('embed_form.borrower_other_partner'));
        }

        return $existingBorrower;
    }

    /**
     * What converting the submission would do, without doing it: shown in the
     * Convert modal before the partner confirms. convertToBorrower() still
     * asks again (409) if a borrower appears in between.
     *
     * @return array{mode: string, existing_borrower: ?array}
     */
    public function conversionPreview(EmbedFormSubmission $submission): array
    {
        $existingBorrower = $this->convertibleBorrower($submission);

        return $existingBorrower
            ? ['mode' => self::CONVERSION_MODE_EXISTING, 'existing_borrower' => $this->existingBorrowerSummary($existingBorrower, $submission)]
            : ['mode' => self::CONVERSION_MODE_NEW, 'existing_borrower' => null];
    }

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
    /**
     * The sites the browser may show the form page in an iframe on - only the
     * Allowed Domains, each with and without "www." (BUG-092). The submit's
     * Origin check above cannot do this: inside an iframe the form's own page
     * sends the request, so the browser reports the form's host, never the
     * site around it. Null when the list is empty: unrestricted.
     */
    public function frameAncestors(EmbedFormConfig $config): ?string
    {
        $allowed = is_array($config->allowed_domains) ? $config->allowed_domains : [];

        $sources = [];
        foreach ($allowed as $domain) {
            $host = $this->normalizeHost($domain);
            if (!$host) {
                continue;
            }
            // Always https; an entry typed with http:// allows http as well (R2 BUG-035).
            $schemes = AllowedDomain::allowsHttp((string) $domain) ? ['https', 'http'] : ['https'];
            foreach ($schemes as $scheme) {
                $sources[] = "{$scheme}://{$host}";
                $sources[] = "{$scheme}://www.{$host}";
            }
        }

        return $sources ? implode(' ', array_values(array_unique($sources))) : null;
    }

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
        } catch (Throwable $e) {
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
            // As the borrower and partner portals store a phone: (512) 234-5678.
            'business_phone_no' => $this->portalPhone($data['business_phone_no'] ?? null),
            'business_country' => $data['business_country'] ?? null,
            'business_country_code' => $data['business_country_code'] ?? null,
            'business_address' => $data['business_address'] ?? null,
            'business_city' => $data['business_city'] ?? null,
            'business_state' => $data['business_state'] ?? null,
            'business_postal_code' => $data['business_postal_code'] ?? null,
            'industry_type' => $data['industry_type'] ?? null,
            'entity_type' => $data['entity_type'] ?? null,
            // Nine digits, as the borrower profile keeps an EIN.
            'business_ein' => BusinessEin::normalize($data['business_ein'] ?? null),
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
            'phone' => $this->portalPhone($data['phone'] ?? null),
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
            // Shown on the form as the Business Start Date.
            'business_checking_start_date' => $data['business_checking_start_date'] ?? null,
            // Not asked: it follows from when the business started.
            'years_in_business' => $this->yearsInBusiness($data['business_checking_start_date'] ?? null),
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
     * How long the business has run, as one of the portal's years-in-business
     * options, so it reads the same as a value picked from that list.
     */
    protected function yearsInBusiness(?string $startDate): ?string
    {
        if (!$startDate) {
            return null;
        }

        [$underSix, $underYear, $oneToTwo, $twoToThree, $threePlus] = config('funding_application.options.years_in_business');
        $months = (int) Carbon::parse($startDate)->startOfDay()->diffInMonths(now()->startOfDay());

        return match (true) {
            $months < 6 => $underSix,
            $months < 12 => $underYear,
            $months < 24 => $oneToTwo,
            $months < 36 => $twoToThree,
            default => $threePlus,
        };
    }

    /**
     * Funding Request step.
     */
    protected function fundingRequestAttributes(array $data): array
    {
        return [
            'requested_amount' => $data['requested_amount'] ?? null,
            // A form loaded before the choice existed showed dollars.
            'currency' => $data['currency'] ?? $this->defaultCurrency(),
            'purpose_of_funds' => $data['purpose_of_funds'] ?? null,
            'purpose_note' => $data['purpose_note'] ?? null,
            // Left for a partner to set during review - a public applicant has
            // no approved funding to name.
            'approved_funding' => null,
            'preferred_funding_type' => $data['preferred_funding_type'] ?? null,
            'additional_funding_types' => !empty($data['additional_funding_types'])
                ? array_values($data['additional_funding_types'])
                : null,
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

        $attributes['signature_name'] = $data['signature_name'] ?? null;
        $attributes['attestation_authorization'] = $allAccepted;
        $attributes['application_version'] = $data['application_version'] ?? null;
        $attributes['submission_timezone'] = $data['timezone'] ?? null;
        // Signed on the public form: no account was signed in, so the applicant
        // is known by the email they gave - and the certificate says exactly
        // that. consents_data (the browser's per-checkbox timestamps) is decoded
        // defensively inside, being applicant-supplied.
        $attributes['signature_data'] = SignatureRecorder::record(
            $request,
            SignatureRecorder::METHOD_EMBEDDED_FORM,
            $data,
            null,
            $data['email'] ?? null
        );

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
                'mobile_phone' => $this->portalPhone($coBorrower['mobile_phone'] ?? null),
                'home_phone' => $this->portalPhone($coBorrower['home_phone'] ?? null),
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
     * Tell the partner about a new submission: in the bell always, by email
     * when there is an address.
     */
    protected function sendNotificationEmail(EmbedFormSubmission $submission, EmbedFormConfig $config): void
    {
        $partner = $config->partner;

        // The partner's account address, and only that. The form no longer asks
        // for a notification address of its own, and contact_person_email is the
        // partner's staff contact rather than the address the platform notifies.
        // Every partner - White-Label included - is mailed like all other mail:
        // through its own verified settings, otherwise the host's platform
        // fallback, under the partner's own sender name (MailConfig).
        $notifyEmail = $partner?->email;

        // The template counts attachments and co-borrowers, and lazy loading is
        // disabled outside production - so load them before handing the model
        // to the mailable rather than letting the view trip over it.
        $submission->loadMissing(['attachments', 'coBorrowers']);

        $data = [
            'id' => $submission->id,
            'name' => personName($submission->first_name ?? null, $submission->middle_name ?? null, $submission->last_name ?? null),
        ];

        // A white-label partner is also texted - through its own Twilio account
        // when that is active, never the host's. Certified and host: no text.
        $type = $this->isWhiteLabelPartner($partner)
            ? 'partner.embed_form.submitted_white_label'
            : 'partner.embed_form.submitted';

        try {
            app(Notifier::class)->send(
                $type,
                $partner,
                $data,
                mail: $notifyEmail ? (new NewSubmissionMail($submission))->to($notifyEmail) : null
            );
        } catch (\Exception $e) {
            Log::error(__('embed_form.log_notification_failed'), ['error' => $e->getMessage()]);
        }

        $this->notifyHostOfPartnerSubmission($submission, $partner, $data);
    }

    /**
     * The host is told about every application that comes through a partner's
     * form (its own form already told it above): bell, push and mail, no text.
     * The mail is the host's own, sent under the host's mailbox and branding.
     */
    protected function notifyHostOfPartnerSubmission(EmbedFormSubmission $submission, ?Partner $partner, array $data): void
    {
        if (!$partner || $partner->is_host) {
            return;
        }

        $host = Partner::where('is_host', true)->first();
        if (!$host) {
            return;
        }

        $partnerName = $partner->company_name ?: $partner->full_name;

        try {
            app(Notifier::class)->send(
                'host.embed_form.submitted',
                $host,
                $data + ['partner' => $partnerName],
                mail: $host->email
                ? (new NewSubmissionMail($submission, hostCopy: true, hostPartnerId: (int) $host->id, formPartnerName: $partnerName))->to($host->email)
                : null
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
     * Paginated submissions for a partner (DataTable).
     */
    public function getSubmissions(int $partnerId, array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = EmbedFormSubmission::with('attachments.docType', 'coBorrowers', 'convertedBorrower.latestSubscription', 'salesRepUser')
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
        return $submission->fresh(['attachments.docType', 'coBorrowers', 'convertedBorrower.latestSubscription']);
    }

    /**
     * Delete applications, with their attachments, co-borrowers and files.
     * Limited to one partner's applications when $partnerId is given (null:
     * the host, any partner's). A converted application is the record behind
     * a client and a funding application, so a selection that includes one is
     * refused whole and nothing is deleted.
     *
     * @param  list<int>  $ids
     * @return int how many were deleted
     */
    public function deleteSubmissions(array $ids, ?int $partnerId): int
    {
        $submissions = EmbedFormSubmission::query()
            ->whereKey($ids)
            ->when($partnerId !== null, fn ($query) => $query->where('partner_id', $partnerId))
            ->get();

        $converted = $submissions->where('status', EmbedFormSubmission::STATUS_CONVERTED);
        if ($converted->isNotEmpty()) {
            throw new EmbedFormSubmissionConvertedException(
                __('embed_form.converted_cannot_delete'),
                $converted->map(fn (EmbedFormSubmission $s) => [
                    'id' => $s->id,
                    'name' => trim("{$s->first_name} {$s->last_name}"),
                ])->values()->all(),
            );
        }

        DB::transaction(function () use ($submissions) {
            // Attachments and co-borrowers go with the row (cascade).
            EmbedFormSubmission::whereKey($submissions->modelKeys())->delete();
        });

        // The uploaded files, once the rows are gone.
        foreach ($submissions as $submission) {
            Storage::disk('public')->deleteDirectory("embed_forms/{$submission->partner_id}/{$submission->id}");
        }

        return $submissions->count();
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
        $this->movedDocuments = [];

        DB::beginTransaction();
        try {
            $existingBorrower = $this->convertibleBorrower($submission);

            // Nothing is created until the partner has seen who this is.
            if ($existingBorrower && !filter_var($data['confirm_existing_borrower'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw new ExistingBorrowerConfirmationRequired($this->existingBorrowerSummary($existingBorrower, $submission));
            }

            $borrowerService = app(BorrowerService::class);

            $ssnDecrypted = null;
            if ($submission->ssn_encrypted) {
                try {
                    $ssnDecrypted = Crypt::decryptString($submission->ssn_encrypted);
                } catch (Throwable $e) {
                    $ssnDecrypted = null;
                }
            }

            // Embed form conversions are always free trial enrollments with no expiry.
            $profile = $this->borrowerProfileFrom($submission, $ssnDecrypted);
            $borrowerData = $profile + [
                'status' => Borrower::STATUS_ACTIVE,
                'partner_id' => $submission->partner_id ?: (auth()->user()?->partner_id ?? null),
                'plan_id' => null,
                'enrollment_payment' => null,
                'is_free_trail' => true,
                'free_trail_end_date' => null,
                'assign_id' => $data['salesperson_id'] ?? null,
            ];

            if ($existingBorrower) {
                // The borrower already exists: only the funding application is
                // new. Their blank profile fields are filled only when the
                // partner, shown the list, agreed to it (BUG-064) - and what
                // they or the partner already entered is never overwritten.
                if (filter_var($data['fill_profile_fields'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $this->fillBlankProfileFields($existingBorrower, $profile);
                }
                $borrower = $existingBorrower;
                $borrowerId = $existingBorrower->id;
            } else {
                $result = $borrowerService->store($borrowerData);
                $borrower = $result['borrower'] ?? $result;
                $borrowerId = is_object($borrower) ? $borrower->id : ($result['borrower_id'] ?? null);
            }

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

            // Only now that the application holds them: each upload is kept once.
            $this->deleteMovedOriginals($submission);

            // The applicant signed on the public form: keep the application as
            // signed, with its certificate, as a signing in the portals does.
            // Failures are logged inside; they never undo the conversion.
            if ($fundingApplication && !empty($fundingApplication->signature_data)) {
                $signature = $fundingApplication->signature_data;
                app(FundingApplicationDocumentService::class)->generateAndLockFinalDocuments($fundingApplication, $signature);
            }

            // Notified only after the transaction has landed, so nobody is told
            // about a conversion that then rolled back.
            if ($fundingApplication) {
                $this->notifyConverted($submission, $fundingApplication, (bool) $existingBorrower);
            }

            return [
                'borrower' => $borrower,
                'id' => $borrowerId,
                'funding_application' => $fundingApplication,
                'funding_application_id' => $fundingApplication?->id,
                'is_existing_borrower' => (bool) $existingBorrower,
                'is_free_trail' => $existingBorrower ? (bool) $existingBorrower->is_free_trail : true,
                'free_trail_end_date' => null,
            ];
        } catch (ExistingBorrowerConfirmationRequired $e) {
            // An expected pause for confirmation, not a failure: nothing was written.
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();
            // The attachment rows point at the originals again; drop the copies.
            $this->deleteMovedCopies();
            Log::error(__('embed_form.log_conversion_failed'), ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * The borrower profile the submission's answers fill in.
     *
     * Every field with a counterpart on the profile is carried across, so the
     * borrower does not open a half-empty profile after answering the same
     * questions on the public form. The business and address fields fall back to
     * their pre-rewrite consumer counterparts so a submission taken before the
     * funding application rewrite still converts with the data it does have.
     *
     * Not carried, because the profile has no true counterpart:
     *   years_in_business  - the profile derives it from business_start_date;
     *                        the form only has a range ("2-3 years").
     *   business credit    - a range ("50-64"); the profile's PAYDEX is one number.
     */
    protected function borrowerProfileFrom(EmbedFormSubmission $submission, ?string $ssn): array
    {
        $phone = $this->portalPhone($submission->phone);

        return [
            'first_name' => $submission->first_name,
            'middle_name' => $submission->middle_name,
            'last_name' => $submission->last_name,
            'email' => $submission->email,
            'phone_number' => $phone,
            // What the portals store and what SMS dials with (country code + phone).
            'country_code' => '+1',
            'date_of_birth' => $submission->date_of_birth ? substr((string) $submission->getRawOriginal('date_of_birth'), 0, 10) : null,
            // The whole SSN, encrypted by the Borrower model - not just the last four.
            'ssn' => strlen((string) $this->ssnDigits($ssn)) === 9 ? $this->ssnDigits($ssn) : null,
            'home_address' => $submission->street_address ?: $submission->home_address,
            'city' => $submission->city,
            'state' => $submission->state,
            'postal_code' => $submission->zip_code ?: $submission->postal_code,
            'country' => $submission->country ?: 'United States',

            'business_name' => $submission->business_name
                ?: ($submission->employer_name ?: "{$submission->first_name} {$submission->last_name}"),
            'business_email' => $submission->business_email ?: $submission->email,
            'business_phone' => $this->portalPhone($submission->business_phone_no ?: $submission->employer_phone) ?: $phone,
            'business_address' => $submission->business_address,
            'business_city' => $submission->business_city,
            'business_state' => $submission->business_state,
            'business_postal_code' => $submission->business_postal_code,
            'business_country' => $submission->business_country,
            'business_country_code' => $submission->business_country_code,
            'business_ein' => $submission->business_ein,
            // The form's "Business Start Date"; the profile works years in business out from it.
            'business_start_date' => $submission->business_checking_start_date
                ? substr((string) $submission->getRawOriginal('business_checking_start_date'), 0, 10)
                : null,
            'currency' => $submission->currency,
            'business_entity_type' => $submission->entity_type,
            'business_industry_type' => $submission->industry_type,
            'number_of_employees' => $submission->number_of_employees,

            'monthly_revenue' => $submission->monthly_revenue,
            'annual_revenue' => $submission->annual_revenue,
            'current_business_debt' => $submission->has_business_debt ? $submission->debt_balance : null,
            'annual_personal_income' => $submission->annual_income,
            'fico_score' => $this->ficoScore($submission->self_reported_personal_credit_score)
                ?? $this->ficoScore($submission->credit_score),
        ];
    }

    /**
     * Fill only the blanks of an existing borrower's profile, quietly: this is
     * the conversion completing a record, not someone editing it.
     */
    protected function fillBlankProfileFields(Borrower $borrower, array $profile): void
    {
        $blanks = $this->blankProfileFields($borrower, $profile);

        if ($blanks === []) {
            return;
        }

        $borrower->forceFill($blanks)->save();

        // On the client's Activity: which fields, never their values (BUG-064).
        app(ActivityLogService::class)->logSystemEvent(
            Borrower::class,
            $borrower->id,
            $borrower->partner_id,
            $borrower->id,
            self::PROFILE_FILLED_EVENT,
            "Filled from an embedded form application, with the partner's consent: "
                . implode(', ', array_map(fn (string $field) => ActivityLog::fieldLabel($field), array_keys($blanks))) . '.',
        );
    }

    /**
     * Fill the blanks of the converted borrower's profile from the submission -
     * for submissions converted before conversions did this. Returns the names
     * of the fields filled (or that would be, on a dry run), never their values.
     *
     * @return list<string>
     */
    public function completeConvertedBorrowerProfile(EmbedFormSubmission $submission, bool $dryRun = false): array
    {
        $borrower = $submission->converted_borrower_id ? Borrower::find($submission->converted_borrower_id) : null;
        if (!$borrower) {
            return [];
        }

        $ssn = null;
        if ($submission->ssn_encrypted) {
            try {
                $ssn = Crypt::decryptString($submission->ssn_encrypted);
            } catch (Throwable $e) {
                $ssn = null;
            }
        }

        $blanks = $this->blankProfileFields($borrower, $this->borrowerProfileFrom($submission, $ssn));
        if (!$dryRun && $blanks !== []) {
            $borrower->forceFill($blanks)->save();
        }

        return array_keys($blanks);
    }

    /** The profile values whose field on the borrower is still blank. */
    private function blankProfileFields(Borrower $borrower, array $profile): array
    {
        $blanks = [];
        foreach ($profile as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $current = $field === 'ssn' ? $borrower->ssn : $borrower->getAttribute($field);
            // Only the last four of an SSN (older accounts) is not an SSN on file.
            $partialSsn = $field === 'ssn' && strlen((string) $this->ssnDigits($current)) !== 9;
            if ($current === null || $current === '' || $partialSsn || ($field === 'fico_score' && (int) $current === 0)) {
                $blanks[$field] = $value;
            }
        }

        // An address is filled whole or not at all: half the client's address
        // and half the submission's is no address (BUG-064, Texas + Miami).
        foreach (self::ADDRESS_GROUPS as $group) {
            $onFile = array_filter($group, fn (string $field) => !in_array($borrower->getAttribute($field), [null, ''], true));
            if ($onFile !== []) {
                $blanks = array_diff_key($blanks, array_flip($group));
            }
        }

        return $blanks;
    }

    /** An SSN as its digits; older submissions hold it written 123-45-6789. */
    private function ssnDigits(?string $ssn): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $ssn);

        return $digits === '' ? null : $digits;
    }

    /** The currency of a requested amount given without one: the form's first. */
    private function defaultCurrency(): string
    {
        return array_key_first(config('funding_application.embed_form.currencies'));
    }

    /** A FICO score is one number from 300 to 850; a range or anything else is not used. */
    private function ficoScore(mixed $value): ?int
    {
        $value = trim((string) $value);

        return preg_match('/^\d{3}$/', $value) && (int) $value >= 300 && (int) $value <= 850 ? (int) $value : null;
    }

    /**
     * A US phone the way the borrower and partner portals store it,
     * (512) 234-5678, whatever shape the public form sent (it sends
     * +15122345678). Anything that is not a US number is kept as it came.
     */
    private function portalPhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        return normalizePhoneE164($phone) ? formatPhoneDisplay($phone) : $phone;
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
            'full_name',
            'business_name',
            'business_email',
            'business_phone_no',
            'business_country',
            'business_country_code',
            'business_address',
            'business_city',
            'business_state',
            'business_postal_code',
            'industry_type',
            'entity_type',
            'years_in_business',
            'number_of_employees',

            // Ownership Information
            'first_name',
            'last_name',
            'date_of_birth',
            'citizenship_status',
            'street_address',
            'country',
            'city',
            'state',
            'zip_code',
            'ownership',
            'has_co_borrower',

            // Financial Information
            'monthly_revenue',
            'annual_revenue',
            'business_bank_name',
            'business_checking_start_date',
            'self_reported_personal_credit_score',
            'self_reported_business_credit_score',
            'has_business_debt',
            'debt_balance',

            // Funding Request
            'requested_amount', 'purpose_of_funds', 'purpose_note', 'approved_funding',
            'preferred_funding_type', 'additional_funding_types', 'timeframe_needed', 'collateral_available',

            // Disclosures and consent
            'credit_pull_authorization',
            'terms_conditions_acceptance',

            // Federal Compliance Disclosures
            'filed_for_bankruptcy',
            'any_pending_lawsuits',
            'has_federal_loan',
            'has_federal_debt',
            'has_criminal_offense',
            'has_parole_or_probation',
            'suspended_or_debarred',
            'any_outstanding_judgments_business',
            'any_outstanding_judgments_owner',
            'federal_compliance_disclosures_explanation',

            // Attestation and authorization
            'attestation_authorization',
            'signature_data',
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
            // Phones in the portals' (512) 234-5678 form, not the form's +1....
            'phone_number' => $this->portalPhone($submission->phone),
            'business_phone_no' => $this->portalPhone($submission->business_phone_no),
            // Nine digits, as the portals' own applications hold it.
            'ssn' => $this->ssnDigits($ssnDecrypted),
            'currency' => $submission->currency ?: $this->defaultCurrency(),
            'currency_symbol' => config('funding_application.embed_form.currencies.' . ($submission->currency ?: $this->defaultCurrency())),

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
        FundingApplication $application,
        bool $existingClient = false
    ): void {
        $notifier = app(Notifier::class);
        $staffData = [
            'name' => personName($submission->first_name ?? null, $submission->middle_name ?? null, $submission->last_name ?? null),
            'borrower_id' => $application->borrower_id,
        ];

        $staff = [Partner::where('is_host', 1)->first()];
        $partner = $submission->partner;
        if ($partner && !$partner->is_host) {
            $staff[] = $partner;
        }

        $mailed = [];
        foreach (array_filter($staff) as $recipient) {
            // One email per address, as before, even if host and partner share one.
            $email = !empty($recipient->email) && !in_array($recipient->email, $mailed, true) ? $recipient->email : null;
            $mailed[] = $email;

            // Each staff copy comes from its recipient's own company: the host's
            // from the host, the partner's from the partner.
            try {
                $notifier->send(
                    'partner.embed_form.converted',
                    $recipient,
                    $staffData,
                    mail: $email ? (new EmbedFormConverted($submission, $application, true, (int) $recipient->id, $existingClient))->to($email) : null
                );
            } catch (Throwable $e) {
                Log::error('Failed to notify staff of an embed form conversion', [
                    'submission_id' => $submission->id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $notifier->send(
                'borrower.embed_form.converted',
                $application->borrower,
                [],
                mail: !empty($submission->email) ? (new EmbedFormConverted($submission, $application, false, existingClient: $existingClient))->to($submission->email) : null
            );
        } catch (Throwable $e) {
            Log::error('Failed to send conversion notice to embed form applicant', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
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
                } catch (Throwable $e) {
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
                'mobile_phone' => $this->portalPhone($coBorrower->mobile_phone),
                'home_phone' => $this->portalPhone($coBorrower->home_phone),
                'date_of_birth' => $coBorrower->date_of_birth,
                'ssn' => $this->ssnDigits($ssn),
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
     * Move the submission's uploads into the funding application's documents.
     *
     * They go to the folder the borrower's and partner's own uploads use,
     * funding-application/{borrower}/FA-{application}, and are kept once: the
     * file is copied, the submission's attachment row is pointed at the copy,
     * and the original is deleted after the conversion commits (see
     * deleteMovedOriginals) - never before, so a rollback loses nothing.
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

            $destination = "funding-application/{$borrowerId}/FA-{$application->id}/"
                . Str::uuid() . ($extension ? '.' . $extension : '');

            $disk->copy($attachment->file_path, $destination);
            $this->movedDocuments[] = ['from' => $attachment->file_path, 'to' => $destination];

            // The submission still opens it, from where it now lives.
            $attachment->update(['file_path' => $destination]);

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

            $document = $this->addToClientDocuments(
                $destination, $attachment->original_name, $attachment->borrower_doc_type_id, $application->id, $borrowerId
            );
            $this->movedDocuments[array_key_last($this->movedDocuments)]['client']
                = "borrowers/{$borrowerId}/" . $document->getRawOriginal('file_path');
        }
    }

    /**
     * Put a document in the client's own documents (Clients > Details >
     * Documents), as an upload from that tab would be: the file copied to
     * borrowers/{borrower}/, the row holding just its name. Its own copy, so
     * deleting it from one place leaves the other. If the row cannot be
     * written, the copy is removed again.
     *
     * Also used by embed-form:relocate-converted-documents for submissions
     * converted before conversions did this.
     */
    public function addToClientDocuments(
        string $source,
        string $originalName,
        ?int $documentTypeId,
        int $applicationId,
        int $borrowerId
    ): BorrowerDocument {
        $disk = Storage::disk('public');
        $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: pathinfo($source, PATHINFO_EXTENSION);
        $fileName = Str::uuid() . ($extension ? '.' . $extension : '');
        $path = "borrowers/{$borrowerId}/{$fileName}";

        if (!$disk->copy($source, $path)) {
            throw new \RuntimeException("Could not copy {$source} to the client's documents.");
        }

        try {
            return BorrowerDocument::create([
                'borrower_id' => $borrowerId,
                'application_id' => $applicationId,
                'document_type_id' => $documentTypeId,
                'file_name' => $originalName,
                'file_path' => $fileName,
                'file_size' => $this->readableSize((int) $disk->size($path)),
                'status' => BorrowerDocument::STATUS_UPLOADED,
                'created_by' => auth()->id(),
            ]);
        } catch (Throwable $e) {
            $disk->delete($path);
            throw $e;
        }
    }

    /** The size the Documents tab shows, as BorrowerDocumentService writes it. */
    private function readableSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        return $bytes >= 1024 ? round($bytes / 1024, 2) . ' KB' : $bytes . ' bytes';
    }

    /**
     * After the conversion commits: delete the embed form originals of the
     * moved uploads, and the submission's upload folder once it is empty. A
     * delete that fails leaves a stray file, logged, not a failed conversion.
     */
    protected function deleteMovedOriginals(EmbedFormSubmission $submission): void
    {
        $disk = Storage::disk('public');

        foreach ($this->movedDocuments as $moved) {
            if (!$disk->delete($moved['from'])) {
                Log::warning(__('embed_form.log_funding_application_failed'), [
                    'submission_id' => $submission->id,
                    'reason' => 'embed form upload not deleted after the move',
                ]);
            }
        }

        $folder = "embed_forms/{$submission->partner_id}/{$submission->id}";
        if ($this->movedDocuments !== [] && $disk->allFiles($folder) === []) {
            $disk->deleteDirectory($folder);
        }

        $this->movedDocuments = [];
    }

    /** After a rollback: the copies belong to an application that does not exist. */
    protected function deleteMovedCopies(): void
    {
        $disk = Storage::disk('public');

        foreach ($this->movedDocuments as $moved) {
            $disk->delete(array_filter([$moved['to'], $moved['client'] ?? null]));
        }

        $this->movedDocuments = [];
    }

    /**
     * Securely download an embed form attachment with tenant RBAC scoping.
     * Ensures auth user partner_id and form partner_id strictly match (unless host admin).
     */
    public function downloadAttachment(int $id): StreamedResponse|JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return ApiResponse::unauthorized();
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

