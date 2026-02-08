<?php

namespace Database\Seeders;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Payment\Payment;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPhase;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DemoSubscriberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedJohnPartnerSubscription();
        $this->seedMichaelChenBorrowerSubscription();
    }

    /**
     * Seed a paid subscription for John Partner (Partner 2).
     */
    protected function seedJohnPartnerSubscription(): void
    {
        // 1. Get Partner 2 (Tech Solutions Inc)
        $partner = Partner::where('company_name', 'Tech Solutions Inc')->first();
        if (!$partner) return;

        // 2. Register/Get User
        $user = User::where('email', 'john.p@example.com')->first();
        if (!$user) {
            $user = User::create([
                'name' => 'John Partner',
                'email' => 'john.p@example.com',
                'phone_number' => '+1234567892',
                'user_type' => User::PARTNER,
                'password' => Hash::make('12345678'),
                'status' => User::STATUS['active'],
                'email_verified_at' => now(),
                'partner_id' => $partner->id,
                'entity_type' => Partner::class,
                'entity_id' => $partner->id,
            ]);
        }

        // 3. Create Subscription
        $plan = SubscriptionPlan::where('entity_type', 'Partner')->first() ?? SubscriptionPlan::first();
        if (!$plan) return;

        $subscription = Subscription::updateOrCreate(
            ['entity_type' => Partner::class, 'entity_id' => $partner->id],
            [
                'subscription_plan_id' => $plan->id,
                'partner_type' => $plan->partner_type,
                'enrollment_type' => $plan->enrollment_type,
                'monthly_platform_fee' => $plan->platform_fee,
                'total_subscription_amount' => $plan->total_amount,
                'payable_amount' => $plan->payment_amount,
                'first_payment_date' => '2026-01-01',
                'subscription_start_date' => '2025-11-26',
                'subscription_end_date' => '2026-11-26',
                'status' => Subscription::STATUS_ACTIVE,
            ]
        );

        // 4. Create Phases
        SubscriptionPhase::updateOrCreate(
            ['subscription_id' => $subscription->id, 'phase' => SubscriptionPhase::PHASE_ENROLLMENT],
            ['installments_paid' => 1, 'next_installment_date' => '2026-11-27']
        );

        SubscriptionPhase::updateOrCreate(
            ['subscription_id' => $subscription->id, 'phase' => SubscriptionPhase::PHASE_PLATFORM_FEE],
            ['installments_paid' => 0, 'next_platform_fee_date' => null]
        );

        // 5. Create 1 Invoice and 1 Payment
        $this->createSinglePayment($partner, Partner::class, $user, $subscription);
    }

    /**
     * Seed a paid subscription for Michael Chen (Borrower ID 1).
     */
    protected function seedMichaelChenBorrowerSubscription(): void
    {
        // 1. Get Borrower 1
        $borrower = Borrower::find(1);
        if (!$borrower) return;

        // 2. Get User
        $user = User::where('entity_type', Borrower::class)->where('entity_id', $borrower->id)->first()
            ?? User::where('email', $borrower->email)->first();

        if (!$user) return;

        // 3. Create Subscription
        $plan = SubscriptionPlan::where('entity_type', 'Borrower')->first() ?? SubscriptionPlan::first();
        $subscription = Subscription::updateOrCreate(
            ['entity_type' => Borrower::class, 'entity_id' => $borrower->id],
            [
                'subscription_plan_id' => $plan->id,
                'partner_type' => $plan->partner_type,
                'enrollment_type' => $plan->enrollment_type,
                'monthly_platform_fee' => $plan->platform_fee,
                'total_subscription_amount' => $plan->total_amount,
                'payable_amount' => $plan->payment_amount,
                'first_payment_date' => '2026-01-01',
                'subscription_start_date' => '2025-11-26',
                'subscription_end_date' => '2026-11-26',
                'status' => Subscription::STATUS_ACTIVE,
            ]
        );

        // 4. Phase
        SubscriptionPhase::updateOrCreate(
            ['subscription_id' => $subscription->id, 'phase' => SubscriptionPhase::PHASE_ENROLLMENT],
            ['installments_paid' => 1, 'next_installment_date' => '2026-11-27']
        );

        SubscriptionPhase::updateOrCreate(
            ['subscription_id' => $subscription->id, 'phase' => SubscriptionPhase::PHASE_PLATFORM_FEE],
            ['installments_paid' => 0, 'next_platform_fee_date' => null]
        );

        // 5. Create 1 Invoice and 1 Payment
        $this->createSinglePayment($borrower, Borrower::class, $user, $subscription);
    }

    /**
     * Helper to create 1 invoice and 1 payment.
     */
    protected function createSinglePayment($entity, $type, $user, $subscription): void
    {
        $invoice = Invoice::create([
            'invoice_id' => 'INV-' . strtoupper(uniqid()),
            'partner_id' => $type === Partner::class ? $entity->id : $entity->partner_id,
            'borrower_id' => $type === Borrower::class ? $entity->id : null,
            'entity_type' => $type,
            'entity_id' => $entity->id,
            'customer_name' => $type === Partner::class ? $entity->full_name : ($entity->first_name . ' ' . $entity->last_name),
            'email' => $entity->email,
            'payment_amount' => $subscription->payable_amount,
            'payment_date' => now()->format('Y-m-d'),
            'status' => Invoice::STATUS_PAID,
            'is_partner_paying' => false,
            'is_platform_fee' => 0,
            'created_at' => now()->format('Y-m-d'),
        ]);

        Payment::create([
            'partner_id' => $invoice->partner_id,
            'borrower_id' => $invoice->borrower_id,
            'invoice_id' => $invoice->invoice_id,
            'entity_type' => $type,
            'entity_id' => $entity->id,
            'transaction_id' => 'TXN_' . strtoupper(uniqid()),
            'payment_from' => 'Stripe',
            'payment_type' => 'Card',
            'amount' => $subscription->payable_amount,
            'currency' => 'usd',
            'status' => 'succeeded',
            'transaction_date' => now()->format('Y-m-d'),
            'created_by' => $user->id,
            'created_at' => now()->format('Y-m-d'),
        ]);
    }
}
