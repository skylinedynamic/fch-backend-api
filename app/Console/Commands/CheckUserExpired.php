<?php

namespace App\Console\Commands;

use App\Models\Invoice\Invoice;
use App\Models\User\User;
use App\Services\FCH\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\CronJobFailureMail;
use Illuminate\Support\Str;

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

    public function __construct(InvoiceService $invoiceService)
    {
        parent::__construct();
        $this->invoiceService = $invoiceService;
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

            // Logic: Filter users with is_paid = 1 and end_date <= today + generate_before_days
            $users = User::where('is_paid', 1)
                ->where('end_date', '<=', $targetDate)
                ->whereHas('partner', function ($query) {
                    $query->where('is_host', 0);
                })
                ->get();

            $this->info("Found " . $users->count() . " eligible users for subscription check.");

            foreach ($users as $user) {
                // Requirement: Only one invoice per month per user
                $invoiceExistsThisMonth = Invoice::where('entity_type', User::class)
                    ->where('entity_id', $user->id)
                    ->whereRaw("DATE_FORMAT(created_at, '%Y-%m') = ?", [$currentMonth])
                    ->exists();

                if ($invoiceExistsThisMonth) {
                    $this->info("Invoice already exists for this month for user: " . $user->email);
                    continue;
                }

                // Find last paid invoice to get the amount (Accurate Code Pattern)
                $lastInvoice = Invoice::where('entity_type', User::class)
                    ->where('entity_id', $user->id)
                    ->where('status', Invoice::STATUS_PAID)
                    ->orderBy('id', 'desc')
                    ->first();

                $amount = $lastInvoice ? $lastInvoice->payment_amount : 0;

                if ($amount <= 0) {
                    $this->warn("Could not determine invoice amount for user: " . $user->email . ". Skipping.");
                    continue;
                }

                try {
                    $invoiceId = $this->invoiceService->generateInvoiceId();
                    Invoice::create([
                        'invoice_id' => $invoiceId,
                        'partner_id' => $user->partner_id,
                        'entity_type' => User::class,
                        'entity_id' => $user->id,
                        'customer_name' => $user->name,
                        'email' => $user->email,
                        'payment_amount' => $amount,
                        'payment_date' => null,
                        'status' => Invoice::STATUS_UNPAID,
                        'created_by' => null,
                    ]);

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
