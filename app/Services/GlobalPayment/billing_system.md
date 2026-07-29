# FCH Application-Led Stripe Billing System

## Overview

This document describes how the entire Stripe payment and subscription billing system works in the FCH platform.

The system uses an **Application-Led Billing Model**:
- **Laravel (your database) is the source of truth** for subscription schedules, installment dates, and platform fee dates.
- **Stripe is used purely as a secure card vault and payment processor** — it never controls when or how much to charge.

---

## Architecture: 3-Layer System

```
┌─────────────────────────────────────────────────────────────────┐
│  LAYER 1: Frontend (React)                                      │
│  User enters card → Stripe.js tokenizes it → sends pm_xxx      │
│  to your backend. Raw card data NEVER touches your servers.     │
└───────────────────────────┬─────────────────────────────────────┘
                            │ pm_xxx token + invoice_id
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│  LAYER 2: Laravel Backend (Application-Led)                     │
│  Controls ALL billing logic:                                    │
│  - When to charge (dates stored in DB)                          │
│  - How much to charge (amounts from subscription phases)        │
│  - What phase the subscription is in (enrollment / platform fee)│
└───────────────────────────┬─────────────────────────────────────┘
                            │ PaymentIntent API call
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│  LAYER 3: Stripe (Card Vault + Processor)                       │
│  - Stores the customer's card (stripe_customer_id)              │
│  - Executes charges when Laravel instructs it to                │
│  - Notifies Laravel of results via Webhooks                     │
└─────────────────────────────────────────────────────────────────┘
```

---

## Full Flow: Step by Step

### STEP 1 — User Selects Payment Method and Pays First Invoice

The customer opens an invoice in the frontend and clicks **Pay Now**.

```
Frontend (React / InvoicePaymentModal)
        │
        ├─ User selects Stripe as payment provider
        ├─ User enters card details in the secure CardElement
        ├─ Calls Stripe.js: stripe.createPaymentMethod({ type: 'card', card: cardElement })
        │    └─ Stripe returns: pm_xxxxxxxxxxxxx  (card token, never raw card data)
        │
        └─ POST /api/payment/process  { payment_token: "pm_xxx", invoice_id: 42 }
```

---

### STEP 2 — Backend Charges the Card (UnifiedPaymentService)

```
UnifiedPaymentController → UnifiedPaymentService → PaymentGatewayManager
        │
        ├─ Finds the Invoice (ID: 42)
        ├─ Resolves the correct payment provider (Stripe) for the partner
        ├─ Calls StripePaymentDriver::charge()
        │
        StripePaymentDriver::charge()
        │
        ├─ 1. Resolves / Creates Stripe Customer (cus_xxx) using payer email
        │       └─ Saves cus_xxx to entity's stripe_customer_id column in DB
        │
        ├─ 2. Attaches pm_xxx to the Stripe Customer and sets it as default
        │       └─ This VAULTS the card. Future charges need only cus_xxx
        │
        ├─ 3. Creates and confirms a one-time PaymentIntent
        │       └─ Stripe::PaymentIntent::create([
        │               'customer' => 'cus_xxx',
        │               'payment_method' => 'pm_xxx',
        │               'confirm' => true, ...
        │          ])
        │
        └─ Returns: { success: true, transaction_id: "pi_xxx", status: "succeeded" }
```

---

### STEP 3 — Laravel Syncs the Local Database (UnifiedPaymentService)

Back in `UnifiedPaymentService::processPayment()` after a successful charge:

```
DB::transaction:
  1. Creates a Payment ledger record  (transaction_id = "pi_xxx", status = Succeeded)
  2. Updates Invoice status = "Paid", payment_date = today
  3. Calls SubscriptionService::handleSuccessfulPayment($invoice)
        │
        └─ Sets subscription status = "Active"
        └─ Sets subscription_start_date, subscription_end_date
        └─ Increments installments_paid on the SubscriptionPhase
        └─ Sets next_installment_date = today + 1 month
        └─ If all installments paid → transitions phase to "platform_fee"
        └─ Sets next_platform_fee_date for the next cron billing cycle
```

---

### STEP 4 — Daily Cron: Generate New Invoices (subscriptions:process)

Runs every day at **01:00 and 13:00**.

```
SubscriptionService::generateInstallmentInvoices()
  └─ Scans SubscriptionPhase where:
       phase = "enrollment"
       next_installment_date <= today + 5 days  (generate 5 days early)
       subscription status = "Active"
  └─ For each due phase → creates a new Invoice (status = Unpaid)
  └─ Sends the invoice email to the customer

SubscriptionService::generatePlatformFeeInvoices()
  └─ Same logic but for phase = "platform_fee" and next_platform_fee_date
```

---

### STEP 5 — Daily Cron: Auto-Charge Due Invoices (subscriptions:auto-charge)

Runs every day at **01:15 and 13:15** (15 min after invoice generation).

