<?php

namespace Tests\Unit\Rules;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Rules\ReCaptcha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/** reCAPTCHA is on only when Site Settings holds both keys. */
#[CoversClass(ReCaptcha::class)]
class ReCaptchaConfiguredTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = $this->makeHostPartner();
    }

    private function save(string $key, string $value): void
    {
        AppConfiguration::set($key, $value, AppConfiguration::MODULE_WEBSITE, $this->host->id);
    }

    #[Test]
    public function it_is_off_with_neither_key(): void
    {
        $this->assertFalse(ReCaptcha::isConfigured());
    }

    #[Test]
    public function it_is_off_with_only_the_secret(): void
    {
        $this->save('recaptcha_secret_key', 'secret');

        $this->assertFalse(ReCaptcha::isConfigured());
    }

    #[Test]
    public function it_is_off_with_only_the_site_key(): void
    {
        $this->save('recaptcha_site_key', 'site-key');

        $this->assertFalse(ReCaptcha::isConfigured());
    }

    #[Test]
    public function it_is_on_with_both_keys(): void
    {
        $this->save('recaptcha_secret_key', 'secret');
        $this->save('recaptcha_site_key', 'site-key');

        $this->assertTrue(ReCaptcha::isConfigured());
    }
}
