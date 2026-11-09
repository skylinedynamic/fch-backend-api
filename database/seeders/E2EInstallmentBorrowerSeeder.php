<?php

namespace Database\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * A second borrower-portal E2E account, on an installment plan whose billing
 * period is still running, for the cancel-and-resume spec
 * (client-fch: e2e/sidebar/billing-details/billing-details-resume-save.spec.ts,
 * BUG-069). The main E2E account paid one time and has nothing to cancel.
 *
 * A copy of the main E2E borrower (same partner, a complete profile) under
 * its own login, so the spec never touches the shared account. Every run puts
 * the plan back the same: Active, 4 of 12 installments paid, the end date 20
 * days ahead, no open invoice. With E2E_INSTALLMENT_ENDED=1 it is instead
 * cancelled and its end date 3 days past - the portal locked down.
 *
 * Local/testing only.
 *
 *   php artisan db:seed --class=E2EInstallmentBorrowerSeeder
 *   E2E_INSTALLMENT_ENDED=1 php artisan db:seed --class=E2EInstallmentBorrowerSeeder
 */
class E2EInstallmentBorrowerSeeder extends Seeder
{
    public const EMAIL = 'e2e-installment-borrower@fourcornerholdings.test';

    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EInstallmentBorrowerSeeder only runs in local/testing.');
        }

        $email = env('E2E_INSTALLMENT_BORROWER_EMAIL', self::EMAIL);
        $password = env('E2E_BORROWER_PASSWORD', '12345678');
        $templateUser = User::where('email', env('E2E_BORROWER_EMAIL', 'craig-borrower@fourcornerholdings.com'))->firstOrFail();
        $template = Borrower::findOrFail($templateUser->entity_id);

        $user = User::where('email', $email)->first();
        $borrower = $user ? Borrower::find($user->entity_id) : null;

        if (!$borrower) {
            $borrower = $template->replicate();
            $borrower->forceFill([
                'signup_id' => 'SIGN-E2E-INSTALLMENT',
                'email' => $email,
                'first_name' => 'E2E',
                'last_name' => 'Installment',
                // No card on file: nothing of this account is ever auto-charged.
                'stripe_customer_id' => null,
                'default_payment_method' => null,
            ])->save();
        }

        if (!$user) {
            $user = $templateUser->replicate();
            $user->forceFill([
                'email' => $email,
                'name' => 'E2E Installment',
                'entity_id' => $borrower->id,
            ]);
        }
        $user->forceFill(['password' => Hash::make($password), 'email_verified_at' => now()])->save();

        $subscription = Subscription::where('entity_type', Borrower::class)->where('entity_id', $borrower->id)->latest('id')->first()
            ?? new Subscription(['entity_type' => Borrower::class, 'entity_id' => $borrower->id]);

        $ended = (bool) env('E2E_INSTALLMENT_ENDED', false);

        $subscription->forceFill([
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
            'installment_type' => 1,
            'installment_count' => 12,
            'installments_paid' => 4,
            'down_payment' => 1000.00,
            'payable_amount' => 7696.70,
            'total_subscription_amount' => 6696.70,
            'subscription_amount_per_month' => 558.06,
            'monthly_platform_fee' => 0,
            'current_phase' => Subscription::PHASE_INSTALLMENT,
            'status' => $ended ? Subscription::STATUS_CANCELLED : Subscription::STATUS_ACTIVE,
            'subscription_start_date' => today()->subMonths(4),
            'first_payment_date' => today()->subMonths(4),
            'subscription_end_date' => $ended ? today()->subDays(3) : today()->addDays(20),
            'enrollment_completed_at' => null,
            'current_cycle_invoice_id' => null,
        ])->save();

        Invoice::where('subscription_id', $subscription->id)->openCycle()->delete();

        $this->command?->info("E2E installment borrower ready: {$email} (subscription {$subscription->id})");
    }
}
