<?php

namespace App\Console\Commands;

use App\Mail\FundingRequest\BorrowerFundingApplicationReminder;
use App\Mail\FundingRequest\PartnerFundingApplicationReminder;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendFundingApplicationReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'funding:application-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send automated reminders to borrowers who have not started their Funding Application after approval';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();

        // Query approved funding requests that DO NOT have a funding application
        $allFundingRequests = FundingRequest::with(['borrower.user', 'partner', 'borrower.partner'])
            ->where('status', FundingRequest::STATUS_APPROVED)
            ->doesntHave('fundingApplication')
            ->whereNotNull('approved_at')
            ->get();

        $count = 0;


        foreach ($allFundingRequests as $fundingRequest) {
            if (!$fundingRequest->borrower) continue;
   
            $approvedAt = Carbon::parse($fundingRequest->approved_at);
            $hoursSinceApproval = $approvedAt->diffInHours($now);
            
            // Calculate full calendar days difference, ignoring the exact time of day
            $daysSinceApproval = $approvedAt->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());

            $user = $fundingRequest->borrower->user;
            if (!$user) continue;

            $reminderDays = config('funding.application_reminder_days', [1, 3, 7]);
            
            // Reverse so we check the longest time first
            $reversedDays = array_reverse($reminderDays);
            $totalSteps = count($reminderDays);
            
            foreach ($reversedDays as $index => $days) {
                // Determine which step this corresponds to (1 for 1d, 2 for 3d, 3 for 7d)
                $step = $totalSteps - $index;
                
                if ($daysSinceApproval >= $days && $fundingRequest->reminder_step < $step) {
                    
                    $borrowerEmail = $user->email;
                    
                    $partner = $fundingRequest->partner;
                    $hostPartner = Partner::host() ?? Partner::where('is_host', true)->first();
                    
                    $partnerEmail = $partner?->email;
                    $hostEmail = $hostPartner?->email;
                    
                    $emailSent = false;

                    // Send Borrower Email
                    if ($borrowerEmail) {
                        Log::info("FundingRequest #{$fundingRequest->id}: Dispatching Day {$days} Application Reminder to borrower ({$borrowerEmail})");
                        Mail::to($borrowerEmail)->queue(new BorrowerFundingApplicationReminder($fundingRequest));
                        $emailSent = true;
                    }

                    // Send Partner Email
                    if ($partnerEmail) {
                        Log::info("FundingRequest #{$fundingRequest->id}: Dispatching Day {$days} Application Reminder to partner ({$partnerEmail})");
                        Mail::to($partnerEmail)->queue(new PartnerFundingApplicationReminder($fundingRequest, $partner, false));
                        $emailSent = true;
                    }

                    // Send Host Email
                    if ($hostEmail && $hostEmail !== $partnerEmail && $hostEmail !== $borrowerEmail) {
                        Log::info("FundingRequest #{$fundingRequest->id}: Dispatching Day {$days} Application Reminder to Host ({$hostEmail})");
                        Mail::to($hostEmail)->queue(new PartnerFundingApplicationReminder($fundingRequest, $hostPartner, true));
                        $emailSent = true;
                    }

                    if ($emailSent) {
                        $fundingRequest->update(['reminder_step' => $step]);
                        $count++;
                    }
                    
                    break; // Only send the highest applicable reminder in this run
                }
            }
        }

        $this->info("Processed $count funding application reminders successfully.");
    }
}
