<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Common\Permission;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The rules the embedded form API enforces on its own, whatever the admin panel
 * shows or hides:
 *
 *   - every authenticated route needs its permission
 *   - "converted" is reached only by converting, and is final
 *   - an application is converted once
 *   - an unknown form key is a 404, never a list of the form's fields
 *   - a non-numeric id is a 404, not a server error
 *   - search covers the business as well as the applicant
 */
class EmbedFormApiRulesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/common/embed-forms';
    private const PERMISSIONS = ['view configuration', 'update configuration', 'view application', 'convert application'];

    private Partner $partner;
    private EmbedFormConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Rules Partner',
            'full_name' => 'Rules Partner',
            'email' => 'partner-' . uniqid() . '@example.test',
        ]);

        $this->config = EmbedFormConfig::create([
            'partner_id' => $this->partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Business Funding Application',
            'is_active' => true,
        ]);
    }

    private function user(array $permissions): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Rules User',
            'email' => 'user-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'partner_id' => $this->partner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Lazy loading is off in this app, so the relations Spatie reads are
        // loaded before it is asked to attach anything.
        $user = User::with('permissions', 'roles')->findOrFail($id);

        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return User::with('permissions', 'roles', 'partner')->findOrFail($id);
    }

    private function submission(array $attributes = []): EmbedFormSubmission
    {
        return EmbedFormSubmission::create(array_merge([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $this->partner->id,
            'form_config_id' => $this->config->id,
            'status' => EmbedFormSubmission::STATUS_NEW,
            'first_name' => 'Ada',
            'last_name' => 'Applicant',
            'email' => 'ada-' . uniqid() . '@example.test',
            'phone' => '+15553456789',
            'business_name' => 'Ada Holdings',
            'business_email' => 'accounts@ada-holdings.example.test',
        ], $attributes));
    }

    #[Test]
    public function a_user_without_the_permissions_is_refused_on_every_route(): void
    {
        $submission = $this->submission();
        Passport::actingAs($this->user([]), [], 'api');

        $this->getJson(self::BASE . '/config')->assertForbidden();
        $this->putJson(self::BASE . '/config', ['form_title' => 'Changed'])->assertForbidden();
        $this->postJson(self::BASE . '/config/generate-key')->assertForbidden();
        $this->postJson(self::BASE . '/submissions/fetch')->assertForbidden();
        $this->getJson(self::BASE . "/submissions/{$submission->id}")->assertForbidden();
        $this->putJson(self::BASE . "/submissions/{$submission->id}/status", ['status' => 'reviewing'])->assertForbidden();
        $this->postJson(self::BASE . "/submissions/{$submission->id}/convert-to-borrower")->assertForbidden();
        $this->getJson(self::BASE . '/attachments/1/download')->assertForbidden();

        $this->assertSame(EmbedFormSubmission::STATUS_NEW, $submission->fresh()->status);
    }

    #[Test]
    public function viewing_applications_does_not_allow_converting_or_configuring(): void
    {
        $submission = $this->submission();
        Passport::actingAs($this->user(['view application']), [], 'api');

        $this->postJson(self::BASE . '/submissions/fetch')->assertOk();
        $this->getJson(self::BASE . "/submissions/{$submission->id}")->assertOk();
        $this->postJson(self::BASE . "/submissions/{$submission->id}/convert-to-borrower")->assertForbidden();
        $this->getJson(self::BASE . '/config')->assertForbidden();
        $this->putJson(self::BASE . '/config', ['form_title' => 'Changed'])->assertForbidden();
    }

    #[Test]
    public function converted_cannot_be_set_by_hand(): void
    {
        $submission = $this->submission();
        Passport::actingAs($this->user(self::PERMISSIONS), [], 'api');

        $this->putJson(self::BASE . "/submissions/{$submission->id}/status", ['status' => 'converted'])
            ->assertStatus(422);
        $this->assertSame(EmbedFormSubmission::STATUS_NEW, $submission->fresh()->status);

        $this->putJson(self::BASE . "/submissions/{$submission->id}/status", ['status' => 'reviewing'])
            ->assertOk();
        $this->assertSame(EmbedFormSubmission::STATUS_REVIEWING, $submission->fresh()->status);
    }

    #[Test]
    public function a_converted_application_keeps_its_status_and_is_not_converted_again(): void
    {
        $submission = $this->submission(['status' => EmbedFormSubmission::STATUS_CONVERTED]);
        Passport::actingAs($this->user(self::PERMISSIONS), [], 'api');

        $this->putJson(self::BASE . "/submissions/{$submission->id}/status", ['status' => 'new'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('embed_form.status_locked_converted'));

        $this->postJson(self::BASE . "/submissions/{$submission->id}/convert-to-borrower", ['confirm_existing_borrower' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', __('embed_form.already_converted'));

        $this->assertSame(EmbedFormSubmission::STATUS_CONVERTED, $submission->fresh()->status);
    }

    #[Test]
    public function a_non_numeric_id_is_not_found(): void
    {
        Passport::actingAs($this->user(self::PERMISSIONS), [], 'api');

        $this->getJson(self::BASE . '/submissions/undefined')->assertNotFound();
        $this->putJson(self::BASE . '/submissions/abc/status', ['status' => 'new'])->assertNotFound();
        $this->postJson(self::BASE . '/submissions/abc/convert-to-borrower')->assertNotFound();
    }

    #[Test]
    public function search_finds_an_application_by_its_business(): void
    {
        $this->submission();
        $this->submission(['business_name' => 'Zenith Bakery', 'business_email' => 'hello@zenith-bakery.example.test']);
        Passport::actingAs($this->user(self::PERMISSIONS), [], 'api');

        $this->postJson(self::BASE . '/submissions/fetch', ['search' => 'Zenith Bak'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);

        $this->postJson(self::BASE . '/submissions/fetch', ['search' => 'zenith-bakery.example'])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function another_partners_application_is_forbidden(): void
    {
        $other = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Other Partner',
            'full_name' => 'Other Partner',
            'email' => 'other-' . uniqid() . '@example.test',
        ]);
        $foreign = $this->submission(['partner_id' => $other->id]);
        Passport::actingAs($this->user(self::PERMISSIONS), [], 'api');

        $this->getJson(self::BASE . "/submissions/{$foreign->id}")->assertForbidden();
        $this->putJson(self::BASE . "/submissions/{$foreign->id}/status", ['status' => 'rejected'])->assertForbidden();
        $this->postJson(self::BASE . "/submissions/{$foreign->id}/convert-to-borrower")->assertForbidden();
        $this->assertSame(EmbedFormSubmission::STATUS_NEW, $foreign->fresh()->status);
    }

    #[Test]
    public function an_unknown_form_key_is_not_found_and_names_no_fields(): void
    {
        $this->postJson('/api/v1/embed/validate-step/share_pub_unknown/1', [])
            ->assertNotFound()
            ->assertJsonMissingPath('errors');

        $this->postJson('/api/v1/embed/submit/share_pub_unknown', [])
            ->assertNotFound()
            ->assertJsonMissingPath('errors');
    }

    #[Test]
    public function a_deactivated_form_is_not_found_and_names_no_fields(): void
    {
        $this->config->update(['is_active' => false]);

        $this->postJson("/api/v1/embed/submit/{$this->config->public_key}", [])
            ->assertNotFound()
            ->assertJsonMissingPath('errors');
    }

    #[Test]
    public function a_live_form_still_validates_its_fields(): void
    {
        $this->postJson("/api/v1/embed/validate-step/{$this->config->public_key}/1", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['full_name', 'business_name']]);
    }
}
