<?php

namespace App\Services\FCH\Common\Payment;

use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Traits\PaginatesApi;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Services\GlobalPayment\PaymentMailService;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Global\Datatable\QueryFilter;
use App\Services\GlobalPayment\UnifiedPaymentService;
use App\Services\FCH\Subscription\SubscriptionService;
use App\Http\Resources\FCH\Common\Payment\PaymentResource;

class PaymentService
{
    use PaginatesApi;

    /**
     * Apply RBAC / user scope to payment query following exact Invoice logic.
     */
    protected function applyRbacScope(Builder $query, mixed $authUser = null): Builder
    {
        $authUser = $authUser ?? Auth::user();

        if (!$authUser) {
            return $query;
        }

        // RBAC: Borrower Wise
        if ($authUser->entity_type === Borrower::class && !empty($authUser->entity_id)) {
            return $query->where('payments.borrower_id', $authUser->entity_id);
        }

        // RBAC: Partner & Host Wise
        $partnerId = $authUser->partner_id;
        $partner = $authUser->partner ?? ($partnerId ? Partner::find($partnerId) : null);
        $isHost = $partner ? ((int) ($partner->is_host ?? 0) === 1) : false;

        if (!$partner) {
            $hostPartnerId = Partner::where('is_host', 1)->value('id');
            if ($hostPartnerId) {
                $isHost = true;
                $partnerId = $hostPartnerId;
            }
        } else {
            $partnerId = $partner->id;
        }

        if ($isHost) {
            // Host sees:
            // 1. All partner payments (is_partner_paying = true or entity_type = Partner) -> partner paying host
            // 2. ONLY host's own client payments (partner_id = hostPartnerId AND is_partner_paying = false)
            // Host CANNOT see non-host partners' client payments!
            $query->where(function ($q) use ($partnerId) {
                $q->where('payments.is_partner_paying', true)
                    ->orWhere('payments.entity_type', Partner::class)
                    ->orWhere(function ($sub) use ($partnerId) {
                        $sub->where('payments.partner_id', $partnerId)
                            ->where(function ($s2) {
                                $s2->where('payments.is_partner_paying', false)
                                    ->orWhereNull('payments.is_partner_paying');
                            });
                    });
            });
        } elseif ($partnerId) {
            // Non-host partner sees ONLY his own payments (partner paying host) and his client payments (borrower paying partner)
            $query->where(function ($q) use ($partnerId) {
                $q->where('payments.partner_id', $partnerId)
                    ->orWhere(function ($q2) use ($partnerId) {
                        $q2->where('payments.entity_type', Partner::class)
                            ->where('payments.entity_id', (string) $partnerId);
                    });
            });
        }

        return $query;
    }

