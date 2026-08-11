<?php

namespace App\Console\Commands;

use App\Mail\CronJobFailureMail;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Invoice\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        //This is for checking if schedular is runing or not
        $runId = DB::table('scheduler_runs')->insertGetId([
            'command' => 'users:check-expired',
            'date' => now()->toDateString(),
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $generateBeforeDays = config('subscription.generate_before_days', 5);
            $targetDate = Carbon::today()->addDays($generateBeforeDays)->toDateString();
            $currentMonth = Carbon::today()->format('Y-m');

            // Query expiring User/Employee subscriptions from the subscriptions table
            $subscriptions = \App\Models\Subscription\Subscription::with('entity')
                ->whereIn('entity_type', [User::class, Employee::class])
                ->where('status', \App\Models\Subscription\Subscription::STATUS_ACTIVE)
                ->where('subscription_end_date', '<=', $targetDate)
                ->get();

            $users = collect();

            foreach ($subscriptions as $subscription) {
                $entity = $subscription->entity;
                if (!$entity) {
                    continue;
                }

                $userRecord = null;
                if ($subscription->entity_type === User::class) {
                    $userRecord = $entity;
                } elseif ($subscription->entity_type === Employee::class) {
                    $userRecord = User::where('entity_type', Employee::class)->where('entity_id', $entity->id)->first();
                }

                if (!$userRecord || $userRecord->status !== User::STATUS['active']) {
                    continue;
                }

                $partner = $userRecord->partner;
                if (!$partner || $partner->is_host == 1 || $partner->status !== Partner::STATUS_ACTIVE) {
                    continue;
                }

                // Bind subscription metadata to the user object to keep loop compatibility
                $userRecord->subscription_id = $subscription->id;
                $userRecord->monthly_platform_fee = $subscription->monthly_platform_fee;

                $users->push($userRecord);
            }

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

                $amount = $user->monthly_platform_fee ?: 49.97;

                try {
                    $partner = Partner::find($user->partner_id);
                    $invoiceId = $this->invoiceService->generateInvoiceId();
                    $newInvoice = Invoice::create([
                        'invoice_id' => $invoiceId,
                        'partner_id' => $user->partner_id,
                        'subscription_id' => $user->subscription_id,
                        'entity_type' => Partner::class,
                        'entity_id' => $user->partner_id,
                        'customer_name' => $partner ? ($partner->company_name ?: $partner->full_name) : $user->name,
                        'email' => $partner ? $partner->email : $user->email,
                        'payment_amount' => $amount,
                        'payment_date' => null,
                        'status' => Invoice::STATUS_UNPAID,
                        'is_partner_paying' => 1,
                        'created_by' => null,
                    ]);

                    // Log System Event for Batch Process Invoice Creation
                    $this->activityLogService->logModelEvent(
                        $newInvoice,
                        ActivityLog::LOG_TYPE_BATCH_PROCESS
                    );

                    $this->info("Created renewal invoice for user: " . $user->email);

                    // Accurate Code: Automatically send the renewal invoice email
                    try {
                        $this->invoiceService->sendInvoice($newInvoice->id);
                        $this->info("Renewal invoice email sent to Partner: " . $newInvoice->partner?->email);
                    } catch (\Exception $e) {
                        Log::error("Failed to send renewal invoice email to: " . $user->email, ['error' => $e->getMessage()]);
                        $this->error("Error sending email to user: " . $user->email);
                    }
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

            DB::table('scheduler_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            
            DB::table('scheduler_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
                'updated_at' => now(),
            ]);

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
