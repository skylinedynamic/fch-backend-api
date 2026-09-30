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

/** Host-only prompt release management: create -> activate -> roll back, over HTTP. */
class AssistantPromptApiTest extends TestCase
{
    use RefreshDatabase;

    private User $hostUser;
    private User $partnerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $host = Partner::create(['is_host' => true, 'status' => Partner::STATUS_ACTIVE, 'company_name' => 'Host', 'full_name' => 'Host', 'email' => 'h-' . uniqid() . '@example.test']);
        $other = Partner::create(['is_host' => false, 'status' => Partner::STATUS_ACTIVE, 'company_name' => 'P', 'full_name' => 'P', 'email' => 'p-' . uniqid() . '@example.test']);

        $this->hostUser = $this->makeUser($host);
        $this->partnerUser = $this->makeUser($other);
    }

    private function makeUser(Partner $p): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'T', 'email' => 'u-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'user_type' => User::PARTNER,
            'partner_id' => $p->id, 'entity_type' => Partner::class, 'entity_id' => $p->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::findOrFail($id);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/common/assistant-config/' . AiAssistantConfig::TYPE_BORROWER_SUPPORT . '/prompts' . $suffix;
    }

    #[Test]
    public function a_non_host_partner_cannot_read_or_change_prompts(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->getJson($this->url())->assertStatus(403);
        $this->postJson($this->url(), ['version' => '9', 'content' => str_repeat('x', 300)])->assertStatus(403);
        $this->postJson($this->url('/1/activate'))->assertStatus(403);
    }

    #[Test]
    public function the_host_can_release_and_roll_back_a_prompt_without_the_content_leaking_into_listings(): void
    {
        Passport::actingAs($this->hostUser, [], 'api');

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.in_force.version', '1.5')
            ->assertJsonPath('data.in_force.source', 'file');

        $id = $this->postJson($this->url(), ['version' => '1.6', 'content' => 'BORROWER v1.6 ' . str_repeat('approved ', 60), 'notes' => 'wording'])
            ->assertStatus(201)->assertJsonMissingPath('data.content')->json('data.id');

        $this->postJson($this->url("/{$id}/activate"))->assertOk();

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.in_force.version', '1.6')
            ->assertJsonPath('data.in_force.source', 'database')
            ->assertJsonMissingPath('data.versions.0.content');

        // duplicate version is refused (immutability)
        $this->postJson($this->url(), ['version' => '1.6', 'content' => str_repeat('y', 300)])->assertStatus(422);

        $this->postJson($this->url('/revert-to-bundled'))->assertOk();
        $this->getJson($this->url())->assertJsonPath('data.in_force.version', '1.5');
    }

    #[Test]
    public function the_host_can_add_repoint_and_disable_navigation_routes_without_a_deploy(): void
    {
        Passport::actingAs($this->hostUser, [], 'api');
        $base = '/api/v1/common/assistant-config/wlp_support/routes';

        // new portal page, added purely through the API
        $this->putJson("{$base}/training_videos", ['label' => 'Training Videos', 'path' => '/training/videos'])->assertOk();
        // repoint an existing default and switch another off
        $this->putJson("{$base}/clients", ['label' => 'Clients', 'path' => '/clients-list'])->assertOk();
        $this->putJson("{$base}/leads", ['label' => 'Leads', 'path' => '/leads', 'is_active' => false])->assertOk();

        $map = AiAssistantConfig::find('wlp_support')->navigationMap();
        $this->assertSame('/training/videos', $map['training_videos']['path']);
        $this->assertSame('/clients-list', $map['clients']['path']);
        $this->assertArrayNotHasKey('leads', $map);
        $this->assertSame('/resources', $map['resources']['path'], 'untouched defaults stay');

        // the widget config endpoint serves the effective map
        $this->getJson('/api/v1/common/assistant/wlp/config')->assertOk()
            ->assertJsonPath('data.navigation.training_videos', '/training/videos')
            ->assertJsonPath('data.navigation.clients', '/clients-list');

        // removing the override restores the default
        $this->deleteJson("{$base}/clients")->assertOk();
        $this->assertSame('/clients', AiAssistantConfig::find('wlp_support')->navigationMap()['clients']['path']);

        // other assistants are unaffected
        $this->assertArrayNotHasKey('training_videos', AiAssistantConfig::find('borrower_support')->navigationMap());
    }

    #[Test]
    public function navigation_paths_must_be_same_origin_in_app_paths_and_only_the_host_may_edit(): void
    {
        Passport::actingAs($this->hostUser, [], 'api');
        $base = '/api/v1/common/assistant-config/wlp_support/routes';

        foreach (['https://evil.test/x', '//evil.test', 'resources', '/a?x=1', '/a#b', 'javascript:alert(1)', '/a//b'] as $bad) {
            $this->putJson("{$base}/bad_key", ['label' => 'X', 'path' => $bad])->assertStatus(422);
        }
        $this->putJson("{$base}/Bad-Key", ['label' => 'X', 'path' => '/ok'])->assertStatus(422);

        Passport::actingAs($this->partnerUser, [], 'api');
        $this->putJson("{$base}/x_key", ['label' => 'X', 'path' => '/ok'])->assertStatus(403);
        $this->getJson($base)->assertStatus(403);
    }
}
