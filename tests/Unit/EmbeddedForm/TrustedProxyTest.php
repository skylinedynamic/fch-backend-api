<?php

namespace Tests\Unit\EmbeddedForm;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Client IP resolution behind a reverse proxy.
 *
 * Without trusted proxies configured, request()->ip() returns the socket peer -
 * the proxy - which is why every embedded form submission recorded 127.0.0.1.
 * These tests cover both halves of the fix: the real client IP is read when the
 * hop is trusted, and a forged header from an untrusted client is ignored.
 *
 * The middleware is exercised directly rather than through a route, so no HTTP
 * stack or database is involved.
 */
class TrustedProxyTest extends TestCase
{
    /**
     * Resolve the IP the application would record for this request.
     */
    private function resolvedIp(string $remoteAddr, array $headers = []): string
    {
        $server = array_merge(['REMOTE_ADDR' => $remoteAddr], $headers);
        $request = Request::create('/api/v1/embed/submit/key', 'POST', [], [], [], $server);

        // The middleware mutates the request in place; the response is ignored.
        (new TrustProxies())->handle($request, fn () => response(''));

        return $request->ip();
    }

    public static function trustedProxies(): array
    {
        return [
            'loopback IPv4' => ['127.0.0.1'],
            'loopback IPv6' => ['::1'],
            'private 10.x' => ['10.1.2.3'],
            'private 172.16.x' => ['172.16.5.9'],
            'private 192.168.x' => ['192.168.1.20'],
        ];
    }

    #[Test]
    #[DataProvider('trustedProxies')]
    public function it_reads_the_forwarded_client_ip_from_a_trusted_proxy(string $proxy): void
    {
        $this->assertSame(
            '203.0.113.45',
            $this->resolvedIp($proxy, ['HTTP_X_FORWARDED_FOR' => '203.0.113.45'])
        );
    }

    #[Test]
    public function it_stores_an_ipv6_client_address(): void
    {
        $this->assertSame(
            '2001:db8::42',
            $this->resolvedIp('127.0.0.1', ['HTTP_X_FORWARDED_FOR' => '2001:db8::42'])
        );
    }

    #[Test]
    public function ipv6_fits_the_column_it_is_stored_in(): void
    {
        // ip_address is varchar(45); the longest possible IPv6 text form - an
        // IPv4-mapped address - is 45 characters.
        $longest = '0000:0000:0000:0000:0000:ffff:255.255.255.255';

        $this->assertSame(45, strlen($longest));
        $this->assertLessThanOrEqual(45, strlen(
            $this->resolvedIp('127.0.0.1', ['HTTP_X_FORWARDED_FOR' => $longest])
        ));
    }

    #[Test]
    public function it_takes_the_client_from_the_front_of_a_proxy_chain(): void
    {
        // X-Forwarded-For accumulates left to right: client, then each proxy.
        $this->assertSame(
            '203.0.113.45',
            $this->resolvedIp('127.0.0.1', [
                'HTTP_X_FORWARDED_FOR' => '203.0.113.45, 10.0.0.9, 10.0.0.10',
            ])
        );
    }

    #[Test]
    public function a_forged_header_from_an_untrusted_client_is_ignored(): void
    {
        // The spoof-protection half of the acceptance criteria: a public client
        // that is not a configured proxy cannot choose its own IP.
        $this->assertSame(
            '198.51.100.99',
            $this->resolvedIp('198.51.100.99', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4'])
        );
    }

    #[Test]
    public function a_direct_request_with_no_forwarding_header_uses_the_peer(): void
    {
        $this->assertSame('198.51.100.99', $this->resolvedIp('198.51.100.99'));
    }

    #[Test]
    public function the_default_trust_list_covers_loopback_and_private_ranges_only(): void
    {
        // Set by bootstrap/app.php via TrustProxies::at(), and only reachable
        // through reflection - the accessor is protected.
        $property = new \ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies');
        $configured = $property->getValue();

        $this->assertIsArray($configured);
        // Trusting every hop would defeat the spoof protection above, so the
        // default must never be the wildcard.
        $this->assertNotContains('*', $configured);
        $this->assertContains('127.0.0.1', $configured);
        $this->assertContains('10.0.0.0/8', $configured);
    }
}