    /**
     * List payments with pagination and filtering.
     */
    public function list(array $filters)
    {
        $authUser = Auth::user();
        $query = Payment::query()
            ->with([
                'partner:id,full_name',
                'borrower:id,first_name,last_name',
                'invoice:id,invoice_id',
                'entity'
            ])
            ->select('payments.*');

        // RBAC: Partner & Borrower Wise (Following Invoice Logic)
        $this->applyRbacScope($query, $authUser);

        // Accurate Date Range: transaction_date (Consistent with InvoiceService)
        // Accurate Date Range Filtering (Inclusive of Full Day)
        if (!empty($filters['date_from'])) {
            $query->whereDate('payments.transaction_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('payments.transaction_date', '<=', $filters['date_to']);
        }

        if (!empty($filters['date_range']) && is_array($filters['date_range'])) {
            if (!empty($filters['date_range'][0])) {
                $query->whereDate('payments.transaction_date', '>=', $filters['date_range'][0]);
            }
            if (!empty($filters['date_range'][1])) {
                $query->whereDate('payments.transaction_date', '<=', $filters['date_range'][1]);
            }
        }

        // Compatibility with payee_id
        if (!empty($filters['payee_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('payments.partner_id', $filters['payee_id'])
                    ->orWhere('payments.borrower_id', $filters['payee_id']);
            });
        }

        // Status (Compatibility)
        $statusFilter = $this->extractFilterValue($filters['status'] ?? null);
        if (!empty($statusFilter)) {
            $query->where('payments.status', strtolower((string) $statusFilter));
        }

        if (!empty($filters['invoice_id'])) {
            $query->where('payments.invoice_id', $filters['invoice_id']);
        }

        $envFilter = $this->extractFilterValue($filters['is_environment'] ?? null);
        if (isset($envFilter) && $envFilter !== '' && $envFilter !== null) {
            $query->where('payments.is_environment', $envFilter);
        }

        $methodFilter = $this->extractFilterValue($filters['payment_from'] ?? null);
        if (!empty($methodFilter)) {
            $query->where('payments.payment_from', (string) $methodFilter);
        }

        $typeFilter = $this->extractFilterValue($filters['transaction_type'] ?? null);
        if (!empty($typeFilter)) {
            $targetType = strtolower((string) $typeFilter);
            $isNonHostPartner = !empty($authUser?->partner) && (int) ($authUser->partner->is_host ?? 0) === 0;

            if ($isNonHostPartner) {
                if ($targetType === 'debit') {
                    $query->where(function ($q) {
                        $q->where('payments.is_partner_paying', 1)
                            ->orWhere('payments.entity_type', Partner::class)
                            ->orWhere('payments.status', 'refunded')
                            ->orWhere('payments.payment_type', 'payout');
                    });
                } else if ($targetType === 'credit') {
                    $query->where(function ($q) {
                        $q->where(function ($q2) {
                            $q2->where('payments.is_partner_paying', 0)
                                ->orWhereNull('payments.is_partner_paying');
                        })
                            ->where('payments.entity_type', '!=', Partner::class)
                            ->where('payments.status', '!=', 'refunded')
                            ->where('payments.payment_type', '!=', 'payout');
                    });
                }
            } else {
                if ($targetType === 'debit') {
                    $query->where(function ($q) {
                        $q->where('payments.status', 'refunded')
                            ->orWhere('payments.payment_type', 'payout');
                    });
                } else if ($targetType === 'credit') {
                    $query->where(function ($q) {
                        $q->where('payments.status', '!=', 'refunded')
                            ->where('payments.payment_type', '!=', 'payout');
                    });
                }
            }
        }

        // Efficiently extract global search term
        $globalSearch = $filters['filters']['global']['value'] ?? ($filters['search'] ?? null);
        $search = !empty($globalSearch) ? trim((string) $globalSearch) : null;

        if ($search) {
            $query->search($search);
        }

        // PrimeReact Structured Filters
        $rawFilters = $filters['filters'] ?? [];
        unset($rawFilters['global'], $rawFilters['status'], $rawFilters['is_environment'], $rawFilters['payment_from'], $rawFilters['transaction_type']);

        // Manual Date Handling for structured filters
        if (isset($rawFilters['date'])) {
            $dateFilter = $rawFilters['date'];
            if (isset($dateFilter['constraints']) && is_array($dateFilter['constraints'])) {
                foreach ($dateFilter['constraints'] as $constraint) {
                    $value = $constraint['value'] ?? null;
                    $mode = $constraint['matchMode'] ?? 'dateIs';
                    if ($value) {
                        if ($mode === 'dateIs') {
                            $query->whereDate('payments.transaction_date', '=', $value);
                        } elseif ($mode === 'dateBefore') {
                            $query->whereDate('payments.transaction_date', '<', $value);
                        } elseif ($mode === 'dateAfter') {
                            $query->whereDate('payments.transaction_date', '>', $value);
                        }
                    }
                }
            }
            unset($rawFilters['date']);
        }

        // Handle structured filter for invoice_id (INV-...)
        if (isset($rawFilters['invoice_id'])) {
            $invoiceFilter = $rawFilters['invoice_id'];
            $constraints = $invoiceFilter['constraints'] ?? [$invoiceFilter];
            if (is_array($constraints)) {
                foreach ($constraints as $c) {
                    $value = $c['value'] ?? null;
                    if ($value) {
                        $query->whereHas('invoice', function ($q) use ($value) {
                            $q->where('invoice_id', 'like', "%{$value}%");
                        });
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

        // Handle "company" filter specifically
        if (isset($rawFilters['company'])) {
            $companyFilter = $rawFilters['company'];
            $constraints = $companyFilter['constraints'] ?? [$companyFilter];
            if (is_array($constraints)) {
                foreach ($constraints as $c) {
                    $value = $c['value'] ?? null;
                    if ($value) {
                        $query->where(function ($q) use ($value) {
                            $q->whereHas('partner', function ($pq) use ($value) {
                                $pq->where('company_name', 'like', "%{$value}%");
                            })->orWhereHas('borrower', function ($bq) use ($value) {
                                $bq->where('business_name', 'like', "%{$value}%");
                            });
                        });
                    }
                }
            }
            unset($rawFilters['company']);
        }

        // Compatibility with payee_id
        if (!empty($filters['payee_id'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('payments.partner_id', $filters['payee_id'])
                    ->orWhere('payments.borrower_id', $filters['payee_id']);
            });
            unset($rawFilters['payee_id']);
        }

        // Sorting
        $sortField = $filters['sort_field'] ?? 'transaction_date';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        $sortFieldMap = [
            'date' => 'payments.transaction_date',
            'transaction_date' => 'payments.transaction_date',
            'amount' => 'payments.amount',
            'status' => 'payments.status',
            'from' => 'payments.payment_from',
            'payment_type' => 'payments.payment_type',
            'invoice_id' => 'payments.invoice_id',
        ];

        $actualSortField = $sortFieldMap[$sortField] ?? 'payments.transaction_date';

        $skipSorting = false;
        if ($sortField === 'company') {
            $query->leftJoin('partners', 'payments.partner_id', '=', 'partners.id')
                ->leftJoin('borrowers', 'payments.borrower_id', '=', 'borrowers.id')
                ->orderBy(DB::raw('COALESCE(partners.company_name, borrowers.business_name)'), $sortOrder);
            $skipSorting = true;
        } elseif ($sortField === 'invoice_id') {
            $query->leftJoin('invoices', 'payments.invoice_id', '=', 'invoices.id')
                ->orderBy('invoices.invoice_id', $sortOrder);
            $skipSorting = true;
        } elseif ($sortField === 'partner_name') {
            $query->leftJoin('partners', 'payments.partner_id', '=', 'partners.id')
                ->orderBy('partners.full_name', $sortOrder);
            $skipSorting = true;
        } elseif ($sortField === 'borrower_name') {
            $query->leftJoin('borrowers', 'payments.borrower_id', '=', 'borrowers.id')
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
                    'from' => 'payment_from',
                    'transaction_id' => 'transaction_id',
                    'amount' => 'amount',
                    'status' => 'status',
                    'payment_type' => 'payment_type'
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
            PaymentResource::class,
            __('payment.list_retrieved'),
            200,
            ['stats' => $stats]
        );
    }

    /**
     * Get payment statistics from cloned Query Builder using optimized SQL aggregation.
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
            COALESCE(SUM(payments.amount), 0) as total_payments,
            COALESCE(SUM(CASE WHEN payments.status IN (?, ?) THEN payments.amount ELSE 0 END), 0) as completed,
            COALESCE(SUM(CASE WHEN payments.status = ? THEN payments.amount ELSE 0 END), 0) as pending,
            COALESCE(SUM(CASE WHEN payments.status = ? THEN payments.amount ELSE 0 END), 0) as processing
        ", [
            Payment::STATUS_SUCCEEDED,
            'success',
            Payment::STATUS_PENDING,
            Payment::STATUS_PROCESSING
        ])->first();

        $totalPayments = (float) ($result->total_payments ?? 0);
        $completed = (float) ($result->completed ?? 0);
        $pending = (float) ($result->pending ?? 0);
        $processing = (float) ($result->processing ?? 0);

        return [
            'total_payments' => currencyFormat($totalPayments),
            'completed' => currencyFormat($completed),
            'pending' => currencyFormat($pending),
            'processing' => currencyFormat($processing),
        ];
    }

    /**
     * Helper to extract scalar value from simple values or PrimeReact structured filter arrays.
     */
    protected function extractFilterValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_key_exists('value', $value)) {
                return $value['value'];
            }
            if (isset($value['constraints']) && is_array($value['constraints']) && isset($value['constraints'][0])) {
                return $value['constraints'][0]['value'] ?? null;
            }
            return null;
        }
        return $value;
    }

    /**
     * Get payment statistics.
     */
    public function getStats(array $filters): array
    {
        $authUser = Auth::user();
        $query = Payment::query();

        if (!empty($authUser?->partner) && (int) ($authUser->partner->is_host ?? 0) === 0) {
            $partnerId = $authUser->partner_id;
            $query->where(function ($q) use ($partnerId) {
                $q->where('payments.partner_id', $partnerId)
                    ->orWhere(function ($q2) use ($partnerId) {
                        $q2->where('payments.entity_type', Partner::class)
                            ->where('payments.entity_id', (string) $partnerId);
                    });
            });
        }

        // Apply same filters as list
        if (!empty($filters['date_from'])) {
            $query->whereDate('payments.transaction_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('payments.transaction_date', '<=', $filters['date_to']);
        }

        $envFilter = $this->extractFilterValue($filters['is_environment'] ?? null);
        if (isset($envFilter) && $envFilter !== '' && $envFilter !== null) {
            $query->where('payments.is_environment', $envFilter);
        }

        $methodFilter = $this->extractFilterValue($filters['payment_from'] ?? null);
        if (!empty($methodFilter)) {
            $query->where('payments.payment_from', (string) $methodFilter);
        }

        $typeFilter = $this->extractFilterValue($filters['transaction_type'] ?? null);
        if (!empty($typeFilter)) {
            $targetType = strtolower((string) $typeFilter);
            $isNonHostPartner = !empty($authUser?->partner) && (int) ($authUser->partner->is_host ?? 0) === 0;

            if ($isNonHostPartner) {
                if ($targetType === 'debit') {
                    $query->where(function ($q) {
                        $q->where('payments.is_partner_paying', 1)
                            ->orWhere('payments.entity_type', Partner::class)
                            ->orWhere('payments.status', 'refunded')
                            ->orWhere('payments.payment_type', 'payout');
                    });
                } else if ($targetType === 'credit') {
                    $query->where(function ($q) {
                        $q->where(function ($q2) {
                            $q2->where('payments.is_partner_paying', 0)
                                ->orWhereNull('payments.is_partner_paying');
                        })
                            ->where('payments.entity_type', '!=', Partner::class)
                            ->where('payments.status', '!=', 'refunded')
                            ->where('payments.payment_type', '!=', 'payout');
                    });
                }
            } else {
                if ($targetType === 'debit') {
                    $query->where(function ($q) {
                        $q->where('payments.status', 'refunded')
                            ->orWhere('payments.payment_type', 'payout');
                    });
                } else if ($targetType === 'credit') {
                    $query->where(function ($q) {
                        $q->where('payments.status', '!=', 'refunded')
                            ->where('payments.payment_type', '!=', 'payout');
                    });
                }
            }
        }

        return $this->getStatsFromQuery($query);
    }

    /**
     * Get payment details with RBAC security scoping.
     */
    public function find($id)
    {
        $authUser = Auth::user();
        $query = Payment::query()->with(['partner', 'borrower', 'invoice', 'entity']);

        $this->applyRbacScope($query, $authUser);

        $payment = $query->find($id);
        return $payment ? new PaymentResource($payment) : null;
    }

    /**
     * Approve a processing payment with database lock and strict authorization check.
     */
    public function approvePayment($id, array $data = []): array
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return [
                'status' => false,
                'message' => __('payment.unauthenticated')
            ];
        }

        $note = trim((string) ($data['note'] ?? $data['details'] ?? ''));


        return DB::transaction(function () use ($id, $note, $authUser) {
            /** @var Payment|null $payment */
            $payment = Payment::lockForUpdate()->with(['invoice', 'partner', 'borrower', 'entity'])->find($id);

            if (!$payment) {
                return [
                    'status' => false,
                    'message' => __('payment.not_found')
                ];
            }

            if (strtolower((string) $payment->status) !== Payment::STATUS_PROCESSING) {
                return [
                    'status' => false,
                    'message' => __('payment.only_processing_can_be_approved')
                ];
            }

            if (!$payment->canApproveForUser($authUser)) {
                return [
                    'status' => false,
                    'message' => __('payment.unauthorized_approve')
                ];
            }

            $now = now();
            $userName = $authUser->name ?? __('payment.admin');
            $existingDetails = is_array($payment->details) ? $payment->details : [];
            $existingDetails['decision'] = 'approved';
            $existingDetails['approval_note'] = $note;
            $existingDetails['approved_at'] = $now->utc()->toDateTimeString();
            $existingDetails['approved_by'] = $userName;
            $existingDetails['approved_by_id'] = $authUser->id;

            // Update payment status to Succeeded, transaction_date to now, and save details
            $payment->update([
                'status' => Payment::STATUS_SUCCEEDED,
                'transaction_date' => $now,
                'details' => $existingDetails,
            ]);

            $invoice = $payment->invoice;
            $subscription = Subscription::find($invoice->subscription_id);

            // Update invoice status to Paid and set paid_at to now
            if ($invoice) {
                $invoice->update([
                    'status' => Invoice::STATUS_PAID,
                    'paid_at' => $now,
                ]);

                // Auto charge partner host fee upon approval of manual borrower payment
                if ($subscription && $invoice->entity_type === Borrower::class && $authUser->partner->is_host == false) {
                    if (!$subscription->first_payment_date) {
                        $borrowerFeeInvoice = Invoice::where('subscription_id', $subscription->id)
                            ->where('type', Invoice::INVOICE_TYPE_BORROWER_FEE)
                            ->first();
                        if ($borrowerFeeInvoice && $borrowerFeeInvoice->status = Invoice::STATUS_UNPAID) {
                            $unifiedPaymentService = app(UnifiedPaymentService::class);
                            $unifiedPaymentService->triggerPartnerAutoChargeForBorrowerInvoice($borrowerFeeInvoice);
                        }

                    }
                }

                // Update subscription data
                if ($subscription) {
                    $subscriptionService = app(SubscriptionService::class);
                    $subscriptionService->handleSuccessfulPayment($invoice->fresh(), $subscription);
                }

                // Send payment approval receipt email via centralized PaymentMailService
                app(PaymentMailService::class)->sendApproved($payment, $invoice, $note ?? null);
            }

            return [
                'status' => true,
                'message' => __('payment.approved_successfully'),
                'data' => new PaymentResource($payment->fresh())
            ];
        });
    }

    /**
     * Reject a processing payment with database lock and strict authorization check.
     */
    public function rejectPayment($id, array $data = []): array
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return [
                'status' => false,
                'message' => __('payment.unauthenticated')
            ];
        }

