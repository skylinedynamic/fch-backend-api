<?php

namespace App\Services\FCH\Common\Borrower;

use Dom\DocumentType;
use App\Models\User\User;
use App\Models\Common\Role;
use App\Helpers\ApiResponse;
use App\Traits\PaginatesApi;
use App\Traits\UploadsFiles;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use Illuminate\Http\UploadedFile;
use App\Models\CreditBuilder\Tier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FCH\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\BorrowerSignupId;
use App\Models\Subscription\Subscription;
use App\Mail\Borrower\BorrowerPaymentLinkMail;
use App\Services\Global\Datatable\QueryFilter;
use App\Mail\Borrower\BorrowerAccountCreatedMail;
use App\Http\Resources\FCH\Borrower\BorrowerResource;
use App\Services\FCH\Subscription\SubscriptionService;
use App\Http\Resources\FCH\Borrower\BorrowerDetailsResource;
use App\Http\Resources\Global\Document\BorrowerDocTypeResource;

class BorrowerService
{
    use PaginatesApi, UploadsFiles;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * List borrowers with pagination, search, filtering and sorting.
     */
    public function list(array $filters)
    {

        // Get authenticated user to determine filter logic
        $authUser = auth()->user();

        $query = Borrower::query()
            ->with(['partner:id,full_name', 'assignedUser:id,name', 'tier:id,name'])
            ->select('borrowers.*');

        // Filter based on authenticated user's partner and host status
        if ($authUser && $authUser->partner) {
            if ($authUser->partner->is_host == 1) {
                // If user is a host, filter by partner_id if provided, otherwise show all
                if (! empty($filters['partner_id'])) {
                    $query->where('borrowers.partner_id', $filters['partner_id']);
                }
                // If no partner_id filter provided, show all borrowers (no restriction)
            } else {
                // If user is not a host, only show borrowers from their own partner
                $query->where('borrowers.partner_id', $authUser->partner_id);
            }
        }

        // Filter by current_tier (supports ID or Name)
        if (! empty($filters['current_tier'])) {
            $currentTier = $filters['current_tier'];
            if (is_numeric($currentTier)) {
                $query->where('borrowers.current_tier', $currentTier);
            } else {
                $query->whereHas('tier', function ($q) use ($currentTier) {
                    $q->where('name', 'like', "%{$currentTier}%");
                });
            }
        }

        // Filter by assign_id
        if (! empty($filters['assign_id'])) {
            $query->where('borrowers.assign_id', $filters['assign_id']);
        }

        // Determine sorting and joins
        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        if ($sortField === 'full_name') {
            $sortField = 'first_name';
        }

        $skipSorting = false;
        $rawFilters = $filters['filters'] ?? [];

        // Join Tiers if sorting or filtering by current_tier_name
        if ($sortField === 'current_tier_name' || isset($rawFilters['current_tier_name'])) {
            $query->leftJoin('tiers', 'borrowers.current_tier', '=', 'tiers.id')
                ->addSelect('tiers.name as current_tier_name');
            if ($sortField === 'current_tier_name') {
                $query->orderBy('tiers.name', $sortOrder);
                $skipSorting = true;
            }
        }

        // Join Partners if sorting or filtering by partner_name
        if ($sortField === 'partner_name' || isset($rawFilters['partner_name'])) {
            $query->leftJoin('partners', 'borrowers.partner_id', '=', 'partners.id')
                ->addSelect('partners.full_name as partner_name');
            if ($sortField === 'partner_name') {
                $query->orderBy('partners.full_name', $sortOrder);
                $skipSorting = true;
            }
        }

        // Join Users if sorting or filtering by assign_name
        if ($sortField === 'assign_name' || isset($rawFilters['assign_name'])) {
            $query->leftJoin('users as assigned_users', 'borrowers.assign_id', '=', 'assigned_users.id')
                ->addSelect('assigned_users.name as assign_name');
            if ($sortField === 'assign_name') {
                $query->orderBy('assigned_users.name', $sortOrder);
                $skipSorting = true;
            }
        }

        // Search across multiple fields
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('borrowers.first_name', 'LIKE', "%{$search}%")
                    ->orWhere('borrowers.last_name', 'LIKE', "%{$search}%")
                    ->orWhere('borrowers.email', 'LIKE', "%{$search}%")
                    ->orWhere('borrowers.phone_number', 'LIKE', "%{$search}%")
                    ->orWhere('borrowers.status', 'LIKE', "%{$search}%")
                    ->orWhereHas('partner', function ($q) use ($search) {
                        $q->where('full_name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('tier', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })
                    // Search concatenated full_name (first_name + last_name)
                    ->orWhereRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) LIKE ?", ["%{$search}%"]);
            });
        }

