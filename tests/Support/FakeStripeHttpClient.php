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
