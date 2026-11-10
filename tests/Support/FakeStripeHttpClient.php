<?php

namespace Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's HTTP layer so payment tests never touch the network.
 *
 * Its real purpose is the Authorization header. Stripe's SDK sends the secret
 * key on every request, so recording those headers is how a test can prove WHICH
 * Stripe account a charge went to - the fact that split apart in production, when
 * cards were tokenized against the live account and charged against the test one.
 * Asserting on a returned PaymentIntent could never catch that; asserting on the
 * key that carried the request can.
 *
 * Responses are canned per endpoint. Anything unrecognized returns an empty
 * object rather than throwing, so a test only has to describe the calls it cares
 * about.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, url: string, headers: array, params: array}> */
    public array $requests = [];

    /** @var array<string, array<string, mixed>> */
    private array $responses = [];

    /**
     * Called as each request goes out, so a test can observe the state of the
     * application at the exact moment money moves - whether a database
     * transaction is open, for instance.
     *
     * @var null|callable(string $url): void
     */
    public $onRequest = null;

    /** @var array<string, array{0: int, 1: array}> Endpoints that answer with an error. */
    private array $failures = [];

    /** @var array<string, array<int, array>> Exact "method path" answers, see on(). */
    private array $routes = [];

    public function __construct()
    {
        $this->responses = [
            'customers' => ['id' => 'cus_fake', 'object' => 'customer', 'email' => 'payer@example.test'],
            'payment_methods' => [
                'id' => 'pm_fake',
                'object' => 'payment_method',
                'customer' => null,
                'billing_details' => ['name' => 'A Cardholder'],
                'card' => ['last4' => '4242'],
            ],
            'payment_intents' => [
                'id' => 'pi_fake',
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'client_secret' => 'pi_fake_secret',
                'customer' => 'cus_fake',
                'payment_method' => 'pm_fake',
            ],
        ];
    }

    /**
     * Install this client for the duration of a test.
     */
    public static function install(): self
    {
        $client = new self();
        ApiRequestor::setHttpClient($client);

        return $client;
    }

    /**
     * Hand Stripe's SDK back its real client, so one test cannot leak into another.
     */
    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    /**
     * Override the canned body for an endpoint, e.g. 'customers'.
     */
    public function willReturn(string $resource, array $body): self
    {
        $this->responses[$resource] = $body;

        return $this;
    }

    /**
     * Answer an endpoint with a Stripe error instead, e.g. a declined card:
     * willFail('payment_intents', 402, ['type' => 'card_error', 'code' => 'card_declined', ...]).
     */
    public function willFail(string $resource, int $status, array $error): self
    {
        $this->failures[$resource] = [$status, $error];

        return $this;
    }

    /**
     * Answer one exact request - method plus path under /v1/ - with a series of
     * bodies, one per call; the last keeps answering once the others are used.
     * Checked before willReturn()/willFail(), so a test can tell apart creating a
     * PaymentIntent from reading or cancelling one:
     *
     *   on('post', 'payment_intents', $needsAuthentication)
     *   on('get', 'payment_intents/pi_3ds', $succeeded)
     *   on('post', 'payment_intents/pi_3ds/cancel', $canceled)
     *
     * A body holding an 'error' key and a '_status' is answered as that error.
     */
    public function on(string $method, string $path, array ...$bodies): self
    {
        $this->routes[strtolower($method) . ' ' . trim($path, '/')] = $bodies;

        return $this;
    }

    /**
     * The requests made to one exact method + path, in order.
     *
     * @return array<int, array{method: string, url: string, headers: array, params: array}>
     */
    public function requestsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->requests,
            fn (array $request) => strtolower($request['method']) === strtolower($method)
                && $this->pathOf($request['url']) === trim($path, '/')
        ));
    }

    private function pathOf(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return trim(preg_replace('#^/v1/#', '', $path), '/');
    }

    /**
     * Every secret key that carried a request, in order, deduplicated.
     *
     * @return array<int, string>
     */
    public function apiKeysUsed(): array
    {
        $keys = [];

        foreach ($this->requests as $request) {
            foreach ($request['headers'] as $header) {
                if (is_string($header) && str_starts_with($header, 'Authorization: Bearer ')) {
                    $keys[] = substr($header, strlen('Authorization: Bearer '));
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Every idempotency key sent, in order.
     *
     * Stripe carries the key as a request header, so this is how a test can tell
     * a retry from a second debit.
     *
     * @return array<int, string>
     */
    public function idempotencyKeys(): array
    {
        $keys = [];

        foreach ($this->requests as $request) {
            foreach ($request['headers'] as $header) {
                if (is_string($header) && stripos($header, 'Idempotency-Key: ') === 0) {
                    $keys[] = substr($header, strlen('Idempotency-Key: '));
                }
            }
        }

        return $keys;
    }

    /**
     * The idempotency key on the charge request, or null if it carried none.
     */
    public function chargeIdempotencyKey(): ?string
    {
        foreach ($this->requests as $request) {
            if (!str_contains($request['url'], '/v1/payment_intents')) {
                continue;
            }

            foreach ($request['headers'] as $header) {
                if (is_string($header) && stripos($header, 'Idempotency-Key: ') === 0) {
                    return substr($header, strlen('Idempotency-Key: '));
                }
            }
        }

        return null;
    }

    /**
     * Whether any request was made against the given Stripe resource.
     */
    public function called(string $resource): bool
    {
        foreach ($this->requests as $request) {
            if (str_contains($request['url'], '/v1/' . $resource)) {
                return true;
            }
        }

        return false;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $absUrl,
            'headers' => $headers,
            'params' => $params,
        ];

        if (is_callable($this->onRequest)) {
            ($this->onRequest)($absUrl);
        }

        $route = strtolower($method) . ' ' . $this->pathOf($absUrl);
        if (isset($this->routes[$route])) {
            $body = count($this->routes[$route]) > 1
                ? array_shift($this->routes[$route])
                : $this->routes[$route][0];

            if (isset($body['error'], $body['_status'])) {
                return [json_encode(['error' => $body['error']]), $body['_status'], []];
            }

            return [json_encode($body), 200, []];
        }

        foreach ($this->failures as $resource => [$status, $error]) {
            if (str_contains($absUrl, '/v1/' . $resource)) {
                return [json_encode(['error' => $error]), $status, []];
            }
        }

        foreach ($this->responses as $resource => $body) {
            if (str_contains($absUrl, '/v1/' . $resource)) {
                // Stripe's list endpoints wrap results; a GET with no id is a list.
                $isList = $method === 'get' && preg_match('#/v1/' . preg_quote($resource, '#') . '/?$#', parse_url($absUrl, PHP_URL_PATH) ?: '');

                $payload = $isList
                    ? ['object' => 'list', 'data' => [$body], 'has_more' => false, 'url' => '/v1/' . $resource]
                    : $body;

                return [json_encode($payload), 200, []];
            }
        }

        return [json_encode(['object' => 'object']), 200, []];
    }
}
