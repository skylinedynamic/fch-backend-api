<?php

namespace Tests\Integration\Branding;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ownSiteName() is the partner's own site_name only. Unlike
 * getBrandingSettings(), it never falls back to the host's.
 */
#[CoversClass(AppConfigurationService::class)]
class OwnSiteNameTest extends TestCase
{
    use RefreshDatabase;

    private function partner(bool $isHost, string $company): Partner
    {
        return Partner::create([
            'is_host' => $isHost,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => $company,
            'full_name' => $company,
            'email' => strtolower(str_replace(' ', '', $company)) . '@example.test',
        ]);
    }

    private function setSiteName(Partner $partner, string $value, string $module = AppConfiguration::MODULE_BRANDING): void
    {
        AppConfiguration::create([
            'partner_id' => $partner->id,
            'module' => $module,
            'key' => 'site_name',
            'value' => $value,
        ]);
    }

    private function ownSiteName(Partner $partner): ?string
    {
        return app(AppConfigurationService::class)->ownSiteName($partner->id);
    }

    #[Test]
    public function it_returns_the_partners_own_site_name(): void
    {
        $partner = $this->partner(false, 'Acme Capital LLC');
        $this->setSiteName($partner, 'Acme Capital');

        $this->assertSame('Acme Capital', $this->ownSiteName($partner));
    }

    #[Test]
    public function it_does_not_borrow_the_hosts_site_name(): void
    {
        $this->setSiteName($this->partner(true, 'Host Holdings'), 'Host Brand');

        $this->assertNull($this->ownSiteName($this->partner(false, 'Acme Capital LLC')));
    }

    #[Test]
    public function it_ignores_a_site_name_stored_under_another_module(): void
    {
        $partner = $this->partner(false, 'Acme Capital LLC');
        $this->setSiteName($partner, 'Website Title', 'website');

        $this->assertNull($this->ownSiteName($partner));
    }

    #[Test]
    #[DataProvider('blankValues')]
    public function a_blank_site_name_counts_as_none(string $value): void
    {
        $partner = $this->partner(false, 'Acme Capital LLC');
        $this->setSiteName($partner, $value);

        $this->assertNull($this->ownSiteName($partner));
    }

    public static function blankValues(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'the word null, as saved by the settings form' => ['null'],
        ];
    }
}
