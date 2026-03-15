<?php

namespace App\Console\Commands;

use App\Mail\CronJobFailureMail;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Models\ActivityLog\ActivityLog;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;

class CheckUserExpired extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:check-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for users whose paid status is expiring or expired and create renewal invoices.';

    /**
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     * @var ActivityLogService
     */
    protected $activityLogService;

    public function __construct(InvoiceService $invoiceService, ActivityLogService $activityLogService)
    {
        parent::__construct();
        $this->invoiceService = $invoiceService;
        $this->activityLogService = $activityLogService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $generateBeforeDays = config('subscription.generate_before_days', 5);
            $targetDate = Carbon::today()->addDays($generateBeforeDays)->toDateString();
            $currentMonth = Carbon::today()->format('Y-m');

            // Logic: Filter active users with is_paid = 1 and end_date <= today + generate_before_days
            // User must be Active AND Partner must be Active
            $users = User::where('is_paid', 1)
                ->where('status', User::STATUS['active'])
                ->where('end_date', '<=', $targetDate)
                ->where(function ($query) {
                    $query->where('entity_type', Employee::class)
                        ->orWhereNull('entity_type');
                })
                ->whereHas('partner', function ($query) {
                    $query->where('is_host', 0)
                        ->where('status', Partner::STATUS_ACTIVE);
                })
                ->get();

            $this->info("Found " . $users->count() . " eligible users for subscription check.");

            foreach ($users as $user) {
                // Requirement: Only one invoice per month per user
                $invoiceExistsThisMonth = Invoice::where(function ($query) use ($user) {
                    $query->where(function ($q) use ($user) {
                        $q->where('entity_type', User::class)
                            ->where('entity_id', $user->id);
                    })
                        ->orWhere(function ($q) use ($user) {
                            $q->where('entity_type', Employee::class)
                                ->where('entity_id', $user->entity_id);
                        });
                })
                    ->whereRaw("DATE_FORMAT(created_at, '%Y-%m') = ?", [$currentMonth])
                    ->exists();


                if ($invoiceExistsThisMonth) {
                    $this->info("Invoice already exists for this month for user: " . $user->email);
                    continue;
                }

                // Find last paid invoice to get the amount (Accurate Code Pattern)
                $lastInvoice = Invoice::where(function ($query) use ($user) {
                    $query->where(function ($q) use ($user) {
                        // Case 1: Invoice stored as User
                        $q->where('entity_type', User::class)
                            ->where('entity_id', $user->id);
                    })
                        ->orWhere(function ($q) use ($user) {
                            // Case 2: Invoice stored as Employee
                            $q->where('entity_type', Employee::class)
                                ->where('entity_id', $user->entity_id);
                        });
                })
                    ->where('status', Invoice::STATUS_PAID)
                    ->orderByDesc('id')
                    ->first();

                $amount = $lastInvoice ? $lastInvoice->payment_amount : 0;

                if ($amount <= 0) {
                    $this->warn("Could not determine invoice amount for user: " . $user->email . ". Skipping.");
                    continue;
                }

                try {
                    $invoiceId = $this->invoiceService->generateInvoiceId();
                    $invoice = Invoice::create([
                        'invoice_id' => $invoiceId,
                        'partner_id' => $user->partner_id,
                        'entity_type' => $user->entity_type,
                        'entity_id' => $user->entity_id,
                        'customer_name' => $user->name,
                        'email' => $user->email,
                        'payment_amount' => $amount,
                        'payment_date' => null,
                        'status' => Invoice::STATUS_UNPAID,
                        'created_by' => null,
                    ]);

                    // Log System Event for Batch Process Invoice Creation
                    $this->activityLogService->logModelEvent(
                        $invoice,
                        ActivityLog::LOG_TYPE_BATCH_PROCESS
                    );

                    $this->info("Created renewal invoice for user: " . $user->email);
                } catch (\Exception $e) {
                    Log::error("Failed to create renewal invoice for user: " . $user->email, ['error' => $e->getMessage()]);
                    $this->error("Error creating invoice for user: " . $user->email);

                    // Send granular failure email
                    $emails = explode(',', config('app.cron_notify_emails', ''));
                    $emails = array_filter(array_map('trim', $emails));

                    if (!empty($emails)) {
                        Mail::to($emails)->send(new CronJobFailureMail([
                            'job_name' => 'CheckUserExpired (users:check-expired) - Individual User Failure',
                            'error_message' => $e->getMessage(),
                            'timestamp' => now()->toDateTimeString(),
                            'stack_trace' => $e->getTraceAsString(),
                            'user_email' => $user->email,
                            'user_id' => $user->id,
                            'partner_name' => $user->partner?->full_name ?? 'N/A'
                        ]));
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error("CheckUserExpired Job Failed: " . $e->getMessage());

            $emails = explode(',', config('app.cron_notify_emails', ''));
            $emails = array_filter(array_map('trim', $emails));

            if (!empty($emails)) {
                Mail::to($emails)->send(new CronJobFailureMail([
                    'job_name' => 'CheckUserExpired (users:check-expired)',
                    'error_message' => $e->getMessage(),
                    'timestamp' => now()->toDateTimeString(),
                    'stack_trace' => $e->getTraceAsString()
                ]));
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