        // 🔍 Filter by assign_name (related user name)
        if (isset($filters['filters']['assign_name'])) {
            $assignNameFilter = $filters['filters']['assign_name'];
            if (isset($assignNameFilter['constraints']) && is_array($assignNameFilter['constraints'])) {
                $hasActiveConstraint = false;
                foreach ($assignNameFilter['constraints'] as $c) {
                    if (!empty($c['value'])) {
                        $hasActiveConstraint = true;
                        break;
                    }
                }

                if ($hasActiveConstraint) {
                    $query->whereHas('assignedUser', function ($q) use ($assignNameFilter) {
                        $q->where(function ($subQ) use ($assignNameFilter) {
                            foreach ($assignNameFilter['constraints'] as $constraint) {
                                $value = $constraint['value'] ?? null;
                                $matchMode = $constraint['matchMode'] ?? 'contains';

                                if ($value) {
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
            unset($filters['filters']['assign_name']);
        }

        // Build aliases dynamically based on which joins are present
        $aliases = [
            'email' => 'borrowers.email',
            'phone_number' => 'borrowers.phone_number',
            'first_name' => 'borrowers.first_name',
            'last_name' => 'borrowers.last_name',
            'status' => 'borrowers.status',
        ];

        // Add join-dependent aliases only when those joins exist
        if ($sortField === 'current_tier_name' || isset($rawFilters['current_tier_name'])) {
            $aliases['current_tier_name'] = 'tiers.name';
        }
        if ($sortField === 'partner_name' || isset($rawFilters['partner_name'])) {
            $aliases['partner_name'] = 'partners.full_name';
        }
        if ($sortField === 'assign_name' || isset($rawFilters['assign_name'])) {
            $aliases['assign_name'] = 'assigned_users.name';
        }

        $query = QueryFilter::apply(
            $query,
            $sortField,
            $sortOrder,
            $filters['filters'] ?? [],
            [
                'concat_fields' => [
                    'full_name' => ['borrowers.first_name', 'borrowers.last_name'],
                ],
                'aliases' => $aliases,
                'skip_sorting' => $skipSorting,
            ]
        );


        // Pagination
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            BorrowerResource::class,
            __('borrower.success.retrieved')
        );
    }

    /**
     * Store a new borrower.
     */
    public function store(array $data)
    {
        DB::beginTransaction();
        try {
            // Handle partner_id assignment
            $authUser = auth()->user();
            if (empty($data['partner_id'])) {
                $data['partner_id'] = $authUser->partner_id;
            } elseif ($authUser->partner && $authUser->partner->is_host != 1 && $data['partner_id'] != $authUser->partner_id) {
                // Non-hosts can only create borrowers for their own partner
                $data['partner_id'] = $authUser->partner_id;
            }

            $data['current_tier'] = Tier::orderBy('order', 'asc')->first()?->id;
            $data['status'] = Borrower::STATUS_ONBOARDING;

            // Filter out fields that are not columns in the borrowers table
            $borrowerData = collect($data)->except(['payment_amount', 'enrollment_type'])->toArray();

            $borrower = Borrower::create($borrowerData);
            Log::info('Payment Amount: ' . ($data['payment_amount'] ?? 'N/A'));
            // Create associated User if needed
            $userDataResult = null;
            if (!empty($data['email'])) {
                Log::info('Email send');
                $userDataResult = $this->createOrUpdateUser($data, $borrower);
                $user = $userDataResult['user'];
                $plainPassword = $userDataResult['plain_password'];

                if ($user && $plainPassword) {
                    $hostPartner = Partner::where('is_host', 1)->first();
                    $authUser = Auth::user();
                    $isHostCreating = $authUser && $authUser->partner && $authUser->partner->is_host;

                    $mailData = [
                        'user' => $user,
                        'plain_password' => $plainPassword,
                        'business_info' => [
                            'company_name' => $hostPartner->company_name ?? config('app.name'),
                            'company_logo' => $hostPartner->company_logo ?? 'logo.png',
                            'email' => $hostPartner->email ?? config('mail.from.address'),
                            'phone_number' => $hostPartner->phone_number ?? '',
                            'address_line_1' => $hostPartner->address_line_1 ?? '',
                        ]
                    ];

                    $enrollmentType = $data['enrollment_type'] ?? null;

                    $plan = SubscriptionPlan::where('entity_type', Borrower::class)
                        ->where('enrollment_type', $enrollmentType)
                        ->first();

                    $amount = (float) ($data['payment_amount'] ?? ($data['amount'] ?? ($plan->payment_amount ?? 0)));
                    Log::info('Total Enrollment Amount: ' . $amount);

                    $invoice = $this->invoiceService->create([
                        'partner_id' => $borrower->partner_id,
                        'borrower_id' => $borrower->id,
                        'entity_type' => Borrower::class,
                        'entity_id' => $borrower->id,
                        'customer_name' => $borrower->full_name,
                        'email' => $borrower->email,
                        'country' => $borrower->country,
                        'payment_amount' => $amount,
                        'status' => Invoice::STATUS_UNPAID,
                        'is_platform_fee' => false,
                    ]);

                    $subscription = $this->subscriptionService->startSubscription($borrower, $plan, $enrollmentType, [
                        'monthly_platform_fee' => 0,
                        'total_subscription_amount' => $plan->total_amount ?? 0,
                        'payable_amount' => $plan->payment_amount ?? 0,
                    ]);
                    Log::info('✅ Borrower subscription initialized with phases', ['borrower_id' => $borrower->id]);

                    if ($isHostCreating) {
                        Log::info('Triggering Onboarding Email');
                        if ($amount > 0) {
                            $mailData['payment_link'] = config('app.frontend_partner_url') . '/invoices?invoice_id=' . $invoice->id;
                            $mailData['amount'] = $invoice->payment_amount;
                            Log::info('📧 Sending Payment Link Mail to Borrower');
                            Mail::to($user->email)->send(new BorrowerPaymentLinkMail($mailData));
                        } else {
                            Log::info('📧 Sending Account Created Mail to Borrower');
                            Mail::to($user->email)->send(new BorrowerAccountCreatedMail($mailData));
                        }
                    } else {
                        Log::info('📧 Sending Default Account Created Mail');
                        Mail::to($user->email)->send(new BorrowerAccountCreatedMail($mailData));
                    }
                }
            }

            DB::commit();

            return [
                'borrower' => $borrower->fresh(),
                'user' => $userDataResult['user'] ?? null,
                'plain_password' => $userDataResult['plain_password'] ?? null,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Borrower creation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update an existing borrower.
     */
    public function update(Borrower $borrower, array $data)
    {
        DB::beginTransaction();
        try {
            if (isset($data['business_logo']) && $data['business_logo'] instanceof UploadedFile) {
                if ($borrower->business_logo) {
                    $this->deleteFile($borrower->business_logo, 'public');
                }
                $upload = $this->uploadFile($data['business_logo'], "borrowers/{$borrower->id}", 'public');
                $data['business_logo'] = $upload['path'];
            }

            $borrower->update($data);

            // Update associated User if exists
            if ($borrower->user) {
                $userResult = $this->createOrUpdateUser($data, $borrower, $borrower->user);
                $user = $userResult['user'];
                $plainPassword = $userResult['plain_password'];

                if ($plainPassword) {
                    $hostPartner = Partner::where('is_host', 1)->first();
                    $mailData = [
                        'user' => $user,
                        'plain_password' => $plainPassword,
                        'business_info' => [
                            'company_name' => $hostPartner->company_name ?? config('app.name'),
                            'company_logo' => $hostPartner->company_logo ?? 'logo.png',
                            'email' => $hostPartner->email ?? config('mail.from.address'),
                            'phone_number' => $hostPartner->phone_number ?? '',
                            'address_line_1' => $hostPartner->address_line_1 ?? '',
                        ],
                        'subject' => 'Welcome to Four Corner Holdings, LLC - Borrower Account Updated',
                        'is_update' => true,
                    ];
                    Log::info('📧 Sending account update email to borrower with new password', ['email' => $user->email]);
                    Mail::to($user->email)->send(new BorrowerAccountCreatedMail($mailData));
                }
            } elseif (!empty($data['email'])) {
                // Create user if email is provided and user doesn't exist
                $this->createOrUpdateUser($data, $borrower);
            }

            DB::commit();

            // Return consistent format or just borrower? Controller expects resource.
            // Keeping it simple as controller will be updated to handle array for store, but update often doesn't need email.
            return $borrower->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Borrower update failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get borrower details.
     */
    public function getDetails(int $id)
    {
        return Borrower::when(auth()->user()->user_type == User::PARTNER, function ($query) {
            $query->where('partner_id', auth()->user()->partner_id);
        })
            ->with('partner')
            ->find($id);
    }

    /**
     * Delete borrowers.
     */
    public function delete(array $ids)
    {
        try {
            // Delete associated users first
            User::where('entity_type', Borrower::class)->whereIn('entity_id', $ids)->delete();
            Borrower::whereIn('id', $ids)->delete();

            return ApiResponse::success([], __('borrower.success.deleted'));
        } catch (\Throwable $e) {
            return ApiResponse::serverError(__('borrower.error.delete_failed'), 500);
        }
    }

    /**
     * Create or update user for borrower.
     */
    private function createOrUpdateUser(array $data, Borrower $borrower, ?User $existingUser = null)
    {
        $userData = [];

        if (isset($data['first_name']) && isset($data['last_name'])) {
            $userData['name'] = $data['first_name'] . ' ' . $data['last_name'];
        }

        if (isset($data['email'])) {
            $userData['email'] = $data['email'];
        }

        if (isset($data['phone_number'])) {
            $userData['phone_number'] = $data['phone_number'];
        }

        if (!$existingUser) {
            // Defaults for new user
            $userData['user_type'] = User::BORROWER;
            $userData['entity_type'] = Borrower::class;
            $userData['entity_id'] = $borrower->id;
            $userData['partner_id'] = $borrower->partner_id;
        }

        if ($existingUser) {
            $plainPassword = null;
            // Check if email is changing and present in update data
            if (isset($userData['email']) && $existingUser->email !== $userData['email']) {
                $plainPassword = bin2hex(random_bytes(4)); // Generate 8 char hex string (4 bytes)
                $userData['password'] = bcrypt($plainPassword);
                Log::info('🔑 Password reset for Borrower user due to email change', ['user_id' => $existingUser->id]);
            }

            $existingUser->update($userData);
            return ['user' => $existingUser, 'plain_password' => $plainPassword];
        }

        // Check if user exists by email to avoid duplicates if not linked
        $user = User::where('email', $data['email'])->first();
        if ($user) {
            $user->update($userData);
            return ['user' => $user, 'plain_password' => null];
        }

        $plainPassword = bin2hex(random_bytes(4)); // Generate 8 char hex string (4 bytes)
        $userData['password'] = bcrypt($plainPassword);

        $newUser = User::create($userData);

        // Assign 'Borrower' role based on partner_id
        $role = Role::where('name', 'Borrower')
            ->where('partner_id', $borrower->partner_id)
            ->first();

        if ($role) {
            $newUser->assignRole($role);
        }

        return ['user' => $newUser, 'plain_password' => $plainPassword];
    }

    /**
     * Get all users where entity_type is Employee or NULL.
     * Returns only id, name, email, phone_number.
     * 
     * If auth user is_host == 1: returns all users
     * Otherwise: returns only users filtered by partner_id
     */
    public function getAllUsers()
    {
        // Get authenticated user
        $authUser = auth()->user();

        // Base query: users where entity_type is Employee or NULL
        $query = User::query()
            ->where(function ($q) {
                $q->where('entity_type', 'App\\Models\\Borrower\\Borrower')
                    ->orWhereNull('entity_type');
            });

        // Apply partner_id filter if user is NOT a host
        if ($authUser && $authUser->partner) {
            // Check if the authenticated user's partner is a host
            if ($authUser->partner->is_host != 1) {
                // Not a host: filter by partner_id
                $query->where('partner_id', $authUser->partner_id);
            }
            // If is_host == 1: no filter applied, returns all users
        }

        $users = $query
            ->select('id', 'name', 'email', 'phone_number')
            ->orderBy('name', 'asc')
            ->get();

        return $users;
    }

    /**
     * Get Borrower Statistics Counts.
     */
    public function getBorrowerStats($partnerId = null): array
    {
        $today = now();
        $startOfMonth = $today->copy()->startOfMonth();
        $endOfMonth = $today->copy()->endOfMonth();

        // Base query
        $query = Borrower::query();

        // Check user type
        $user = auth()->user();

        // If user is a Borrower, they should only see their own data
        if ($user && $user->user_type === 'Borrower') {
            return [
                'total_borrowers' => 0,
                'new_this_month' => 0,
                'active_count' => 0,
                'inactive_count' => 0,
            ];
        }

        // If user is Partner, use their partner_id
        if ($user && $user->user_type === 'Partner' && !$partnerId) {
            if ($user->entity_type === 'App\\Models\\Partner\\Partner' && $user->entity_id) {
                $partnerId = $user->entity_id;
            }
        }

        // Filter by partner_id if provided
        if ($partnerId) {
            $query->where('partner_id', $partnerId);
        }

        // Clone query for different counts
        $totalBorrowers = (clone $query)->count();
        $newThisMonth = (clone $query)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count();

        // Active count: borrowers with STATUS_ACTIVE
        $activeCount = (clone $query)->where('status', Borrower::STATUS_ACTIVE)->count();

        return [
            'total_borrowers' => $totalBorrowers,
            'new_this_month' => $newThisMonth,
            'active_count' => $activeCount,
            'inactive_count' => $totalBorrowers - $activeCount,
        ];
    }

    /**
     * Get Borrower Profile Statistics.
     */
    /**
     * Get Borrower Profile Statistics.
     */
    public function getBorrowerProfileStats(int $id)
    {
        // Use addslashes to properly escape backslashes for the SQL string literal
        $entityType = addslashes(Borrower::class);

        $stats = DB::table('borrowers as b')
            ->select(
                'b.id',
                // Meetings: linked via User (assigned_for).
                DB::raw("(SELECT COUNT(*) FROM appointments WHERE assigned_for IN (SELECT id FROM users WHERE entity_type = 'App\\\\\\Models\\\\\\Borrower\\\\\\Borrower' AND entity_id = b.id)) as total_meetings"),

                // Invoices: linked via entity_type and entity_id
                DB::raw("(SELECT COUNT(*) FROM invoices WHERE entity_type = 'App\\\\\\Models\\\\\\Borrower\\\\\\Borrower' AND entity_id = b.id) as total_invoices"),

                DB::raw("(SELECT COALESCE((SELECT enrollment_type FROM subscriptions 
                          WHERE entity_type = 'App\\\\\\Models\\\\\\Partner\\\\\\Partner' AND entity_id = b.partner_id 
                          ORDER BY id DESC 
                          LIMIT 1), 'N/A')) as enrollment_type"),

                // Prequalification Requests
                DB::raw('(SELECT COUNT(*) FROM pre_qualification_requests WHERE borrower_id = b.id) as total_prequalification'),

                // Tickets: Placeholder for future support tickets implementation
                DB::raw('0 as total_tickets'),

                // Documents
                DB::raw('(SELECT COUNT(*) FROM borrower_documents WHERE borrower_id = b.id) as total_documents')
            )
            ->where('b.id', $id)
            ->first();

        return $stats;
    }

    /**
     * Get Borrower Documents.
     */
    public function getBorrowerDocuments(int $borrowerId)
    {

        $documents = BorrowerDocument::with('documentType')
            ->where('borrower_id', $borrowerId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($doc) {
                $size = '0 KB';
                $relativePath = $doc->file_path;
                if ($relativePath && str_contains($relativePath, '/storage/')) {
                    $relativePath = substr($relativePath, strpos($relativePath, '/storage/') + strlen('/storage/'));
                }

                // Use relative path for storage operations
                if ($relativePath && Storage::disk('public')->exists($relativePath)) {
                    $sizeBytes = Storage::disk('public')->size($relativePath);
                    $size = $this->formatSizeUnits($sizeBytes);
                }

                return [
                    'id' => $doc->id,
                    'document_name' => $doc->file_name ?: ($doc->getRawOriginal('file_path') ? basename($doc->getRawOriginal('file_path')) : 'N/A'),
                    'type' => $doc->documentType->name ?? 'Other',
                    'rejection_reason' => $doc->rejection_reason ?? '',
                    'size' => $doc->file_size ?: '0 KB',
                    'status' => $doc->status ?: BorrowerDocument::STATUS_UPLOADED,
                    'date' => $doc->created_at ? $doc->created_at->format('Y-m-d') : 'N/A',
                    'document_url' => $doc->file_path, // Uses accessor to return full URL
                ];
            });


        return $documents;
    }

    private function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824) {
            $bytes = number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            $bytes = number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            $bytes = number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            $bytes = $bytes . ' bytes';
        } elseif ($bytes == 1) {
            $bytes = $bytes . ' byte';
        } else {
            $bytes = '0 bytes';
        }

        return $bytes;
    }
}
