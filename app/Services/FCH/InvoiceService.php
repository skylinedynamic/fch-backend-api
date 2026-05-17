<?php

namespace App\Services\FCH;

use App\Models\User\User;
use Illuminate\Support\Str;
use App\Traits\PaginatesApi;
use Illuminate\Support\Carbon;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Mail\Invoice\InvoiceMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\FCH\Invoice\InvoiceResource;
use App\Models\Employee\Employee;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Services\FCH\Common\Setting\EmailConfigurationService;

class InvoiceService
{
    use PaginatesApi;

    public function __construct(
        protected EmailConfigurationService $emailConfigService
    ) {}
    /**
     * Get list of invoices based on filters.
     *
     * @param array $filters
     * @return JsonResponse
     */
    public function getInvoices(array $filters): JsonResponse
    {
        $authUser = Auth::user();
        $query = Invoice::query()
            ->with([
                'partner:id,full_name',
                'borrower:id,first_name,last_name'
            ])
            ->select('invoices.*');

        // RBAC: Partner Wise (Accurate Code)
        if (!empty($authUser?->partner) && (int) ($authUser->partner->is_host ?? 0) === 0) {
            $query->where('invoices.partner_id', $authUser->partner_id);
        }

        // Accurate Date Range: created_at (Following User Request)
        // Accurate Date Range Filtering (Inclusive of Full Day for Timestamps)
        if (!empty($filters['date_from'])) {
            $query->where(
                'invoices.created_at',
                '>=',
                Carbon::parse($filters['date_from'])->startOfDay()
            );
        }

        if (!empty($filters['date_to'])) {
            $query->where(
                'invoices.created_at',
                '<=',
                Carbon::parse($filters['date_to'])->endOfDay()
            );
        }


        // Efficiently extract global search term
        $globalSearch = $filters['filters']['global']['value'] ?? ($filters['search'] ?? null);
        $search = !empty($globalSearch) ? trim((string) $globalSearch) : null;


        if ($search) {
            $query->search($search);
        }
        // PrimeReact Structured Filters
        $rawFilters = $filters['filters'] ?? [];
        unset($rawFilters['global']); // Prevent QueryFilter from trying to search a 'global' column

        // Manual Date Handling for structured filters
        foreach (['date', 'created_at'] as $key) {
            if (isset($rawFilters[$key])) {
                $dateFilter = $rawFilters[$key];
                if (isset($dateFilter['constraints']) && is_array($dateFilter['constraints'])) {
                    foreach ($dateFilter['constraints'] as $constraint) {
                        $value = $constraint['value'] ?? null;
                        $mode = $constraint['matchMode'] ?? 'dateIs';
                        if ($value) {
                            if ($mode === 'dateIs') {
                                $query->whereDate('invoices.created_at', '=', $value);
                            } elseif ($mode === 'dateBefore') {
                                $query->whereDate('invoices.created_at', '<', $value);
                            } elseif ($mode === 'dateAfter') {
                                $query->whereDate('invoices.created_at', '>', $value);
                            }
                        }
                    }
                }
                unset($rawFilters[$key]);
            }
        }

        // Filter by Status (Compatibility)
        // Filter by Status (Compatibility)
        $statusFilter = $filters['status'] ?? null;
        if (is_array($statusFilter)) {
            $statusFilter = $statusFilter['value'] ?? null;
        }

        if (!empty($statusFilter) && $statusFilter !== 'All Status') {
            $query->where('invoices.status', $statusFilter);
        }

        if (isset($rawFilters['status'])) {
            unset($rawFilters['status']);
        }


        // Filter by Payee (Compatibility)
        if (!empty($filters['payee_id'])) {
            $query->where('invoices.entity_id', $filters['payee_id']);
            unset($rawFilters['payee_id']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        $sortFieldMap = [
            'date' => 'created_at',
            'payment_date' => 'payment_date',
            'amount' => 'payment_amount',
        ];

        $actualSortField = $sortFieldMap[$sortField] ?? $sortField;
        if (!str_contains($actualSortField, '.')) {
            $actualSortField = 'invoices.' . $actualSortField;
        }

        // Apply remaining structured filters via QueryFilter
        $query = QueryFilter::apply(
            $query,
            $actualSortField,
            $sortOrder,
            $rawFilters,
            [
                'aliases' => [
                    'amount' => 'payment_amount',
                    'date' => 'created_at',
                    'payment_date' => 'payment_date',
                ]
            ]
        );

        $perPage = isset($filters['per_page']) ? max(1, min((int) $filters['per_page'], 100)) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            InvoiceResource::class,
            __('invoice.list_retrieved')
        );
    }

    /**
     * Get invoice statistics.
     *
     * @param array $filters
     * @return array
     */
    public function getStats(array $filters): array
    {
        $authUser = Auth::user();
        $query = Invoice::query();

        // RBAC: Partner Wise (Accurate Code)
        if (!empty($authUser?->partner) && (int) ($authUser->partner->is_host ?? 0) === 0) {
            $query->where('invoices.partner_id', $authUser->partner_id);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['payee']) && $filters['payee'] !== Invoice::PAYEE_ALL) {
            $query->where('customer_name', 'like', '%' . $filters['payee'] . '%');
        }

        $allInvoices = (clone $query)->get(['payment_amount', 'status']);

        $totalSent = $allInvoices->sum('payment_amount');

        $paidAmount = $allInvoices->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_SUCCESS])
            ->sum('payment_amount');

        $unpaidAmount = $allInvoices->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PENDING, Invoice::STATUS_OVERDUE])
            ->sum('payment_amount');

        $collectionRate = $totalSent > 0 ? ($paidAmount / $totalSent) * 100 : 0;

        return [
            'invoices_sent' => $totalSent,
            'paid_invoices' => $paidAmount,
            'unpaid_invoices' => $unpaidAmount,
            'collection_rate' => round($collectionRate, 2)
        ];
    }

    /**
     * Generate a unique Invoice ID (INV-YYYYMMDDXXX).
     *
     * @return string
     */
    public function generateInvoiceId(): string
    {
        $today = now()->format('Ymd');

        // Count invoices created today to determine the sequence
        $todayCount = Invoice::where('invoice_id', 'like', "INV-{$today}%")->count();
        $sequence = str_pad($todayCount + 1, 3, '0', STR_PAD_LEFT);

        return "INV-{$today}{$sequence}";
    }

    /**
     * Create a new invoice.
     *
     * @param array $data
     * @return Invoice
     */
    public function create(array $data): Invoice
    {
        if (empty($data['invoice_id'])) {
            $data['invoice_id'] = $this->generateInvoiceId();
        }

        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        return Invoice::create($data);
    }

    public function find($id)
    {
        return Invoice::with(['partner', 'borrower', 'subscription'])->find($id)
            ?? Invoice::with(['partner', 'borrower', 'subscription'])->where('invoice_id', $id)->first();
    }

    /**
     * Download Invoice PDF.
     */
    public function downloadInvoice($id)
    {
        $invoice = $this->find($id);
        if (!$invoice) throw new \Exception("Invoice not found");

        $business_info = $this->getBusinessInfo($invoice->partner_id, $invoice->borrower_id);

        $pdf = Pdf::loadView('invoices.download', compact('invoice', 'business_info'))
            ->setPaper('a4')
            ->setWarnings(false);

        return $pdf;
    }

    /**
     * Send Invoice Email.
     */
    public function sendInvoice($id)
    {
        $invoice = $this->find($id);
        if (!$invoice) throw new \Exception("Invoice not found");

        $business_info = $this->getBusinessInfo($invoice->partner_id, $invoice->borrower_id);

        $pdf = Pdf::loadView('invoices.download', compact('invoice', 'business_info'))
            ->setPaper('a4')
            ->setWarnings(false);

        $pdfContent = $pdf->output();

        // Condition strictly requested by user:
        if ($invoice->is_partner_paying) {
            $recipientEmail = $invoice->partner?->email;
        } else {
            $recipientEmail = $invoice->borrower?->email;
        }
        
        // Fallback fallback just in case the specific relation email is missing
        $recipientEmail = $recipientEmail ?? $invoice->email;

        if (!$recipientEmail) {
            throw new \Exception("No recipient email found for this invoice");
        }

        $partnerId = $invoice->partner_id;
        $restore = $this->emailConfigService->applyToMailer($partnerId);
        try {
            Mail::to($recipientEmail)->send(new InvoiceMail($invoice, $pdfContent));
        } finally {
            $restore();
        }
        return true;
    }

    /**
     * Helper to get business info for templates with white-label support.
     */
    private function getBusinessInfo(?int $partnerId = null, ?int $borrowerId = null): array
    {
        // 1. Logic: If borrowerId is null, it's a Host-to-Partner invoice -> use Host branding
        // If borrowerId is present, it's a Partner-to-Borrower invoice -> use Partner branding
        $targetPartner = null;

        if ($borrowerId && $partnerId) {
            $targetPartner = Partner::find($partnerId);

            // Only use partner branding if it's a White-Label Partner
            if ($targetPartner && $targetPartner->partner_type !== Partner::PARTNER_TYPE_WHITE_LABEL) {
                $targetPartner = null; // Fallback to host
            }
        }

        // Fallback to Host if no partner or borrower_id was null
        if (!$targetPartner) {
            $targetPartner = Partner::where('is_host', 1)->first();
        }

        $entityId = $targetPartner->id;
        $entityType = 'App\\Models\\Partner\\Partner';

        // 2. Resolve Branding from AppConfiguration
        $siteName = \App\Models\Common\AppConfiguration::get('site_name', null, $entityType, $entityId);
        
        // Prioritize primary_logo as requested by user
        $logo = \App\Models\Common\AppConfiguration::get('primary_logo', null, $entityType, $entityId)
                ?? \App\Models\Common\AppConfiguration::get('partner_logo', null, $entityType, $entityId) 
                ?? \App\Models\Common\AppConfiguration::get('header_logo', null, $entityType, $entityId);

        // 3. Fallbacks to Partner Model
        $siteName = $siteName ?? $targetPartner->company_name ?? config('app.name');
        $logo = $logo ?? $targetPartner->company_logo;

        // 4. Resolve Logo Path for PDF (Local path is better for DomPDF)
        $logoPath = $logo;
        $businessLogoPath = public_path('logo-mail.png'); // Default fallback

        if ($logoPath) {
            if (str_starts_with($logoPath, 'http')) {
                $businessLogoPath = $logoPath;
            } else {
                $path = ltrim($logoPath, '/');
                // Standardize path resolution
                if (!str_starts_with($path, 'site-settings/') && !str_starts_with($path, 'partners/')) {
                    if ($targetPartner->company_logo === $logo) {
                        $path = "partners/{$entityId}/" . $path;
                    } else {
                        $path = "site-settings/" . $path;
                    }
                }
                
                $localPath = public_path('storage/' . $path);
                if (file_exists($localPath) && is_file($localPath)) {
                    $businessLogoPath = $localPath;
                }
            }
        }

        return [
            'company_name'   => $siteName,
            'email'          => $targetPartner->email ?? 'noreply@fourcornerfunding.com',
            'phone_number'   => $targetPartner->phone_number ?? '',
            'address_line_1' => $targetPartner->address_line_1 ?? '',
            'city'           => $targetPartner->city ?? '',
            'state'          => $targetPartner->state ?? '',
            'postal_code'    => $targetPartner->postal_code ?? '',
            'country'        => $targetPartner->country ?? '',
            'website'        => $targetPartner->website ?? 'https://www.fourcornerfunding.com',
            'business_logo_path' => $businessLogoPath,
        ];
    }
}
