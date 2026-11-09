<?php

namespace Tests\Unit\EmbeddedForm;

use App\Support\EmbedForm\AllowedDomain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Round 2 BUG-035: how an Allowed Domains entry is read and stored. */
#[CoversClass(AllowedDomain::class)]
class AllowedDomainTest extends TestCase
{
    #[Test]
    public function the_host_is_the_entry_without_scheme_path_port_credentials_or_www(): void
    {
        $this->assertSame('x.com', AllowedDomain::host('  HTTPS://www.X.com:8443/apply?step=1#top '));
        $this->assertSame('x.com', AllowedDomain::host('user:pass@x.com'));
        $this->assertSame('x.com', AllowedDomain::host('x.com.'));
        $this->assertSame('shop.x.com', AllowedDomain::host('shop.x.com/'));
    }

    #[Test]
    public function another_scheme_or_nothing_names_no_host(): void
    {
        $this->assertNull(AllowedDomain::host('ftp://x.com'));
        $this->assertNull(AllowedDomain::host('https://'));
        $this->assertNull(AllowedDomain::host('   '));
    }

    #[Test]
    public function an_entry_is_stored_bare_or_with_http_when_typed_so(): void
    {
        $this->assertSame('x.com', AllowedDomain::normalize('https://x.com/path'));
        $this->assertSame('x.com', AllowedDomain::normalize('x.com'));
        $this->assertSame('http://x.com', AllowedDomain::normalize('HTTP://www.x.com/page'));
        $this->assertTrue(AllowedDomain::allowsHttp('http://x.com'));
        $this->assertFalse(AllowedDomain::allowsHttp('x.com'));
    }

    #[Test]
    public function only_real_host_names_are_valid(): void
    {
        foreach (['x.com', 'shop.example.co.uk', 'my-site.io', 'xn--80ak6aa92e.xn--p1ai'] as $host) {
            $this->assertTrue(AllowedDomain::isValidHost($host), $host);
        }
        foreach (['intranet', '192.168.0.1', 'x..com', '-x.com', 'not a domain', '*.x.com'] as $host) {
            $this->assertFalse(AllowedDomain::isValidHost($host), $host);
        }
    }

    #[Test]
    public function wildcards_and_localhost_are_recognised(): void
    {
        $this->assertTrue(AllowedDomain::isWildcard('*.x.com'));
        $this->assertTrue(AllowedDomain::isLocalhost('localhost'));
        $this->assertTrue(AllowedDomain::isLocalhost('app.localhost'));
        $this->assertFalse(AllowedDomain::isLocalhost('localhost.com'));
    }
}
