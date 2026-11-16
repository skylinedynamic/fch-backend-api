<?php

namespace App\Services\FCH\Partner;

use App\Services\FCH\Common\Notification\Notifier;
use App\Models\User\User;
use App\Models\Common\Role;
use Illuminate\Support\Str;
use App\Helpers\ApiResponse;
use App\Traits\PaginatesApi;
use App\Traits\UploadsFiles;
use Illuminate\Http\Request;
use App\Models\Invoice\Invoice;
use App\Models\Appointment\Appointment;
use App\Models\SupportTicket\SupportTicket;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerCommissionTier;
use Illuminate\Validation\ValidationException;
use Throwable;
use App\Models\Borrower\Borrower;
use App\Models\Common\Permission;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FCH\Common\Invoice\InvoiceService;
use Illuminate\Support\Facades\Auth;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\ActivityLog\ActivityLog;
use Spatie\Permission\PermissionRegistrar;
use App\Mail\Partner\PartnerPaymentLinkMail;
use App\Mail\Partner\PartnerMigrationMail;
use App\Mail\Trial\PartnerTrialMail;
use App\Mail\Trial\PartnerTrialExpiryUpdatedMail;
use App\Services\Global\Datatable\QueryFilter;
use App\Mail\Partner\PartnerAccountUpdateMail;
use App\Http\Resources\FCH\Partner\PartnerResource;
use App\Services\FCH\Subscription\EnrollmentService;
use App\Services\FCH\Subscription\SubscriptionService;
use App\Http\Resources\FCH\Borrower\BorrowerPartnerResource;
use DateTimeInterface;
use App\Support\Files\Disk;

