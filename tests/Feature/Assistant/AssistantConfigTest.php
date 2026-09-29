<?php

namespace Tests\Feature\Assistant;

use App\Models\Assistant\AiAssistantConfig;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin Assistant Config screen: host-only, and refuses to enable an
 * assistant that has no knowledge base attached (neither a vector store nor
 * local inline-knowledge files), matching AssistantConfigController.
 */
class AssistantConfigTest extends TestCase
{
    use RefreshDatabase;

    private Partner $hostPartner;
    private Partner $partner;
    private User $hostUser;
    private User $partnerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostPartner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'FCH Host',
            'full_name' => 'FCH Host',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Four Corner Funding',
            'email' => 'partner-' . uniqid() . '@example.test',
        ]);

        $this->hostUser = $this->makeUser(['partner_id' => $this->hostPartner->id, 'entity_type' => Partner::class, 'entity_id' => $this->hostPartner->id]);
        $this->partnerUser = $this->makeUser(['partner_id' => $this->partner->id, 'entity_type' => Partner::class, 'entity_id' => $this->partner->id]);
    }

    private function makeUser(array $attributes = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'Test User',
            'email' => 'user-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return User::findOrFail($id);
    }

    #[Test]
    public function a_non_host_partner_cannot_view_or_save_assistant_config(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->getJson('/api/v1/common/assistant-config')->assertStatus(403);
        $this->postJson('/api/v1/common/assistant-config', [
            'assistant_type' => AiAssistantConfig::TYPE_WLP_SUPPORT,
        ])->assertStatus(403);
    }

    #[Test]
    public function a_host_user_can_save_a_draft_without_enabling_it(): void
    {
        Passport::actingAs($this->hostUser, [], 'api');

        $this->postJson('/api/v1/common/assistant-config', [
            'assistant_type' => AiAssistantConfig::TYPE_WLP_SUPPORT,
            'model' => 'gpt-test',
            'is_enabled' => false,
        ])->assertSuccessful();

        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT);
        $this->assertSame('gpt-test', $config->model);
        $this->assertFalse($config->is_enabled);
    }

    #[Test]
    public function enabling_an_assistant_with_no_knowledge_base_at_all_is_refused(): void
    {
        // This dev environment has real inline-knowledge fixture files dropped
        // under storage/app/assistant-knowledge/wlp_support/ for manual testing
        // — hide them for the duration of this test so "no knowledge base at
        // all" is actually true, regardless of ambient disk state.
        $folder = storage_path('app/assistant-knowledge/' . AiAssistantConfig::TYPE_WLP_SUPPORT);
        $backup = $folder . '_backup_' . uniqid();
        $hadFolder = is_dir($folder);
        if ($hadFolder) {
            rename($folder, $backup);
        }

        try {
            Passport::actingAs($this->hostUser, [], 'api');

            $this->postJson('/api/v1/common/assistant-config', [
                'assistant_type' => AiAssistantConfig::TYPE_WLP_SUPPORT,
                'is_enabled' => true,
            ])->assertStatus(422);

            $this->assertFalse(AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT)->is_enabled);
        } finally {
            if ($hadFolder) {
                rename($backup, $folder);
            }
        }
    }

    #[Test]
    public function enabling_an_assistant_that_already_has_a_vector_store_succeeds(): void
    {
        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT);
        $config->vector_store_id = 'vs_already_synced';
        $config->save();

        Passport::actingAs($this->hostUser, [], 'api');

        $this->postJson('/api/v1/common/assistant-config', [
            'assistant_type' => AiAssistantConfig::TYPE_WLP_SUPPORT,
            'is_enabled' => true,
        ])->assertSuccessful();

        $this->assertTrue(AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT)->is_enabled);
    }
}
