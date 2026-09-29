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
 * The admin Assistant Config screen: host-only, read-only status reflecting
 * config/assistant.php (is_enabled/model/reasoning_effort) and whatever
 * assistant:sync-knowledge has recorded (vector_store_id) — matching
 * AssistantConfigController, which no longer accepts writes for the former.
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
    public function a_non_host_partner_cannot_view_assistant_config(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->getJson('/api/v1/common/assistant-config')->assertStatus(403);
    }

    #[Test]
    public function a_host_user_sees_the_config_file_values(): void
    {
        config(['assistant.wlp_support.is_enabled' => false]);
        config(['assistant.wlp_support.model' => 'gpt-test']);

        Passport::actingAs($this->hostUser, [], 'api');

        $this->getJson('/api/v1/common/assistant-config')
            ->assertSuccessful()
            ->assertJsonFragment([
                'assistant_type' => AiAssistantConfig::TYPE_WLP_SUPPORT,
                'model' => 'gpt-test',
                'is_enabled' => false,
            ]);
    }

    #[Test]
    public function is_ready_is_false_with_no_knowledge_base_at_all_even_if_enabled_in_config(): void
    {
        // This dev environment has real inline-knowledge fixture files under
        // app/Services/Assistant/Knowledge/wlp_support/ for manual testing —
        // hide them for the duration of this test so "no knowledge base at
        // all" is actually true, regardless of ambient disk state.
        $folder = app_path('Services/Assistant/Knowledge/' . AiAssistantConfig::TYPE_WLP_SUPPORT);
        $backup = $folder . '_backup_' . uniqid();
        $hadFolder = is_dir($folder);
        if ($hadFolder) {
            rename($folder, $backup);
        }

        try {
            config(['assistant.wlp_support.is_enabled' => true]);

            $this->assertFalse(AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT)->isReady());
        } finally {
            if ($hadFolder) {
                rename($backup, $folder);
            }
        }
    }

    #[Test]
    public function is_ready_is_true_once_sync_knowledge_has_recorded_a_vector_store(): void
    {
        config(['assistant.wlp_support.is_enabled' => true]);

        // Simulates what `php artisan assistant:sync-knowledge` records after
        // a real OpenAI upload — the only thing this feature still persists.
        $config = AiAssistantConfig::find(AiAssistantConfig::TYPE_WLP_SUPPORT);
        $config->vector_store_id = 'vs_already_synced';
        $config->save();

        Passport::actingAs($this->hostUser, [], 'api');

        $this->getJson('/api/v1/common/assistant-config')
            ->assertSuccessful()
            ->assertJsonFragment([
                'assistant_type' => AiAssistantConfig::TYPE_WLP_SUPPORT,
                'vector_store_id' => 'vs_already_synced',
                'is_ready' => true,
            ]);
    }
}