class PartnerService
{
    use PaginatesApi, UploadsFiles;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected SubscriptionService $subscriptionService,
        protected EnrollmentService $enrollmentService,
        protected \App\Services\FCH\Common\ActivityLog\ActivityLogService $activityLogService,
        protected \App\Services\Global\Account\EmailChangeService $emailChange,
        protected \App\Services\FCH\Common\Commission\CommissionTierResolver $commissionTiers,
    ) {
    }



    /**
     * Send free trial welcome email using partner's own email config.
     */
    private function sendTrialWelcomeEmail(array $mailData, int $hostPartnerId): void
    {
        try {
            app(Notifier::class)->send('partner.account.trial_started', $mailData['user'], ['date' => $this->trialDate($mailData)],
                mail: (new PartnerTrialMail($mailData, $hostPartnerId))->to($mailData['user']->email));
        } catch (\Exception $e) {
            Log::error(__('partner.log.trial_welcome_mail_failed'), [
                'partner_id' => $mailData['partner_id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send account update email using partner's own email config.
     */
    private function sendAccountUpdateEmail(array $mailData, int $hostPartnerId): void
    {
        try {
            app(Notifier::class)->send('partner.account.login_updated', $mailData['user'],
                mail: (new PartnerAccountUpdateMail($mailData, $hostPartnerId))->to($mailData['user']->email));
        } catch (\Exception $e) {
            Log::error(__('partner.log.account_update_mail_failed'), [
                'partner_id' => $mailData['partner_id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send free trial expiry date updated email using partner's own email config.
     */
    private function sendTrialExpiryUpdatedEmail(array $mailData, ?int $hostPartnerId = null): void
    {
        try {
            app(Notifier::class)->send('partner.account.trial_extended', $mailData['user'], ['date' => $this->trialDate($mailData)],
                mail: (new PartnerTrialExpiryUpdatedMail($mailData, $hostPartnerId))->to($mailData['user']->email));
        } catch (\Throwable $e) {
            Log::error(__('partner.log.trial_expiry_mail_failed'), [
                'partner_id' => $mailData['partner_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** The trial end date as the bell shows it (the column may be cast to Carbon). */
    private function trialDate(array $mailData): string
    {
        $date = $mailData['trial_end_date'] ?? null;

        return $date instanceof DateTimeInterface ? $date->format('M j, Y') : ((string) $date ?: 'N/A');
    }

    /**
     * Field mapping for user table fields.
     */
    private array $userFieldMapping = [
        'full_name' => 'name',
        'email' => 'email',
        'phone_number' => 'phone_number',
        'city' => 'city',
        'state' => 'state',
        'postal_code' => 'postal_code',
        'country' => 'country',
        'address_line_1' => 'address_line_1',
        'address_line_2' => 'address_line_2',
    ];

    /**
     * Field mapping for partner table fields.
     */
    private array $partnerFieldMapping = [
        'full_name' => 'full_name',
        'company_name' => 'company_name',
        'email' => 'email',
        'phone_number' => 'phone_number',
        'contact_person' => 'contact_person',
        'contact_person_email' => 'contact_person_email',
        'contact_person_phone_number' => 'contact_person_phone_number',
        'contact_person_relation' => 'contact_person_relation',
        'partner_type' => 'partner_type',
        'status' => 'status',
        'state' => 'state',
        'city' => 'city',
        'address_line_1' => 'address_line_1',
        'postal_code' => 'postal_code',
        'address_line_2' => 'address_line_2',
        'country' => 'country',
        'country_code' => 'country_code',
        'joining_date' => 'joining_date',
        'company_logo' => 'company_logo',
        'tax_id' => 'tax_id',
        'website' => 'website',
        'partner_image' => 'partner_image',
        'partner_domain' => 'partner_domain',
        'borrower_domain' => 'borrower_domain',
        'assign_to' => 'assign_to',
        'is_free_trail' => 'is_free_trail',
        'free_trail_end_date' => 'free_trail_end_date',
    ];

    /**
     * Build the business_info array from the host partner (or app config fallback).
     */
    private function getHostPartnerBusinessInfo(?Partner $hostPartner): array
    {
        return [
            'company_name' => $hostPartner->company_name ?? config('app.name'),
            'email' => $hostPartner->email ?? config('mail.from.address'),
            'phone_number' => $hostPartner->phone_number ?? config('app.phone_number', ''),
            'address_line_1' => $hostPartner->address_line_1 ?? config('app.address', ''),
            'company_logo' => $hostPartner->company_logo ?? 'logo.png',
        ];
    }

    /**
     * Create a new partner.
     */
    public function store(array $data): array
    {
        DB::beginTransaction();
        try {
            // Create or update user
            $userResult = $this->createOrUpdateUser($data);
            $user = $userResult['user'];
            $plainPassword = $userResult['plain_password'];
            $isNew = $userResult['is_new'];

            $partnerData = $this->mapPartnerData($data);
            $isFreeTrial = isset($data['is_free_trail']) && ($data['is_free_trail'] == 1 || $data['is_free_trail'] === true || $data['is_free_trail'] === 'true');
            $partnerData['status'] = $isFreeTrial ? Partner::STATUS_ACTIVE : Partner::STATUS_ONBOARDING;

            $baseUrl = preg_replace('/^https?:\/\//', '', config('app.base_website'));
            // Generate subdomains if not provided
            if (empty($partnerData['partner_domain']) && !empty($partnerData['company_name'])) {
                $subdomain = $this->generateSubdomain($partnerData['company_name']);

                // Check against restricted domains OR existing subdomains
                if (in_array($subdomain, Partner::RESTRICTED_DOMAINS) || Partner::where('partner_domain', $subdomain . '.' . $baseUrl)->exists()) {
                    $subdomain .= '-' . $user->id;
                }

                $partnerData['partner_domain'] = $subdomain . '.' . $baseUrl;
                $partnerData['partner_subdomain'] = $partnerData['partner_domain'];
            } else {
                // Append base url to provided subdomain
                $partnerData['partner_domain'] = $partnerData['partner_domain'] . '.' . $baseUrl;
                $partnerData['partner_subdomain'] = $partnerData['partner_domain'];
            }

            $partner = Partner::create($partnerData);

            // Log Partner Creation
            $this->activityLogService->logModelEvent($partner, ActivityLog::LOG_TYPE_PARTNER_CREATE);
            // Handle partner_image upload
            if (isset($partnerData['partner_image'])) {
                $imageName = ltrim($partnerData['partner_image'], '/');
                $partnerData['partner_image'] = 'partners/' . $partner->id . '/' . $imageName;
                $partner->update(['partner_image' => $partnerData['partner_image']]);
            }

            // Update user with partner link
            $user->partner_id = $partner->id;
            $user->entity_type = Partner::class;
            $user->entity_id = $partner->id;
            $user->save();

            $this->createPartnerRoles($partner->id, $user);

            if (isset($data['is_free_trail']) && $data['is_free_trail']) {
                $hostPartner = Partner::where('is_host', 1)->first();
                $mailData = [
                    'partner_id' => $partner->id,
                    'user' => $user,
                    'plain_password' => $plainPassword,
                    'trial_end_date' => $data['free_trail_end_date'] ?? null,
                    'business_info' => $this->getHostPartnerBusinessInfo($hostPartner),
                ];

                $authUser = Auth::user();
                $senderPartnerId = $authUser ? $authUser->partner_id : ($hostPartner ? $hostPartner->id : null);
                $this->sendTrialWelcomeEmail($mailData, $senderPartnerId);

                $trialEndDate = $data['free_trail_end_date'] ?? null;
                $this->activityLogService->logModelEvent(
                    $partner,
                    ActivityLog::LOG_TYPE_PARTNER_FREE_TRIAL,
                    [],
                    null,
                    __('partner.free_trial_created_log', ['date' => $trialEndDate ?? 'N/A'])
                );
            } else {
                // ── PAID / STANDARD ENROLLMENT ──────────────────────────────────────────
                $enrollmentData = $data['enrollment'] ?? [];
                $enrollmentData['partner_type'] = $data['partner_type'] ?? $partner->partner_type;

                $this->enrollmentService->enrollPartner($partner, $user, $enrollmentData, $plainPassword);
            }

            DB::commit();

            return [
                'partner' => $partner->fresh(),
                'user' => $user->fresh(),
                'plain_password' => $plainPassword,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error(__('partner.log.partner_create_failed'), ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Create default roles (Admin and Borrower) for a new partner.
     * Assigns the Admin role to the partner's user.
     * Permissions are filtered based on partner's is_host status.
     */
    private function createPartnerRoles(int $partnerId, User $user): void
    {
        try {
            // Clear cached permissions before creating roles
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            // Get partner to check is_host status
            $partner = Partner::find($partnerId);

            if (!$partner) {
                throw new \Exception("Partner with ID {$partnerId} not found");
            }

            if ($partner->partner_type == Partner::PARTNER_TYPE_CERTIFIED) {

                $allowedPermissions = Permission::where('guard_name', 'api')
                    ->where('is_host', 0)
                    ->where('is_certified', 1)
                    ->get();
            } else {

                $allowedPermissions = Permission::where('guard_name', 'api')
                    ->where('is_host', 0)
                    ->get();
            }

            if ($allowedPermissions->isEmpty()) {
                Log::warning('No API permissions found. Roles will be created without permissions.');
            }

            // Create or update Admin role with filtered permissions
            // Using updateOrCreate to avoid duplicate role name errors (partner-wise roles)
            $adminRole = Role::updateOrCreate(
                ['name' => 'Admin', 'guard_name' => 'api', 'partner_id' => $partnerId],
                [
                    'name' => 'Admin',
                    'guard_name' => 'api',
                    'partner_id' => $partnerId,
                    'code' => 'admin',
                    'is_static' => true,
                ]
            );

            // Assign Admin role to the partner's user
            $user->assignRole($adminRole);

            // Also sync permissions directly to user (like EmployeeService)
            if ($allowedPermissions->isNotEmpty()) {
                $adminRole->syncPermissions($allowedPermissions);
                $user->syncPermissions($allowedPermissions);
            }

            // Clear cached permissions after role creation
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Exception $e) {
            Log::error('Failed to create partner roles', [
                'partner_id' => $partnerId,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Update an existing partner.
     */
    public function update(Partner $partner, array $data): Partner
    {
        DB::beginTransaction();
        try {
            // Handle partner_image upload

            if (isset($data['partner_image'])) {

                $imageName = ltrim($data['partner_image'], '/');

                $data['partner_image'] = 'partners/' . $partner->id . '/' . $imageName;
            }

            // The partner's own login, via its entity link. It used to fall back
            // to "whichever account has the submitted email", which could reach
            // an unrelated account (e.g. one of the partner's employees) and
            // overwrite its name, phone and password.
            $user = $partner->partnerUser;

            // With a login, a new email is confirmed from the new address before
            // either record changes; without one it applies directly.
            $newEmail = $this->emailChange->takeFrom($data, $user, $partner->email);
            $wasFreeTrial = (bool) $partner->is_free_trail;
            $isFreeTrialInput = isset($data['is_free_trail']) ? (bool) $data['is_free_trail'] : false;

            if ($user) {
                $userResult = $this->createOrUpdateUser($data, $user);
                $updatedUser = $userResult['user'];
                $plainPassword = $userResult['plain_password'];
                $isNew = $userResult['is_new'];

                if ($plainPassword) {
                    $mailData = [
                        'partner_id' => $partner->id,
                        'user' => $updatedUser,
                        'plain_password' => $plainPassword,
                        'business_info' => [
                            'company_name' => config('app.name'),
                            'email' => config('mail.from.address'),
                            'phone_number' => config('app.phone_number', ''),
                            'address_line_1' => config('app.address', ''),
                            'company_logo' => 'logo.png',
                        ],
                        'is_update' => true,
                    ];
                    $authUser = Auth::user();
                    $senderPartnerId = $authUser ? $authUser->partner_id : Partner::where('is_host', 1)->value('id');
                    $this->sendAccountUpdateEmail($mailData, $senderPartnerId);
                }
            }

            // Update partner
            $oldStatus = $partner->status;
            $partnerData = $this->mapPartnerData($data);

            // free_trail_end_date is a DATE column and is now serialised verbatim
            // through toDateOnly(), so the value the edit form reposts is the same
            // date that was stored. It is safe to update again.
            if ($wasFreeTrial && !$isFreeTrialInput) {
                $partnerData['status'] = Partner::STATUS_ONBOARDING;
            }

            if ($partner->partner_type === Partner::PARTNER_TYPE_CERTIFIED && !empty($partnerData['partner_domain'])) {
                $subdomain = trim($partnerData['partner_domain']);
                $baseUrl = preg_replace('/^https?:\/\//', '', config('app.base_website'));
                if (!str_ends_with($subdomain, '.' . $baseUrl)) {
                    $partnerData['partner_domain'] = $subdomain . '.' . $baseUrl;
                    $partnerData['partner_subdomain'] = $partnerData['partner_domain'];
                }
            }

            $oldTrialEndDate = $partner->free_trail_end_date;
            $partner->update($partnerData);

            $this->syncUsersWithStatus($partner, $oldStatus);

            $changes = $partner->getChanges();

            unset($changes['updated_at']);

            if (isset($changes['email']) && strtolower(trim((string) $partner->getOriginal('email'))) === strtolower(trim((string) $changes['email']))) {
                unset($changes['email']);
            }

            if (!empty($changes)) {
                $extraProps = [];
                if (isset($changes['email'])) {
                    $extraProps['new_email'] = $changes['email'];
                }

                $isEmailOnly = (count($changes) === 1 && isset($changes['email']));
                $contactFields = ['email', 'phone_number', 'contact_person', 'contact_person_email', 'contact_person_phone_number', 'contact_person_relation'];

                $changedKeys = array_keys($changes);
                $isContactOnly = count(array_diff($changedKeys, $contactFields)) === 0;

                $logType = ActivityLog::LOG_TYPE_PARTNER_UPDATE;
                if ($isEmailOnly) {
                    $logType = ActivityLog::LOG_TYPE_EMAIL_UPDATE;
                } elseif ($isContactOnly) {
                    $logType = ActivityLog::LOG_TYPE_PARTNER_CONTACT_UPDATE;
                }

                $enrollmentType = $data['enrollment']['enrollment_type'] ?? null;
                $defaultEnrollmentType = __('partner.default_enrollment_type');
                $transitionMsg = ($wasFreeTrial && !$isFreeTrialInput)
                    ? __('partner.free_trial_converted_log', ['enrollment_type' => $enrollmentType ? $enrollmentType : $defaultEnrollmentType])
                    : null;

                $changedFields = [];
                foreach ($changes as $field => $newValue) {
                    if ($transitionMsg && in_array($field, ['is_free_trail', 'free_trail_end_date', 'status'])) {
                        continue;
                    }

                    // The partner's Agent, by name rather than user id
                    if ($field === 'assign_to') {
                        $owner = $this->activityLogService->describeOwnerChange(
                            __('activity_log.owner_label_partner'),
                            $partner->getPrevious()['assign_to'] ?? null,
                            $newValue
                        );
                        $changedFields[] = $owner['sentence'];
                        $extraProps['changes']['assign_to'] = $owner['change'];
                        continue;
                    }

                    // An EIN or account number is recorded as changed, never repeated
                    if (ActivityLog::isSensitiveField($field)) {
                        $changedFields[] = __('activity_log.sensitive_field_updated', ['field' => ActivityLog::fieldLabel($field)]);
                        continue;
                    }

                    $name = $field === 'free_trail_end_date' ? __('partner.trial_end_date') : ucwords(str_replace('_', ' ', $field));

                    if ($field === 'full_name') {
                        $val = (string) $newValue;
                        $val = $val === '' ? __('partner.empty_value') : $val;
                        $changedFields[] = __('partner.name_updated', ['value' => $val]);
                    } else {
                        $val = (string) $newValue;
                        $val = $val === '' ? __('partner.empty_value') : $val;
                        $changedFields[] = __('partner.field_updated', ['field' => $name, 'value' => $val]);
                    }
                }

                if ($transitionMsg) {
                    $customDescription = !empty($changedFields)
                        ? "{$transitionMsg}. " . implode(', ', $changedFields) . "."
                        : "{$transitionMsg}.";
                } else {
                    $fieldsList = implode(', ', $changedFields);
                    $customDescription = $isEmailOnly ? null : "{$fieldsList}.";
                }

                // Only the Agent changed: an entry saying just that
                $customSubject = array_keys($changes) === ['assign_to'] ? __('activity_log.owner_subject_partner') : null;

                $this->activityLogService->logModelEvent(
                    $partner,
                    $logType,
                    $extraProps,
                    $customSubject,
                    $customDescription
                );
            }

            // Update subscription if custom amounts or enrollment details are provided
            $subscription = $partner->subscription;
            $isFreeTrialInput = isset($data['is_free_trail']) ? (bool) $data['is_free_trail'] : false;

            if ($wasFreeTrial && !$isFreeTrialInput) {
                // Transition off free trial
                // ── PAID / MIGRATION ENROLLMENT ────────────────────────────────────────
                $enrollmentData = $data['enrollment'] ?? [];
                $enrollmentData['partner_type'] = $data['partner_type'] ?? $partner->partner_type;

                $this->enrollmentService->enrollPartner($partner, $user, $enrollmentData, isMigration: true);
            }

            $user?->update(['is_refresh' => true]);
            DB::commit();

            if ($newEmail) {
                $this->emailChange->request($user, $newEmail);
            }

            // Send trial expiry updated email if free trial expiry date was updated
            $isFreeTrialNow = (bool) ($partner->is_free_trail ?? false);
            if ($isFreeTrialNow && array_key_exists('free_trail_end_date', $changes) && !empty($partner->free_trail_end_date)) {
                $hostPartner = Partner::where('is_host', 1)->first();
                $mailUser = $partner->partnerUser ?? $user ?? User::where('email', $partner->email)->first();

                if ($mailUser && !empty($mailUser->email)) {
                    $mailData = [
                        'partner_id' => $partner->id,
                        'partner' => $partner,
                        'user' => $mailUser,
                        'trial_end_date' => $partner->free_trail_end_date,
                        'old_trial_end_date' => $oldTrialEndDate,
                        'business_info' => $this->getHostPartnerBusinessInfo($hostPartner),
                    ];

                    $authUser = Auth::user();
                    $senderPartnerId = $authUser ? $authUser->partner_id : ($hostPartner ? $hostPartner->id : null);
                    // TEMPORARY CONTAINMENT - trial expiry "date changed" email disabled.
                    // Every save used to shift the trial date by a day, so this fired on
                    // edits that never touched the date and told the customer their trial
                    // had moved. Updates can no longer change the date (see the unset in
                    // update()), so this is already unreachable - disabled explicitly as
                    // well so no wrong notification can go out. Restore together with the
                    // date-handling fix.
                    // $this->sendTrialExpiryUpdatedEmail($mailData, $senderPartnerId);
                }

                $this->activityLogService->logModelEvent(
                    $partner,
                    ActivityLog::LOG_TYPE_PARTNER_FREE_TRIAL,
                    [
                        'old_trial_end_date' => $oldTrialEndDate ? \Carbon\Carbon::parse($oldTrialEndDate)->format('Y-m-d') : null,
                        'new_trial_end_date' => \Carbon\Carbon::parse($partner->free_trail_end_date)->format('Y-m-d'),
                    ],
                    null,
                    __('partner.free_trial_expiry_updated_log', [
                        'date' => \Carbon\Carbon::parse($partner->free_trail_end_date)->format('F j, Y'),
                    ])
                );
            }

            return $partner->fresh();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error(__('partner.log.partner_update_failed'), [
                'partner_id' => $partner->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Set a partner's Funding commission tiers by funded-deal count. Each tier
     * has its own type: a share (%) of the success fee or a flat amount ($) per
     * deal, and keeps only that type's value. At least one tier (the request
     * requires it). The host partner has none: the admin keeps the whole fee on
     * the host's own clients.
     *
     * @param array<int, array{min_count:int|string, max_count:int|string|null, commission_type?:string|null, percentage?:float|string|null, amount?:float|string|null}> $tiers
     * @throws ValidationException for the host partner
     */
    public function updateCommission(Partner $partner, array $tiers): Partner
    {
        if ($partner->is_host) {
            throw ValidationException::withMessages([
                'tiers' => __('partner.validation.commission.host'),
            ]);
        }

        $before = $this->commissionSummary(self::tierRows($partner->commissionTiers()->get()));

        $normalized = array_map(function (array $tier) {
            $flat = ($tier['commission_type'] ?? null) === PartnerCommissionTier::TYPE_FLAT;

            return [
                'min_count' => (int) $tier['min_count'],
                'max_count' => ($tier['max_count'] ?? '') === '' ? null : (int) $tier['max_count'],
                'commission_type' => $flat ? PartnerCommissionTier::TYPE_FLAT : PartnerCommissionTier::TYPE_PERCENTAGE,
                'percentage' => $flat ? null : (float) $tier['percentage'],
                'amount' => $flat ? (float) $tier['amount'] : null,
            ];
        }, array_values($tiers));

        DB::transaction(function () use ($partner, $normalized) {
            $partner->commissionTiers()->delete();

            foreach ($normalized as $i => $tier) {
                $partner->commissionTiers()->create($tier + ['sort_order' => $i]);
            }
        });

        $this->logCommissionChange($partner, $before, (string) $this->commissionSummary($normalized));

        return $partner->load('commissionTiers');
    }

    /**
     * The partner's commission as the Commissions tab reads it: its tiers and
     * how many of its clients' funding applications have been funded, so the
     * tab can say which tier the next deal falls in.
     */
    public function commissionOf(Partner $partner): array
    {
        return [
            'id' => $partner->id,
            'commission_tiers' => self::tierRows($partner->commissionTiers),
            'funded_deal_count' => $this->commissionTiers->partnerFundedCount($partner),
        ];
    }

    /**
     * Tiers as the API returns them: each with its commission_type and that
     * type's value (`percentage` or `amount`), the other null.
     *
     * @return array<int, array{id:int, min_count:int, max_count:?int, commission_type:string, percentage:?float, amount:?float}>
     */
    public static function tierRows($tiers): array
    {
        return collect($tiers)->map(fn ($tier) => [
            'id' => $tier->id,
            'min_count' => $tier->min_count,
            'max_count' => $tier->max_count,
            'commission_type' => $tier->commission_type ?: PartnerCommissionTier::TYPE_PERCENTAGE,
            'percentage' => $tier->percentage === null ? null : (float) $tier->percentage,
            'amount' => $tier->amount === null ? null : (float) $tier->amount,
        ])->values()->all();
    }

    /**
     * Tiers in a line for the activity log: "25%" or "$500" when one tier
     * covers every deal, otherwise "0-10: $1,500, 11+: 27.5%". Null when there
     * are none.
     */
    private function commissionSummary(array $tiers): ?string
    {
        if ($tiers === []) {
            return null;
        }

        $value = static fn (array $tier): string => ($tier['commission_type'] ?? null) === PartnerCommissionTier::TYPE_FLAT
            ? '$' . preg_replace('/\.00$/', '', number_format((float) $tier['amount'], 2))
            : rtrim(rtrim(number_format((float) $tier['percentage'], 2, '.', ''), '0'), '.') . '%';

        if (count($tiers) === 1 && $tiers[0]['min_count'] === 0 && $tiers[0]['max_count'] === null) {
            return $value($tiers[0]);
        }

        return collect($tiers)->map(fn ($tier) => ($tier['max_count'] === null
            ? "{$tier['min_count']}+"
            : "{$tier['min_count']}-{$tier['max_count']}") . ': ' . $value($tier))->implode(', ');
    }

    /**
     * Partner profile › Commissions: an entry on the partner's activity log -
     * "Commission added / updated", old → new in its "Show changes" table. Host staff only (internal). Nothing when the tiers did not change.
     * A failure is logged, never thrown: the commission is already saved.
     */
    private function logCommissionChange(Partner $partner, ?string $before, string $after): void
    {
        if ($before === $after) {
            return;
        }

        $by = Auth::user()?->name ?: 'The host';

        [$event, $description] = match (true) {
            $before === null => [__('partner.activity.commission_added'), __('partner.activity.commission_added_description', ['user' => $by, 'to' => $after])],
            default => [__('partner.activity.commission_updated'), __('partner.activity.commission_updated_description', ['user' => $by, 'from' => $before, 'to' => $after])],
        };

        try {
            $this->activityLogService->logSystemEvent(
                Partner::class,
                $partner->id,
                $partner->id,
                null,
                $event,
                $description,
                ActivityLog::LOG_TYPE_PARTNER_COMMISSION_UPDATE,
                ['changes' => ['commission' => [
                    'label' => __('partner.activity.commission_label'),
                    'from' => $before,
                    'to' => $after,
                ]]],
                ActivityLog::VISIBILITY_INTERNAL
            );
        } catch (Throwable $e) {
            Log::error('Failed to log a partner commission change', ['partner_id' => $partner->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A partner's status and agent, as the Status & Agent tab shows them.
     */
    public function findStatusAgent(int $id): ?Partner
    {
        return Partner::with('assignedUser')->find($id);
    }

    /**
     * Save the Status & Agent tab. Only status and agent change, so the save is
     * never held up by the partner's other details (an old phone number, ...).
     * A suspended or inactive partner's login is signed out, as on any status change.
     */
    public function updateStatusAgent(Partner $partner, array $data): Partner
    {
        return DB::transaction(function () use ($partner, $data) {
            $oldStatus = $partner->status;
            $oldAgent = $partner->assign_to;

            $partner->update([
                'status' => $data['status'],
                'assign_to' => $data['assign_to'],
            ]);

            $this->syncUsersWithStatus($partner, $oldStatus);

            $changes = $partner->getChanges();
            unset($changes['updated_at']);

            if (!empty($changes)) {
                $changedFields = [];
                $extraProps = [];

                if (array_key_exists('status', $changes)) {
                    $changedFields[] = __('partner.field_updated', ['field' => 'Status', 'value' => $partner->status]);
                }

                if (array_key_exists('assign_to', $changes)) {
                    $owner = $this->activityLogService->describeOwnerChange(
                        __('activity_log.owner_label_partner'),
                        $oldAgent,
                        $partner->assign_to
                    );
                    $changedFields[] = $owner['sentence'];
                    $extraProps['changes']['assign_to'] = $owner['change'];
                }

                $this->activityLogService->logModelEvent(
                    $partner,
                    ActivityLog::LOG_TYPE_PARTNER_UPDATE,
                    $extraProps,
                    array_keys($changes) === ['assign_to'] ? __('activity_log.owner_subject_partner') : null,
                    implode(', ', $changedFields) . '.'
                );
            }

            $partner->partnerUser?->update(['is_refresh' => true]);

            return $partner->fresh('assignedUser');
        });
    }

    /**
     * Signs a partner's login out when it becomes suspended or inactive.
     */
    private function syncUsersWithStatus(Partner $partner, ?string $oldStatus): void
    {
        if ($partner->status === $oldStatus) {
            return;
        }

        if (in_array($partner->status, [Partner::STATUS_SUSPENDED, Partner::STATUS_INACTIVE])) {
            $this->restrictPartnerUsers($partner);
        } elseif ($partner->status === Partner::STATUS_ACTIVE) {
            $this->reactivatePartnerUsers($partner);
        }
    }

    /**
     * Find a partner by ID.
     */
    public function findById(int $id): ?Partner
    {
        return Partner::with('partnerUser')->find($id);
    }

    /**
     * Get partner details by ID with all relationships.
     */
    public function getDetails(int $id): ?Partner
    {
        return Partner::with(['assignedUser', 'partnerUser', 'subscription'])
            ->find($id);
    }

    /**
     * Create or update user based on email.
     */
    private function createOrUpdateUser(array $data, ?User $existingUser = null): array
    {
        $userData = $this->mapUserData($data);
        $plainPassword = null;

        if ($existingUser) {
            // A login's email never changes here — update() hands a new one to
            // EmailChangeService — so there's no password to reset.
            $plainPassword = null;

            $userData['is_refresh'] = true;
            $existingUser->update($userData);

            return ['user' => $existingUser, 'plain_password' => $plainPassword, 'is_new' => false];
        }

        // Check if user exists by email
        // nomal data update
        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $userData['is_refresh'] = true;
            $user->update($userData);

            return ['user' => $user, 'plain_password' => null, 'is_new' => false];
        }

        // Create new user
        $plainPassword = bin2hex(random_bytes(4));
        $userData['password'] = bcrypt($plainPassword);
        $userData['user_type'] = User::PARTNER;

        $user = User::create($userData);

        return ['user' => $user, 'plain_password' => $plainPassword, 'is_new' => true];
    }

    /**
     * Map request data to user model attributes.
     */
    private function mapUserData(array $data): array
    {
        $userData = [];

        foreach ($this->userFieldMapping as $requestField => $userField) {
            if (array_key_exists($requestField, $data)) {
                $userData[$userField] = $data[$requestField];
            }
        }

        return $userData;
    }

    /**
     * Every partner for a filter dropdown - the host and archived partners
     * included, so records filed under them stay filterable - sorted by name.
     * Unpaginated and just the columns a dropdown shows; the Partners table
     * uses list() instead.
     */
    public function options()
    {
        return Partner::query()
            ->select(['id', 'full_name', 'company_name'])
            ->orderBy('full_name')
            ->orderBy('id')
            ->get();
    }

    /**
     * List partners with pagination, search, filtering and sorting.
     */
    public function list(array $filters)
    {
        // Get authenticated user to determine filter logic
        $authUser = auth()->user();

        // Base query: Start from Partner model with User eager loading
        $query = Partner::query()
            ->with([
                'assignedUser' => function ($userQuery) {
                    $userQuery->select('id', 'name');
                },
                'partnerUser' => function ($userQuery) {
                    $userQuery->select('id', 'entity_type', 'entity_id');
                },
                'subscription'
            ]);

        // Snapshot the query before any user-facing filter is applied, so the
        // summary cards keep showing true totals instead of recounting whatever the
        // table is currently filtered to. Archived partners are excluded: they are
        // not active business. The host's own row stays in the table but is not
        // one of its partners, so it is not counted.
        $statsQuery = (clone $query)->notArchived()->excludingHost();

        // Filter based on authenticated user's host status
        // if ($authUser && $authUser->partner && $authUser->partner->is_host == 1) {
        //     // If logged-in user is a host, show only non-host partners
        //     $query->where('is_host', '!=', 1);
        // } else {
        //     // Otherwise, show only host partners
        //     $query->where('is_host', 1);
        // }

        // 🔍 Filter by partner_id (exact match)
        if (!empty($filters['partner_id'])) {
            $query->where('partners.id', $filters['partner_id']);
        }

        // 🔍 Filter by assigned_user_id (exact match)
        if (!empty($filters['assigned_user_id'])) {
            $query->where('partners.assign_to', $filters['assigned_user_id']);
        }

        // 🔍 Filter by the partner's own status — the value the Status column
        // shows. Partner::STATUS is the full set: Active, Inactive, Suspended and
        // OnBoarding.
        if (!empty($filters['status'])) {
            $query->where('partners.status', $filters['status']);
        }

        // 🔍 Filter by trial status (Partner::TRIAL_STATUS). The scope owns the
        // rules so this list and the Trial badge on the row cannot disagree about
        // what "Expired" means.
        $query->trialFilter($filters['is_free_trail'] ?? null);

        // 🔍 Search filter (uses scopeSearch from Partner model)
        if (!empty($filters['search'])) {
            $query->search(trim((string) $filters['search']));
        }

        // 🗄 Archived records leave the working list but stay findable. A search
        // reaches them by default; `archived` narrows to them, `archived=all`
        // ignores the distinction entirely.
        // '' and null both mean "no archive filter chosen" - the UI sends an empty
        // string, not null, so both have to be treated the same or a search would
        // never reach archived records.
        $archived = $filters['archived'] ?? null;
        $archived = ($archived === '' ? null : $archived);

        if ($archived === null && !empty($filters['search'])) {
            $archived = Partner::ARCHIVE_FILTER_ALL;
        }

        $query->archiveFilter($archived);

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $skipSorting = false;

        // Handle special field aliases
        if ($sortField === 'assigned_user_name') {
            // Join users table to sort by name
            $query->leftJoin('users as assigned_users', 'partners.assign_to', '=', 'assigned_users.id')
                ->orderBy('assigned_users.name', $sortOrder)
                ->select('partners.*'); // Ensure we still only select partner fields

            $skipSorting = true;
        }

        // 🔍 Filter by assigned_user_name (related user name)
        if (isset($filters['filters']['assigned_user_name'])) {
            $assignedUserFilter = $filters['filters']['assigned_user_name'];
            // Check if it has constraints
            if (isset($assignedUserFilter['constraints']) && is_array($assignedUserFilter['constraints'])) {
                $hasActiveConstraint = false;
                foreach ($assignedUserFilter['constraints'] as $c) {
                    if (!empty($c['value'])) {
                        $hasActiveConstraint = true;
                        break;
                    }
                }

                if ($hasActiveConstraint) {
                    $query->whereHas('assignedUser', function ($q) use ($assignedUserFilter) {
                        $q->where(function ($subQ) use ($assignedUserFilter) {
                            foreach ($assignedUserFilter['constraints'] as $constraint) {
                                $value = $constraint['value'] ?? null;
                                $matchMode = $constraint['matchMode'] ?? 'contains';

                                if ($value) {
                                    // Apply logic based on matchMode
                                    $likeContains = "%{$value}%";
                                    $likeStarts = "{$value}%";
                                    $likeEnds = "%{$value}";

                                    if ($matchMode === 'contains') {
                                        $subQ->where('name', 'like', $likeContains);
                                    } elseif ($matchMode === 'startsWith') {
                                        $subQ->where('name', 'like', $likeStarts);
                                    } elseif ($matchMode === 'endsWith') {
                                        $subQ->where('name', 'like', $likeEnds);
                                    } else {
                                        $subQ->where('name', '=', $value);
                                    }
                                }
                            }
                        });
                    });
                }
            }
            // Unset to prevent QueryFilter from processing it incorrectly
            unset($filters['filters']['assigned_user_name']);
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [],
                'skip_sorting' => $skipSorting,
            ]
        );

        // Statistics come from the pre-filter snapshot, not from $query: these are
        // headline totals for the whole book, and recomputing them per filter made
        // the cards move every time someone narrowed the table.
        $statistics = [
            'total_partners' => (clone $statsQuery)->count(),
            'active_partners' => (clone $statsQuery)->where('status', Partner::STATUS_ACTIVE)->count(),
            'inactive_partners' => (clone $statsQuery)->whereIn('status', [Partner::STATUS_INACTIVE, Partner::STATUS_SUSPENDED])->count(),
            'new_this_month' => (clone $statsQuery)->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            PartnerResource::class,
            __('partner.success.retrieved'),
            200,
            ['statistics' => $statistics]
        );
    }

    /**
     * Get all partners with their user information (legacy method with statistics).
     */
    public function getAllPartners(Request $request)
    {
        // Get authenticated user to determine filter logic
        $authUser = auth()->user();

        // Base query: Start from Partner model with User eager loading
        $query = Partner::query()
            ->with([
                'assignedUser' => function ($userQuery) {
                    $userQuery->select('id', 'name');
                }
            ])
            ->select(
                'partners.id',
                'partners.company_name',
                'partners.full_name',
                'partners.contact_person',
                'partners.contact_person_email',
                'partners.contact_person_phone_number',
                'partners.email',
                'partners.phone_number',
                'partners.partner_type',
                'partners.is_host',
                'partners.assign_to',
                'partners.created_at'
            );

        // Filter based on authenticated user's host status
        $hostFilter = null;
        if ($authUser && $authUser->partner && $authUser->partner->is_host == 1) {
            // If logged-in user is a host, show only non-host partners
            $query->where('is_host', '!=', 1);
            $hostFilter = ['is_host', '!=', 1];
        } else {
            // Otherwise, show only host partners
            $query->where('is_host', 1);
            $hostFilter = ['is_host', '=', 1];
        }

        // 🔍 Filter by partner_id (exact match)
        if ($partnerId = $request->get('partner_id')) {
            $query->where('partners.id', $partnerId);
        }

        // 🔍 Filter by assigned_user_id (exact match)
        if ($assignedUserId = $request->get('assigned_user_id')) {
            $query->where('partners.assign_to', $assignedUserId);
        }

        // 🔍 Search filter (searches all partner and user fields)
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%") // Changed from email
                    ->orWhere('phone_number', 'LIKE', "%{$search}%") // Changed from phone_number
                    ->orWhere('full_name', 'LIKE', "%{$search}%") // Changed from owner_name
                    ->orWhere('contact_person', 'LIKE', "%{$search}%")
                    ->orWhere('partner_type', 'LIKE', "%{$search}%")
                    ->orWhereHas('assignedUser', function ($userQ) use ($search) {
                        $userQ->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%");
                    });
            });
        }

        // 📄 Pagination (default 15 per page)
        $partners = $query->orderByDesc('partners.id')->paginate($request->get('per_page', 15));

        // Transform to the required flat structure
        $data = $partners->map(function ($partner) {
            $assignedUser = $partner->assignedUser;

            return [
                'id' => $partner->id,
                'assigned_user_id' => $assignedUser->id ?? null,
                'assigned_user_name' => $assignedUser->name ?? null,
                'full_name' => $partner->full_name,
                'company_name' => $partner->company_name,
                'email' => $partner->email,
                'phone_number' => $partner->phone_number,
                'contact_person' => $partner->contact_person,
                'contact_person_email' => $partner->contact_person_email,
                'contact_person_phone' => $partner->contact_person_phone_number,
                'partner_type' => $partner->partner_type,
                'status' => $partner->status,
                'joining_date' => $partner->joining_date,
            ];
        })->all();

        // Return data with pagination meta and statistics
        return [
            'data' => $data,
            'meta' => [
                'current_page' => $partners->currentPage(),
                'from' => $partners->firstItem(),
                'to' => $partners->lastItem(),
                'per_page' => $partners->perPage(),
                'total' => $partners->total(),
                'last_page' => $partners->lastPage(),
                'next_page_url' => $partners->nextPageUrl(),
                'prev_page_url' => $partners->previousPageUrl(),
                'links' => $partners->linkCollection(),
            ],

        ];
    }


    /**
     * Map request data to partner model attributes.
     */
    private function mapPartnerData(array $data): array
    {
        $partnerData = [];

        foreach ($this->partnerFieldMapping as $requestField => $partnerField) {

            if (array_key_exists($requestField, $data)) {
                $value = $data[$requestField];
                if ($partnerField === 'joining_date' && !empty($value)) {
                    $value = normalizeDate($value);
                }
                if ($partnerField === 'free_trail_end_date') {
                    $value = !empty($value) ? $value : null;
                }
                if ($partnerField === 'is_free_trail') {
                    $value = (bool) $value;
                }
                $partnerData[$partnerField] = $value;
            }
        }

        return $partnerData;
    }

    /**
     * Get Borrowers list.
     */
    public function getBorrowers(array $filters)
    {

        $query = Borrower::query()->with('partner');

        // Filter by partner_id
        if (!empty($filters['partner_id'])) {
            $query->where('partner_id', $filters['partner_id']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'id';

        // Handle full_name sorting by using first_name as proxy
        if ($sortField === 'full_name') {
            $sortField = 'first_name';
        }

        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Apply QueryFilter
        // Search across multiple fields
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('business_name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone_number', 'LIKE', "%{$search}%")
                    ->orWhere('created_at', 'LIKE', "%{$search}%")
                    // Search concatenated full_name (first_name + last_name)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
            });
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'aliases' => [
                    'company_name' => 'business_name',
                ],
                'concat_fields' => [
                    'full_name' => ['first_name', 'last_name'],
                ],
            ]
        );

        // Pagination
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            BorrowerPartnerResource::class,
            __('partner.borrowers_retrieved_data')
        );
    }

    /**
     * Delete Partner
     */

    /**
     * Archive records so they leave the working list without being deleted.
     *
     * Presentation only: `status` is untouched, so archiving changes nothing about
     * login, billing or permissions. Already-archived rows are skipped rather than
     * re-stamped, so the original "who filed this away" survives a repeat action.
     */
    public function archive(array $ids)
    {
        // The host is refused, not quietly skipped: skipping it still answered
        // "archived successfully" (BUG-205).
        if ($this->selectsHost($ids)) {
            return ApiResponse::error(__('partner.error.host_protected'), 422);
        }

        try {
            DB::beginTransaction();

            $archived = 0;

            foreach (Partner::whereIn('id', $ids)->where('is_host', false)->get() as $record) {
                if ($record->archive()) {
                    $archived++;
                }
            }

            DB::commit();

            return ApiResponse::success(
                ['archived' => $archived],
                __('partner.success.archived')
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Partner archive failed', ['ids' => $ids, 'error' => $e->getMessage()]);

            return ApiResponse::error(__('partner.error.archive_failed'), 400);
        }
    }

    /**
     * Return archived records to the working list. Nothing else about them changes -
     * archiving never altered the record, so restoring has nothing to rebuild.
     */
    public function unarchive(array $ids)
    {
        try {
            DB::beginTransaction();

            $restored = 0;

            foreach (Partner::whereIn('id', $ids)->get() as $record) {
                if ($record->unarchive()) {
                    $restored++;
                }
            }

            DB::commit();

            return ApiResponse::success(
                ['restored' => $restored],
                __('partner.success.unarchived')
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Partner restore failed', ['ids' => $ids, 'error' => $e->getMessage()]);

            return ApiResponse::error(__('partner.error.unarchive_failed'), 400);
        }
    }

    public function delete(array $ids)
    {
        // The host is the platform itself - never deleted from a list of
        // partners. Refused with a message rather than skipped (BUG-205).
        if ($this->selectsHost($ids)) {
            return ApiResponse::error(__('partner.error.host_protected'), 422);
        }

        $partners = Partner::whereIn('id', $ids)->where('is_host', false)->get();

        // A partner with a paid invoice (or borrowers/users still under it) is
        // kept. Refuse the whole selection and name each blocked partner, so the
        // user knows to change their status instead - same rule as clients.
        $blocked = $partners->reject(fn (Partner $partner) => $partner->isDeletable());
        if ($blocked->isNotEmpty()) {
            // One partner: the plain sentence. Several: name the blocked ones.
            $message = $partners->count() === 1
                ? __('partner.error.delete_blocked')
                : __('partner.error.delete_blocked_named', ['names' => $blocked->pluck('full_name')->implode(', ')]);

            return ApiResponse::error($message, 422, [
                'blocked' => $blocked->map(fn (Partner $p) => ['id' => $p->id, 'name' => $p->full_name])->values(),
            ]);
        }

        try {
            DB::beginTransaction();
            $images = [];
            foreach ($partners as $partner) {
                if ($partner->partner_image) {
                    $images[] = $partner->getRawOriginal('partner_image');
                }
                $partner->safeDeleteWithRelations();
            }
            DB::commit();

            // Files go only once the records are gone, so a failed delete keeps them.
            foreach ($images as $image) {
                $this->deleteFile($image, Disk::UPLOADS);
            }

            return ApiResponse::success([], __('partner.success.deleted'));
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Partner deletion failed', ['ids' => $ids, 'error' => $e->getMessage()]);
            return ApiResponse::error(__('partner.error.delete_failed'), 400);
        }
    }

    /** Whether a selection of partner ids includes the host company. */
    private function selectsHost(array $ids): bool
    {
        return Partner::whereIn('id', $ids)->where('is_host', true)->exists();
    }

    /**
     * Check if multiple partners are deletable
     */
    public function checkDeletable(array $ids): array
    {
        $partners = Partner::whereIn('id', $ids)->get();
        $results = [];

        foreach ($partners as $partner) {
            $is_deletable = !$partner->is_host && $partner->isDeletable();
            $results[] = [
                'id' => $partner->id,
                'name' => $partner->full_name,
                'is_deletable' => $is_deletable,
                'reason' => !$is_deletable ? $partner->notDeletableReason() : null,
            ];
        }

        return $results;
    }



    public function getPartnerProfileStats(int $id)
    {
        if (!Partner::whereKey($id)->exists()) {
            return (object) [
                'id' => $id,
                'total_meetings' => 0,
                'total_clients' => 0,
                'total_invoice' => 0,
                'total_tickets' => 0,
                'enrollment_type' => 'N/A',
            ];
        }

        // Eloquent counts, not raw sub-selects: a deleted (soft-deleted) client,
        // invoice, ticket or meeting is not counted (BUG-173), so each card
        // matches the partner's tabs.
        $partnerUserIds = User::where('partner_id', $id)->select('id');

        return (object) [
            'id' => $id,
            // The partner's own meetings, and those its users scheduled or are assigned to.
            'total_meetings' => Appointment::where(fn ($q) => $q
                ->where(fn ($own) => $own->where('entity_type', Partner::class)->where('entity_id', $id))
                ->orWhereIn('scheduled_by', $partnerUserIds)
                ->orWhereIn('assigned_for', $partnerUserIds))
                ->count(),
            // Archived clients leave the working list (Clients menu, "Active Only").
            'total_clients' => Borrower::where('partner_id', $id)->notArchived()->count(),
            'total_invoice' => Invoice::where('partner_id', $id)->count(),
            'total_tickets' => SupportTicket::where('partner_id', $id)->count(),
            // Latest subscription row, as before (not a count, unchanged here).
            'enrollment_type' => Subscription::withTrashed()
                ->where('entity_type', Partner::class)->where('entity_id', $id)
                ->orderByDesc('id')->value('enrollment_type') ?? 'N/A',
        ];
    }

    /**
     * Get the next partner ID (last partner ID plus 1).
     */
    public function getNextPartnerId(): int
    {
        return (Partner::max('id') ?? 0) + 1;
    }

    /**
     * Set the main partner user to suspended/inactive and revoke tokens.
     */
    private function restrictPartnerUsers(Partner $partner): void
    {
        $mainUser = $partner->partnerUser;

        if ($mainUser) {
            // Force Logout (Revoke all tokens)
            DB::table('oauth_access_tokens')
                ->where('user_id', $mainUser->id)
                ->update(['revoked' => true]);

        }
    }

    /**
     * Force logout for reactivated partner (optional but kept for internal logic consistency).
     */
    private function reactivatePartnerUsers(Partner $partner): void
    {
        Log::info("Main user for Partner ID: {$partner->id} is eligible for login again.");
    }

    /**
     * Get Tenant Config.
     */
    public function getTenantConfig(int $id)
    {
        return $this->findById($id);
    }

    /**
     * Update Tenant Config.
     */
    public function updateTenantConfig(int $id, array $data)
    {
        $partner = $this->findById($id);

        if (!$partner) {
            throw new \Exception(__('partner.error.not_found'));
        }

        $partner->update($data);

        return $partner;
    }

    /**
     * Generate a subdomain from company name.
     * Small letter, remove special letters, number, space.
     */
    private function generateSubdomain(string $companyName): string
    {
        // Small letter
        $subdomain = strtolower($companyName);
        // Remove special letters, number, space (keeping only a-z)
        $subdomain = preg_replace('/[^a-z]/', '', $subdomain);
        return $subdomain;
    }
}
