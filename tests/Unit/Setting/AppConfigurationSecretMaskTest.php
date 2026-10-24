<?php

namespace Tests\Unit\Setting;

use App\Models\Common\AppConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BUG-022: which settings keys are credentials, how they are shown, and what a
 * save may leave untouched.
 */
#[CoversClass(AppConfiguration::class)]
class AppConfigurationSecretMaskTest extends TestCase
{
    public static function keys(): array
    {
        return [
            'recaptcha secret' => ['recaptcha_secret_key', true],
            'microbilt secret' => ['microbilt_client_secret_production', true],
            'openai key' => ['credit_profile_openai_api_key', true],
            'auth token' => ['auth_token', true],
            'password' => ['smtp_password', true],
            'recaptcha site key' => ['recaptcha_site_key', false],
            'client id' => ['microbilt_client_id_production', false],
            'site name' => ['site_name', false],
        ];
    }

    #[Test]
    #[DataProvider('keys')]
    public function a_key_is_a_secret_by_its_name(string $key, bool $secret): void
    {
        $this->assertSame($secret, AppConfiguration::isSecretKey($key));
    }

    #[Test]
    public function a_stored_secret_is_shown_as_the_mask_and_nothing_else_changes(): void
    {
        $this->assertSame([
            'microbilt_client_secret_production' => AppConfiguration::SECRET_MASK,
            'microbilt_client_secret_sandbox' => '',
            'microbilt_client_id_production' => 'client-id',
        ], AppConfiguration::maskSecrets([
            'microbilt_client_secret_production' => 'mb-secret',
            'microbilt_client_secret_sandbox' => '',
            'microbilt_client_id_production' => 'client-id',
        ]));
    }

    #[Test]
    public function a_masked_or_blank_secret_keeps_the_stored_one_but_a_plain_field_saves(): void
    {
        $this->assertTrue(AppConfiguration::keepsStoredSecret('recaptcha_secret_key', AppConfiguration::SECRET_MASK));
        $this->assertTrue(AppConfiguration::keepsStoredSecret('recaptcha_secret_key', ''));
        $this->assertTrue(AppConfiguration::keepsStoredSecret('recaptcha_secret_key', null));
        $this->assertFalse(AppConfiguration::keepsStoredSecret('recaptcha_secret_key', 'new-secret'));
        $this->assertFalse(AppConfiguration::keepsStoredSecret('site_slogan', ''));
    }
}
