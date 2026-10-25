<?php

namespace Tests\Feature\Setting;

use App\Http\Controllers\Api\V1\FCH\Common\Setting\AppConfigurationController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\OpenAiConfigurationController;
use App\Http\Controllers\Api\V1\FCH\Common\Setting\PreQualificationAgentConfigController;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\PreQualification\PreQualificationAgentConfig;
use App\Services\Global\OpenAI\OpenAiAgentsService;
use App\Services\Global\OpenAI\OpenAiResponsesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Key/value settings (/common/app-configurations) are per partner. The
 * "openai" module is not: the platform reads it without a partner filter
 * (OpenAiResponsesService::setting(), PreQualificationAgentConfig::find(),
 * AiAssistantConfig) for every tenant, so it is written only through the
 * host-guarded, validated endpoints (/common/openai/config,
 * /common/prequalification-agent-config).
 */
#[CoversClass(AppConfigurationController::class)]
#[CoversClass(OpenAiConfigurationController::class)]
#[CoversClass(PreQualificationAgentConfigController::class)]
class AppAndOpenAiConfigurationApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
    }

    #[Test]
    public function a_partner_saves_and_reads_its_own_settings_only(): void
    {
        AppConfiguration::set('site_name', 'Someone Else', AppConfiguration::MODULE_BRANDING, $this->makeCertifiedPartner()->id);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->postJson('/api/v1/common/app-configurations/save', [
            'module' => AppConfiguration::MODULE_BRANDING,
            'settings' => ['site_name' => 'Mine'],
        ])->assertOk();

        $this->postJson('/api/v1/common/app-configurations', ['module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name'])
            ->assertOk()
            ->assertJsonPath('data.value', 'Mine');
        $this->assertSame('Mine', AppConfiguration::get('site_name', null, AppConfiguration::MODULE_BRANDING, $this->partner->id));
    }

    /**
     * Defect: the generic save stored any module, so a tenant could write
     * "openai" rows - an API key (read as plain text when it does not
     * decrypt), the model, or which agent underwrites pre-qualifications - and
     * the platform, reading that module without a partner filter, could pick
     * the tenant's row up for every tenant.
     */
    #[Test]
    public function the_openai_module_cannot_be_written_through_the_generic_save(): void
    {
        foreach ([$this->makePartnerAdmin($this->partner), $this->makeStaffUser($this->host)] as $user) {
            $this->actingAsUser($user);

            $this->postJson('/api/v1/common/app-configurations/save', [
                'module' => AppConfiguration::MODULE_OPENAI,
                'key' => 'openai_api_key',
                'value' => 'sk-tenant-owned-key',
            ])->assertUnprocessable()->assertJsonValidationErrors('module');

            $this->postJson('/api/v1/common/app-configurations/save', [
                'module' => AppConfiguration::MODULE_OPENAI,
                'settings' => ['prequalification_agent_agent_id' => 'agent_tenant', 'prequalification_agent_enabled' => '1'],
            ])->assertUnprocessable();
        }

        $this->assertDatabaseMissing('app_configurations', ['module' => AppConfiguration::MODULE_OPENAI]);
    }

    /**
     * Defect (BUG-022, OP-SET-07): the MicroBilt client secret (and the Credit
     * Profile OpenAI key) came back in full from the generic settings read; the
     * form only hid it visually. A secret is reported as set (masked) only.
     */
    #[Test]
    public function a_stored_secret_is_masked_on_every_read(): void
    {
        AppConfiguration::set('microbilt_client_id_production', 'client-id-plain', AppConfiguration::MODULE_MICROBILT, $this->host->id);
        AppConfiguration::set('microbilt_client_secret_production', 'mb-secret-16char', AppConfiguration::MODULE_MICROBILT, $this->host->id);
        AppConfiguration::set('microbilt_client_secret_sandbox', '', AppConfiguration::MODULE_MICROBILT, $this->host->id);
        AppConfiguration::set('credit_profile_openai_api_key', 'sk-credit-profile', AppConfiguration::MODULE_CREDIT_PROFILE, $this->host->id);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson('/api/v1/common/app-configurations', ['module' => AppConfiguration::MODULE_MICROBILT])
            ->assertOk()
            ->assertJsonPath('data.microbilt_client_id_production', 'client-id-plain')
            ->assertJsonPath('data.microbilt_client_secret_production', AppConfiguration::SECRET_MASK)
            // Nothing stored: nothing to mask, so the form asks for one.
            ->assertJsonPath('data.microbilt_client_secret_sandbox', '')
            ->assertDontSee('mb-secret-16char');

        $this->postJson('/api/v1/common/app-configurations', ['module' => AppConfiguration::MODULE_MICROBILT, 'key' => 'microbilt_client_secret_production'])
            ->assertOk()
            ->assertJsonPath('data.value', AppConfiguration::SECRET_MASK);

        $this->postJson('/api/v1/common/app-configurations', ['module' => AppConfiguration::MODULE_CREDIT_PROFILE])
            ->assertOk()
            ->assertJsonPath('data.credit_profile_openai_api_key', AppConfiguration::SECRET_MASK)
            ->assertDontSee('sk-credit-profile');
    }

    #[Test]
    public function saving_a_masked_or_blank_secret_keeps_the_stored_one(): void
    {
        AppConfiguration::set('microbilt_client_secret_production', 'mb-secret-16char', AppConfiguration::MODULE_MICROBILT, $this->host->id);
        $this->actingAsUser($this->makePartnerAdmin($this->host));
        $stored = fn () => AppConfiguration::get('microbilt_client_secret_production', null, AppConfiguration::MODULE_MICROBILT, $this->host->id);

        foreach (['', null, AppConfiguration::SECRET_MASK] as $sentBack) {
            $this->postJson('/api/v1/common/app-configurations/save', [
                'module' => AppConfiguration::MODULE_MICROBILT,
                'microbilt_mode' => 'production',
                'microbilt_client_secret_production' => $sentBack,
            ])->assertOk();
            $this->assertSame('mb-secret-16char', $stored());
        }

        $this->postJson('/api/v1/common/app-configurations/save', [
            'module' => AppConfiguration::MODULE_MICROBILT,
            'key' => 'microbilt_client_secret_production',
            'value' => AppConfiguration::SECRET_MASK,
        ])->assertOk();
        $this->assertSame('mb-secret-16char', $stored());

        $this->postJson('/api/v1/common/app-configurations/save', [
            'module' => AppConfiguration::MODULE_MICROBILT,
            'microbilt_client_secret_production' => 'mb-rotated',
        ])->assertOk();
        $this->assertSame('mb-rotated', $stored());
        $this->assertSame('production', AppConfiguration::get('microbilt_mode', null, AppConfiguration::MODULE_MICROBILT, $this->host->id));
    }

    #[Test]
    public function the_host_saves_the_openai_key_encrypted_and_never_reads_it_back(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson('/api/v1/common/openai/config', [
            'api_key' => 'sk-host-key-123',
            'aue_model' => 'gpt-test',
            'reasoning_effort' => 'medium',
        ])->assertOk();

        $stored = AppConfiguration::get('openai_api_key', null, AppConfiguration::MODULE_OPENAI, $this->host->id);
        $this->assertNotSame('sk-host-key-123', $stored);
        $this->assertSame('sk-host-key-123', OpenAiResponsesService::decryptSecret($stored));

        $this->getJson('/api/v1/common/openai/config')
            ->assertOk()
            ->assertJsonPath('data.has_api_key', true)
            ->assertJsonPath('data.aue_model', 'gpt-test')
            ->assertDontSee('sk-host-key-123');
    }

    #[Test]
    public function an_invalid_key_is_refused(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson('/api/v1/common/openai/config', ['api_key' => 'Sk-wrong', 'reasoning_effort' => 'medium'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('api_key');
    }

    #[Test]
    public function the_model_list_comes_from_the_configured_key(): void
    {
        $this->mock(OpenAiResponsesService::class)
            ->shouldReceive('listModels')
            ->once()
            ->andReturn(['models' => ['gpt-a', 'gpt-b'], 'is_fallback' => false, 'error' => null]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson('/api/v1/common/openai/models')->assertOk()->assertJsonPath('data.models', ['gpt-a', 'gpt-b']);
    }

    /**
     * Only a host partner login reaches these (the same check as the admin
     * UI's PartnerHostRoute): not another tenant, and not host staff.
     */
    #[Test]
    public function the_openai_endpoints_refuse_everyone_but_a_host_partner_login(): void
    {
        $this->mock(OpenAiAgentsService::class)->shouldNotReceive('retrieveAgent');

        foreach ([$this->makePartnerAdmin($this->partner), $this->makeStaffUser($this->host)] as $user) {
            $this->actingAsUser($user);

            $this->getJson('/api/v1/common/openai/config')->assertForbidden();
            $this->postJson('/api/v1/common/openai/config', ['api_key' => 'sk-abc', 'reasoning_effort' => 'medium'])->assertForbidden();
            $this->getJson('/api/v1/common/openai/models')->assertForbidden();
            $this->getJson('/api/v1/common/prequalification-agent-config')->assertForbidden();
            $this->putJson('/api/v1/common/prequalification-agent-config/' . PreQualificationAgentConfig::TYPE_AGENT, ['enabled' => false])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('app_configurations', ['module' => AppConfiguration::MODULE_OPENAI]);
    }

    #[Test]
    public function the_host_switches_the_underwriting_agent_off_and_sees_it(): void
    {
        $this->mock(OpenAiAgentsService::class)->shouldNotReceive('retrieveAgent');
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->putJson('/api/v1/common/prequalification-agent-config/' . PreQualificationAgentConfig::TYPE_AGENT, [
            'agent_id' => null,
            'enabled' => false,
        ])->assertOk();

        $this->getJson('/api/v1/common/prequalification-agent-config')
            ->assertOk()
            ->assertJsonPath('data.0.step', PreQualificationAgentConfig::TYPE_AGENT)
            ->assertJsonPath('data.0.is_enabled', false)
            ->assertJsonPath('data.0.is_ready', false);
    }

    #[Test]
    public function an_enabled_step_needs_an_agent_and_an_unknown_step_is_not_found(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->putJson('/api/v1/common/prequalification-agent-config/' . PreQualificationAgentConfig::TYPE_AGENT, ['enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('agent_id');
        $this->putJson('/api/v1/common/prequalification-agent-config/not-a-step', ['enabled' => false])->assertNotFound();
    }

    #[Test]
    public function an_agent_without_a_structured_result_is_refused(): void
    {
        $this->mock(OpenAiAgentsService::class)
            ->shouldReceive('retrieveAgent')
            ->with('agent_abc')
            ->andReturn(['ok' => true, 'agent' => ['text' => ['format' => ['type' => 'text']]], 'error' => null]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->putJson('/api/v1/common/prequalification-agent-config/' . PreQualificationAgentConfig::TYPE_AGENT, [
            'agent_id' => 'agent_abc',
            'enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('agent_id');
    }
}
