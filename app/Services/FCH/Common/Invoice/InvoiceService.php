<?php

namespace App\Services\FCH\Common\Invoice;

use App\Services\FCH\Common\Notification\Notifier;
use App\Models\Borrower\Borrower;
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
use Illuminate\Database\Eloquent\Builder;
use App\Services\Global\Datatable\QueryFilter;
use App\Http\Resources\FCH\Invoice\InvoiceResource;
use App\Models\Common\AppConfiguration;
use App\Services\FCH\Common\Setting\AppConfigurationService;


class InvoiceService
{
    use PaginatesApi;

    /**
     * Apply RBAC / user scope to invoice query.
     */
    protected function applyRbacScope(Builder $query, mixed $authUser = null): Builder
    {
        $authUser = $authUser ?? Auth::user();

        if (!$authUser) {
            return $query;
        }

        // RBAC: Borrower Wise
        if ($authUser->entity_type === Borrower::class && !empty($authUser->entity_id)) {
            return $query->where('invoices.borrower_id', $authUser->entity_id);
        }

        // RBAC: Partner & Host Wise
        $partnerId = $authUser->partner_id;
        $partner = $authUser->partner ?? ($partnerId ? Partner::find($partnerId) : null);
        $isHost = $partner ? ((int) ($partner->is_host ?? 0) === 1) : false;

        // Every user belongs to a partner. One whose partner is missing or
        // deleted sees nothing - it used to be treated as the host (findings O6).
        if (!$partner) {
            return $query->whereRaw('1 = 0');
        }

        $partnerId = $partner->id;

        if ($isHost) {
            // Host sees: All partner invoices (is_partner_paying = true or entity_type = Partner) AND only host's own client invoices
            $query->where(function ($q) use ($partnerId) {
                $q->where('invoices.is_partner_paying', true)
                    ->orWhere('invoices.entity_type', Partner::class)
                    ->orWhere(function ($sub) use ($partnerId) {
                        $sub->where('invoices.partner_id', $partnerId)
                            ->where('invoices.is_partner_paying', false);
                    });
            });
        } elseif ($partnerId) {
            // Non-host partner sees ONLY his own invoices and his client invoices
            $query->where('invoices.partner_id', $partnerId);
        }

        return $query;
    }

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
                'borrower:id,first_name,last_name',
                // The payer - each row is named after them (see InvoiceResource).
                'entity',
                'subscription',
                'latestPayment'
            ])
            ->select('invoices.*');

        // RBAC: Partner & Borrower Wise
        $this->applyRbacScope($query, $authUser);

        if (!empty($filters['subscription_id'])) {
            $query->where('invoices.subscription_id', $filters['subscription_id']);
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

        // Handle structured filter for invoice_id (INV-...)
        if (isset($rawFilters['invoice_id'])) {
            $invoiceFilter = $rawFilters['invoice_id'];
            $constraints = $invoiceFilter['constraints'] ?? [$invoiceFilter];
            if (is_array($constraints)) {
                foreach ($constraints as $c) {
                    $value = $c['value'] ?? null;
                    if ($value) {
                        $query->where('invoice_id', 'like', "%{$value}%");
                    }
                }
            }
            unset($rawFilters['invoice_id']);
        }

        // Handle "partner_name" filter
        if (isset($rawFilters['partner_name'])) {
            $partnerNameFilter = $rawFilters['partner_name'];
            $constraints = $partnerNameFilter['constraints'] ?? [$partnerNameFilter];
            if (is_array($constraints)) {
                foreach ($constraints as $c) {
                    $value = $c['value'] ?? null;
                    if ($value) {
                        $query->whereHas('partner', function ($pq) use ($value) {
                            $pq->where('full_name', 'like', "%{$value}%");
                        });
                    }
                }
            }
            unset($rawFilters['partner_name']);
        }

        // Handle "borrower_name" filter
        if (isset($rawFilters['borrower_name'])) {
            $borrowerNameFilter = $rawFilters['borrower_name'];
            $constraints = $borrowerNameFilter['constraints'] ?? [$borrowerNameFilter];
            if (is_array($constraints)) {
                foreach ($constraints as $c) {
                    $value = $c['value'] ?? null;
                    if ($value) {
                        $query->whereHas('borrower', function ($bq) use ($value) {
                            $bq->where('first_name', 'like', "%{$value}%")
                                ->orWhere('last_name', 'like', "%{$value}%")
                                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$value}%"]);
                        });
                    }
                }
            }
            unset($rawFilters['borrower_name']);
        }

        // Handle "customer_name" filter specifically
        if (isset($rawFilters['customer_name'])) {
            $customerFilter = $rawFilters['customer_name'];
            $constraints = $customerFilter['constraints'] ?? [$customerFilter];
            if (is_array($constraints)) {
                foreach ($constraints as $c) {
                    $value = $c['value'] ?? null;
                    if ($value) {
                        $query->where(function ($q) use ($value) {
                            $q->whereHas('partner', function ($pq) use ($value) {
                                $pq->where('full_name', 'like', "%{$value}%");
                            })->orWhereHas('borrower', function ($bq) use ($value) {
                                $bq->where('first_name', 'like', "%{$value}%")
                                    ->orWhere('last_name', 'like', "%{$value}%")
                                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$value}%"]);
                            });
                        });
                    }
                }
            }
            unset($rawFilters['customer_name']);
        }

        // Type column filter: typed as the table shows it ("Borrower Fee"), but
        // stored as `borrower_fee`, so match the stored form.
        if (isset($rawFilters['type'])) {
            $typeFilter = $rawFilters['type'];
            $constraints = $typeFilter['constraints'] ?? [$typeFilter];
            foreach (is_array($constraints) ? $constraints : [] as $c) {
                $value = trim((string) ($c['value'] ?? ''));
                if ($value !== '') {
                    $stored = str_replace(' ', '_', strtolower($value));
                    $query->where('invoices.type', 'like', "%{$stored}%");
                }
            }
            unset($rawFilters['type']);
        }

        // Filter by Payee (Compatibility)
        if (!empty($filters['payee']) && $filters['payee'] !== Invoice::PAYEE_ALL) {
            $query->where(function ($q) use ($filters) {
                $q->whereHas('partner', function ($pq) use ($filters) {
                    $pq->where('full_name', 'like', '%' . $filters['payee'] . '%');
                })->orWhereHas('borrower', function ($bq) use ($filters) {
                    $bq->where('first_name', 'like', '%' . $filters['payee'] . '%')
                        ->orWhere('last_name', 'like', '%' . $filters['payee'] . '%')
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ['%' . $filters['payee'] . '%']);
                });
            });
        }

        // Filter by Status (Compatibility)
        if (!empty($filters['status'])) {
            $query->where('invoices.status', strtolower($filters['status']));
        }

        // Payer: Partner Only / Borrower Only / All Invoice.
        $this->applyPayerFilter($query, $filters);

        // Invoice type (enrollment, installment, commission payout, ...).
        if (!empty($filters['type'])) {
            $query->where('invoices.type', $filters['type']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        $sortFieldMap = [
            'date' => 'invoices.created_at',
            'created_at' => 'invoices.created_at',
            'amount' => 'invoices.payment_amount',
            'payment_amount' => 'invoices.payment_amount',
            'status' => 'invoices.status',
            'invoice_id' => 'invoices.invoice_id',
        ];

        $actualSortField = $sortFieldMap[$sortField] ?? 'invoices.created_at';

        $skipSorting = false;
        if ($sortField === 'customer_name') {
            $query->leftJoin('partners', 'invoices.partner_id', '=', 'partners.id')
                ->leftJoin('borrowers', 'invoices.borrower_id', '=', 'borrowers.id')
                ->orderBy(DB::raw("COALESCE(partners.full_name, CONCAT(borrowers.first_name, ' ', borrowers.last_name))"), $sortOrder);
            $skipSorting = true;
        } elseif ($sortField === 'partner_name') {
            $query->leftJoin('partners', 'invoices.partner_id', '=', 'partners.id')
                ->orderBy('partners.full_name', $sortOrder);
            $skipSorting = true;
        } elseif ($sortField === 'borrower_name') {
            $query->leftJoin('borrowers', 'invoices.borrower_id', '=', 'borrowers.id')
                ->orderBy(DB::raw("CONCAT(borrowers.first_name, ' ', borrowers.last_name)"), $sortOrder);
            $skipSorting = true;
        }

        // Apply remaining filters via QueryFilter
        $query = QueryFilter::apply(
            $query,
            $actualSortField,
            $sortOrder,
            $rawFilters,
            [
                'skip_sorting' => $skipSorting,
                'aliases' => [
                    'amount' => 'payment_amount',
                    'status' => 'status',
                    'invoice_id' => 'invoice_id',
                ]
            ]
        );

        // Perform stats calculation on fully filtered query JUST BEFORE pagination
        $stats = $this->getStatsFromQuery(clone $query);

        $perPage = isset($filters['per_page']) ? max(1, min((int) $filters['per_page'], 100)) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            InvoiceResource::class,
            __('invoice.list_retrieved'),
            200,
            ['stats' => $stats]
        );
    }

    /**
     * Get invoice statistics from cloned Query Builder using optimized SQL aggregation.
     *
     * @param mixed $query
     * @return array
     */
    public function getStatsFromQuery($query): array
    {
        $cloned = clone $query;
        $cloned->setEagerLoads([]);
        $cloned->getQuery()->columns = null;
        $cloned->getQuery()->orders = null;

        $result = $cloned->selectRaw("
            COALESCE(SUM(invoices.payment_amount), 0) as total_sent,
            COALESCE(SUM(CASE WHEN invoices.status IN (?, ?) THEN invoices.payment_amount ELSE 0 END), 0) as paid_amount,
            COALESCE(SUM(CASE WHEN invoices.status IN (?, ?, ?) THEN invoices.payment_amount ELSE 0 END), 0) as unpaid_amount
        ", [
            Invoice::STATUS_PAID,
            Invoice::STATUS_SUCCESS,
            Invoice::STATUS_UNPAID,
            Invoice::STATUS_PENDING,
            Invoice::STATUS_OVERDUE
        ])->first();

        $totalSent = (float) ($result->total_sent ?? 0);
        $paidAmount = (float) ($result->paid_amount ?? 0);
        $unpaidAmount = (float) ($result->unpaid_amount ?? 0);
        $collectionRate = $totalSent > 0 ? ($paidAmount / $totalSent) * 100 : 0;

        return [
            'invoices_sent' => currencyFormat($totalSent),
            'paid_invoices' => currencyFormat($paidAmount),
            'unpaid_invoices' => currencyFormat($unpaidAmount),
            'collection_rate' => round($collectionRate, 2),
        ];
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

        // RBAC: Partner & Borrower Wise
        $this->applyRbacScope($query, $authUser);

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['payee']) && $filters['payee'] !== Invoice::PAYEE_ALL) {
            $query->where('customer_name', 'like', '%' . $filters['payee'] . '%');
        }

        $this->applyPayerFilter($query, $filters);

        return $this->getStatsFromQuery($query);
    }

    /**
     * The payer filter (`is_mine`): true is Partner Only, false is Borrower Only,
     * and null or '' applies no filter.
     *
     * Partner Only: what a partner pays. That covers an invoice billed to a
     * partner, one the partner pays for a borrower, and every borrower fee. The
     * partner always pays a borrower fee, whichever entity the row was recorded
     * on (InvoicePartyResolver bills it from the host too).
     *
     * Borrower Only: an invoice billed to a borrower that the borrower pays, and
     * never a borrower fee. A commission payout to a host user (payee User) is
     * neither, so it is listed only under All Invoice (BUG-178).
     */
    private function applyPayerFilter($query, array $filters): void
    {
        if (!array_key_exists('is_mine', $filters) || $filters['is_mine'] === null || $filters['is_mine'] === '') {
            return;
        }

        $isMine = filter_var($filters['is_mine'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isMine === null) {
            return;
        }

        if ($isMine) {
            $query->where(fn ($q) => $q->where('invoices.is_partner_paying', true)
                ->orWhere('invoices.entity_type', Partner::class)
                ->orWhere('invoices.type', Invoice::INVOICE_TYPE_BORROWER_FEE));
        } else {
            $query->where('invoices.is_partner_paying', false)
                ->where('invoices.entity_type', Borrower::class)
                // Null-safe: an untyped legacy row is still the borrower's.
                ->where(fn ($q) => $q->whereNull('invoices.type')
                    ->orWhere('invoices.type', '!=', Invoice::INVOICE_TYPE_BORROWER_FEE));
        }
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
        $authUser = Auth::user();
        $query = Invoice::query()->with(['partner', 'borrower', 'subscription'])->select('invoices.*');
        $this->applyRbacScope($query, $authUser);

        return (clone $query)->where('invoices.id', $id)->first()
            ?? (clone $query)->where('invoices.invoice_id', $id)->first();
    }

    /**
     * Download Invoice PDF.
     */
    public function downloadInvoice($id)
    {
        $invoice = $this->find($id);
        if (!$invoice)
            throw new \Exception("Invoice not found");

        $business_info = $this->getBusinessInfo($invoice);

        Log::info('Invoice business info', ['invoice_id' => $id, 'business_info' => $business_info]);

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
        if (!$invoice)
            throw new \Exception("Invoice not found");

        $business_info = $this->getBusinessInfo($invoice);

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


        // A last installment adjusted for rounding says so (BUG-049).
        $finalInstallment = $invoice->finalInstallmentNote();

        try {
            app(Notifier::class)->send($finalInstallment ? 'invoice.final_installment' : 'invoice.sent', $invoice->is_partner_paying ? $invoice->partner : $invoice->borrower, [
                'id' => $invoice->id,
                'invoice' => $invoice->invoice_id ?: $invoice->id,
                'amount' => ($invoice->currency ?: 'USD') . ' ' . number_format((float) $invoice->payment_amount, 2),
            ] + ($finalInstallment ? [
                'difference' => $finalInstallment['difference'],
                'monthly' => $finalInstallment['monthly'],
                'total' => $finalInstallment['total'],
            ] : []), mail: (new InvoiceMail($invoice, $pdfContent))->to($recipientEmail));
        } catch (\Exception $e) {
            Log::error('Failed to send invoice email', ['error' => $e->getMessage()]);
        }
        return true;
    }

    /**
     * Helper to get business info for templates with white-label support.
     */
    private function getBusinessInfo(?Invoice $invoice = null): array
    {
        // Branded from the INVOICE, not getConfigPartner(). The request tenant is
        // whoever is logged in - a host admin downloading a partner's client
        // invoice was getting the host's brand on that partner's paper.
        // invoiceBranding() applies the shared rule: the brand belongs to whoever
        // is being paid - the host on a Partner invoice, invoices.partner_id on a
        // Borrower one.
        $resolved = invoiceBranding(
            $invoice?->entity_type,
            $invoice?->partner_id ? (int) $invoice->partner_id : null
        );
        $targetPartner = $resolved['partner'];
        $branding = $resolved['branding'];

        // Invoice paper is white, so the document logo comes first, falling back
        // to the sidebar logo when this tenant uploaded no document one. Resolved
        // through AppConfigurationService, which walks the whole fallback chain
        // (logo_document -> logo_mail -> logo_mobile) - reading a single
        // key directly missed every partner who had only uploaded
        // under the newer surface names.
        //
        // Empty stays empty on purpose: no logo means no logo, rather than
        // another tenant's mark on this tenant's invoice.
        $logoPath = $resolved['logo'];

        return [
            'company_name' => $branding['site_name'] ?? '',
            'email' => $targetPartner?->email ?? '',
            'phone_number' => $targetPartner?->phone_number ?? '',
            'address_line_1' => $targetPartner?->address_line_1 ?? '',
            'city' => $targetPartner?->city ?? '',
            'state' => $targetPartner?->state ?? '',
            'postal_code' => $targetPartner?->postal_code ?? '',
            'country' => $targetPartner?->country ?? '',
            'website' => $targetPartner?->website ?? '',
            'business_logo_path' => $logoPath,
        ];
    }
}
