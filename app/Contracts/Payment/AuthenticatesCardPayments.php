<?php

namespace App\Contracts\Payment;

/**
 * A gateway whose card charges can wait on the payer's bank authentication
 * (3-D Secure / OTP) and be finished from the browser - so far only Stripe.
 *
 * A driver that implements this returns, from charge(), `requires_action` and
 * a `client_secret` when the issuer asks for authentication. The browser runs
 * the challenge with that secret; these two methods let the API find out how it
 * ended, and give up on a challenge nobody finished.
 *
 * Both return the same shape as charge():
 *   success          bool   the money was taken
 *   requires_action  bool   still waiting on the payer's bank
 *   transaction_id   string the gateway's id for the charge
 *   status           string the gateway's own status
 *   error            ?string why the charge failed, as the gateway words it
 *   error_code       ?string
 *   customer_id      ?string
 *   raw              array
 */
interface AuthenticatesCardPayments
{
    /** Read a charge back from the gateway. */
    public function retrievePayment(string $transactionId): array;

    /** Cancel a charge that is still waiting on authentication. */
    public function cancelPayment(string $transactionId): array;
}