```
AutoChargeSubscriptions::handle()
  │
  ├─ Finds all Invoice records where:
  │     status = "Unpaid"
  │     linked subscription status = "Active"
  │     created_at <= today
  │
  ├─ For each invoice:
  │     ├─ Gets entity's stripe_customer_id from local DB
  │     ├─ Calls StripePaymentDriver::chargeOffSession(cus_xxx, amount)
  │     │     └─ Creates PaymentIntent with off_session: true, confirm: true
  │     │     └─ No card entry needed — uses the vaulted default payment method
  │     │
  │     ├─ ON SUCCESS:
  │     │     ├─ Creates Payment ledger record
  │     │     ├─ Marks Invoice as Paid
  │     │     └─ Calls SubscriptionService::handleSuccessfulPayment($invoice)
  │     │           └─ Advances next billing date by 1 month (for next cycle)
  │     │
  │     └─ ON FAILURE (card declined, expired, etc.):
  │           ├─ Marks Invoice as Failed
  │           ├─ Sets Subscription status to "Past Due"  (locks user access)
  │           └─ Sends failure notification email to admin
  │
  └─ Logs results and updates scheduler_runs table
```

---

### STEP 6 — Webhook: The Safety Net

The webhook endpoint `POST /api/payment/stripe-webhook` handles edge cases where the direct API response was not received correctly.

```
Stripe → POST /api/payment/stripe-webhook
        │
        StripeWebhookController::handle()
        │
        ├─ Verifies the Stripe-Signature header (STRIPE_WEBHOOK_SECRET)
        └─ Passes payload to StripeWebhookService::handleWebhook()

Event: payment_intent.succeeded
  └─ Finds the local Payment record by transaction_id (pi_xxx)
  └─ If Invoice is still Unpaid → marks it as Paid
     (Handles the case where the API response was lost due to network timeout)

Event: payment_intent.payment_failed
  └─ Finds the local Payment record
  └─ Marks Invoice as Failed
  └─ Sets Subscription status to "Past Due"
```

#### Why is the webhook needed if we already sync synchronously?

| Scenario | Direct API Response | Webhook Needed? |
|---|---|---|
| Normal payment, API response received | ✅ DB synced | Redundant (idempotent check skips it) |
| API response lost (network timeout) | ❌ DB NOT synced | ✅ **Webhook corrects it** |
| Off-session cron charge (async approval) | ⏳ Status may be `processing` | ✅ **Webhook confirms final result** |
| Customer disputes / bank chargeback | ❌ No direct notification | ✅ **Only way to know** |

---

## Complete Billing Timeline Example

```
Month 0  (Day 1)   → Customer pays first invoice manually via frontend
                     → stripe_customer_id saved, card vaulted on Stripe
                     → Subscription Active, next_installment_date = Month 1 Day 1

Month 0  (Day 26)  → subscriptions:process generates Invoice #2 (Unpaid) for Month 1
                     → Invoice email sent to customer

Month 1  (Day 1)   → subscriptions:auto-charge fires
                     → Finds Invoice #2 (Unpaid)
                     → Charges cus_xxx off-session for installment amount
                     → Invoice #2 → Paid, next_installment_date → Month 2 Day 1

Month 1  (Day 26)  → subscriptions:process generates Invoice #3 for Month 2

... (repeats until all installments are paid)

Final Month        → All installments paid → phase transitions to "platform_fee"
                     → subscriptions:process generates platform fee invoices
                     → subscriptions:auto-charge charges platform fee monthly forever
```

---

## Key Files Reference

| File | Purpose |
|---|---|
| `app/Services/GlobalPayment/Drivers/StripePaymentDriver.php` | `charge()` — initial checkout; `chargeOffSession()` — cron auto-charge |
| `app/Services/GlobalPayment/UnifiedPaymentService.php` | Orchestrates charge + DB sync + subscription phase advancement |
| `app/Services/FCH/Subscription/SubscriptionService.php` | `handleSuccessfulPayment()` — advances phase; `generateInstallmentInvoices()` |
| `app/Console/Commands/AutoChargeSubscriptions.php` | Daily cron — automatically charges all due Unpaid invoices |
| `app/Console/Commands/ProcessSubscriptions.php` | Daily cron — generates new Unpaid invoices 5 days before due date |
| `app/Services/GlobalPayment/Stripe/StripeWebhookService.php` | Safety net — handles `payment_intent.succeeded` and `.payment_failed` |
| `app/Http/Controllers/Api/V1/GlobalPayment/StripeWebhookController.php` | Receives and verifies Stripe webhook HTTP POST requests |
| `app/Services/GlobalPayment/Stripe/StripeCustomerService.php` | Creates/resolves Stripe customers; attaches and vaults payment methods |
| `routes/console.php` | Laravel scheduler — registers all cron job schedules |

---

## Environment Variables Required

```env
STRIPE_SECRET_KEY=sk_live_xxxxx       # Stripe API secret key
STRIPE_WEBHOOK_SECRET=whsec_xxxxx     # Stripe webhook signing secret
```

---

## Stripe Dashboard Setup Required

1. Go to **Developers → Webhooks → Add Endpoint**
2. Enter URL: `https://yourdomain.com/api/payment/stripe-webhook`
3. Select these events:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
4. Copy the **Signing Secret** and set it as `STRIPE_WEBHOOK_SECRET` in your `.env`