        $reason = trim((string) ($data['reason'] ?? $data['note'] ?? $data['details'] ?? ''));
        if (empty($reason)) {
            return [
                'status' => false,
                'message' => __('payment.rejection_reason_required')
            ];
        }

        return DB::transaction(function () use ($id, $reason, $authUser) {
            /** @var Payment|null $payment */
            $payment = Payment::lockForUpdate()->with(['invoice', 'partner', 'borrower', 'entity'])->find($id);

            if (!$payment) {
                return [
                    'status' => false,
                    'message' => __('payment.not_found')
                ];
            }

            if (strtolower((string) $payment->status) !== Payment::STATUS_PROCESSING) {
                return [
                    'status' => false,
                    'message' => __('payment.only_processing_can_be_rejected')
                ];
            }

            if (!$payment->canApproveForUser($authUser)) {
                return [
                    'status' => false,
                    'message' => __('payment.unauthorized_reject')
                ];
            }

            $now = now();
            $userName = $authUser->name ?? $authUser->full_name ?? ($authUser->first_name ? trim($authUser->first_name . ' ' . ($authUser->last_name ?? '')) : null) ?? $authUser->email ?? __('payment.admin');
            $existingDetails = is_array($payment->details) ? $payment->details : [];
            $existingDetails['decision'] = 'rejected';
            $existingDetails['rejection_reason'] = $reason;
            $existingDetails['rejected_at'] = $now->utc()->toDateTimeString();
            $existingDetails['rejected_by'] = $userName;
            $existingDetails['rejected_by_id'] = $authUser->id;

            $payment->update([
                'status' => Payment::STATUS_REJECTED,
                'details' => $existingDetails,
            ]);

            $invoice = $payment->invoice;

            if ($invoice) {
                $invoice->update([
                    'status' => Invoice::STATUS_UNPAID,
                ]);
            }

            // Send payment rejection notification email via centralized PaymentMailService
            app(PaymentMailService::class)->sendRejected($payment, $invoice, $reason);

            return [
                'status' => true,
                'message' => __('payment.rejected_successfully'),
                'data' => new PaymentResource($payment->fresh())
            ];
        });
    }
}
