<?php

namespace App\Support\Payment;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Stripe whether a pair of keys will work, when they're saved.
 *
 * A key can have the right prefix and still be wrong - a typo, or a key from
 * another Stripe account - and the first anyone heard of it was a payer told
 * "Invalid API Key provided" or "No such PaymentMethod". Three read-only
 * questions settle it:
 *
 *  1. GET /account with the secret key: does Stripe accept it?
 *  2. POST /tokens with the publishable key: does Stripe accept it? (A
 *     throwaway PII token with no real data - the one thing a publishable key
 *     is allowed to create. Nothing is charged or stored against a customer.)
 *  3. GET that token with the secret key: is it in the same account?
 *
 * Only a definite "no" is reported: 401 for a key, 404 resource_missing for
 * the token. Anything else - Stripe unreachable or slow, a 5xx, a rate limit,
 * a restricted key without permission to read - is treated as "couldn't
 * check", logged, and lets the save through, so a Stripe outage never becomes
 * a settings outage. Keys are never logged.
 */
class StripeKeyVerifier
{
    private const API = 'https://api.stripe.com/v1';
    private const TIMEOUT_SECONDS = 8;

    /**
     * The first problem Stripe reports with these keys, or null.
     *
     * @return array{field: 'publishable_key'|'secret_key', message: string}|null
     */
    public function problem(?string $publishable, ?string $secret, string $modeLabel): ?array
    {
        $publishable = filled($publishable) ? $publishable : null;
        $secret = filled($secret) ? $secret : null;

        $publishableRejected = ['field' => 'publishable_key', 'message' => __('payment_method.stripe_publishable_key_rejected', ['mode' => $modeLabel])];
        $secretRejected = ['field' => 'secret_key', 'message' => __('payment_method.stripe_secret_key_rejected', ['mode' => $modeLabel])];

        // Plainly not a Stripe key: Stripe would only say 401, so don't ask.
        if ($publishable !== null && !StripeKeys::isPublishable($publishable)) {
            return $publishableRejected;
        }
        if ($secret !== null && !StripeKeys::isSecret($secret)) {
            return $secretRejected;
        }

        if ($secret !== null && $this->ask(fn () => $this->stripe($secret)->get('/account'))?->status() === 401) {
            return $secretRejected;
        }

        $tokenId = null;
        if ($publishable !== null) {
            $response = $this->ask(fn () => $this->stripe($publishable)->asForm()->post('/tokens', ['pii' => ['id_number' => '000000000']]));

            if ($response?->status() === 401) {
                return $publishableRejected;
            }

            $tokenId = $response?->successful() ? $response->json('id') : null;
        }

        if (is_string($tokenId) && $secret !== null) {
            $response = $this->ask(fn () => $this->stripe($secret)->get('/tokens/' . rawurlencode($tokenId)));

            if ($response?->status() === 404 && $response->json('error.code') === 'resource_missing') {
                return ['field' => 'secret_key', 'message' => __('payment_method.stripe_keys_different_accounts', ['mode' => $modeLabel])];
            }
        }

        return null;
    }

    /** Stripe's answer, or null when it couldn't be asked. */
    private function ask(callable $request): ?Response
    {
        try {
            $response = $request();
        } catch (Throwable $e) {
            Log::warning('[StripeKeyVerifier] Stripe could not be reached; the keys were saved without that check', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->serverError() || $response->status() === 429) {
            Log::warning('[StripeKeyVerifier] Stripe did not answer the key check; the keys were saved without it', [
                'status' => $response->status(),
            ]);
        }

        return $response;
    }

    private function stripe(string $key): PendingRequest
    {
        return Http::baseUrl(self::API)
            ->withToken($key)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS);
    }
}
