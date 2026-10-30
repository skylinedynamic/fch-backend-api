<?php

namespace Tests\Feature\Agreement;

use App\Models\Borrower\Borrower;
use App\Models\Common\AgreementTemplate;
use App\Models\Common\AgreementTemplateVersion;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\ManageAgreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Every change to an agreement's wording is kept as a version that never
 * changes afterwards, and every signature records the version that was
 * signed - so it is always known exactly which text someone agreed to, even
 * after the partner edits the template.
 */
#[CoversClass(AgreementTemplateVersion::class)]
#[CoversClass(AgreementService::class)]
class AgreementTemplateVersionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['agreement.image_disk' => 'local', 'agreement.image_directory' => 'agreement-images']);

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        DB::table('app_configurations')->insert([
            'partner_id' => $this->host->id, 'module' => 'agreement', 'key' => 'agreement_signing_enabled', 'value' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function save(string $html, ?string $json = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/common/agreement-templates/save', array_filter([
            'slug' => 'borrower', 'template_html' => $html, 'template_json' => $json,
        ]));
    }

    private function borrowerTemplate(string $html = '<p>Terms v1</p>'): AgreementTemplate
    {
        return AgreementTemplate::create([
            'partner_id' => $this->partner->id, 'slug' => 'borrower', 'is_active' => true, 'template_html' => $html,
        ]);
    }

    #[Test]
    public function each_change_of_wording_is_kept_as_a_new_version(): void
    {
        $admin = $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $id = $this->save('<p>Terms v1</p>', '{"blocks":[1]}')->assertOk()->assertJsonPath('data.version', 1)->json('data.id');
        $this->save('<p>Terms v2</p>', '{"blocks":[2]}')->assertOk()->assertJsonPath('data.version', 2);

        $versions = AgreementTemplateVersion::where('agreement_template_id', $id)->orderBy('version')->get();
        $this->assertSame([1, 2], $versions->pluck('version')->all());
        $this->assertSame('<p>Terms v1</p>', $versions[0]->template_html);
        $this->assertSame('{"blocks":[1]}', $versions[0]->template_json);
        $this->assertSame('<p>Terms v2</p>', $versions[1]->template_html);
        $this->assertSame($admin->id, $versions[1]->created_by);
    }

    #[Test]
    public function saving_the_same_wording_or_switching_it_on_and_off_adds_no_version(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $id = $this->save('<p>Terms</p>')->json('data.id');
        $this->save('<p>Terms</p>')->assertOk()->assertJsonPath('data.version', 1);
        $this->postJson("/api/v1/common/agreement-templates/update-status/{$id}", ['is_active' => false])->assertOk();

        $this->assertSame(1, AgreementTemplateVersion::where('agreement_template_id', $id)->count());
    }

    #[Test]
    public function a_version_cannot_be_changed_or_deleted_once_kept(): void
    {
        $version = $this->borrowerTemplate()->currentVersion();

        $version->template_html = '<p>Changed</p>';
        try {
            $version->save();
            $this->fail('A kept version was changed.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $version->delete();
    }

    #[Test]
    public function a_template_saved_before_versions_existed_gets_its_first_version_when_needed(): void
    {
        $id = DB::table('agreement_templates')->insertGetId([
            'partner_id' => $this->partner->id, 'slug' => 'borrower', 'is_active' => true,
            'template_html' => '<p>Old terms</p>', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $version = AgreementTemplate::find($id)->currentVersion();

        $this->assertSame(1, $version->version);
        $this->assertSame('<p>Old terms</p>', $version->template_html);
    }

    #[Test]
    public function the_borrower_is_told_which_version_they_are_asked_to_sign(): void
    {
        $version = $this->borrowerTemplate()->currentVersion();
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->getJson('/api/v1/borrower/agreement-sign/check')
            ->assertOk()
            ->assertJsonPath('data.template.version_id', $version->id)
            ->assertJsonPath('data.template.version', 1);
    }

    #[Test]
    public function a_signature_records_the_version_that_was_signed(): void
    {
        $template = $this->borrowerTemplate();
        $version = $template->currentVersion();
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->postJson('/api/v1/borrower/agreement-sign/submit', [
            'agreement_template_id' => $template->id, 'agreement_template_version_id' => $version->id,
        ])->assertOk();

        $this->assertDatabaseHas('agreement_signs', ['user_id' => $me->id, 'agreement_template_version_id' => $version->id]);
    }

    /**
     * The partner edited the agreement while the borrower had it open: the
     * borrower must not be recorded as agreeing to wording they never saw.
     */
    #[Test]
    public function signing_wording_that_has_since_changed_is_refused(): void
    {
        $template = $this->borrowerTemplate();
        $seen = $template->currentVersion();
        $template->update(['template_html' => '<p>Terms v2</p>']);
        $template->currentVersion();
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->postJson('/api/v1/borrower/agreement-sign/submit', [
            'agreement_template_id' => $template->id, 'agreement_template_version_id' => $seen->id,
        ])->assertStatus(409)->assertJsonPath('status', false)->assertJsonPath('message', __('agreement.version_outdated'));

        $this->assertFalse((bool) Borrower::find($me->entity_id)->has_sign);
        $this->assertDatabaseCount('agreement_signs', 0);
    }

    #[Test]
    public function a_version_of_another_agreement_is_refused(): void
    {
        $template = $this->borrowerTemplate();
        $other = AgreementTemplate::create([
            'partner_id' => $this->makeCertifiedPartner()->id, 'slug' => 'borrower', 'is_active' => true, 'template_html' => '<p>Other</p>',
        ])->currentVersion();
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->postJson('/api/v1/borrower/agreement-sign/submit', [
            'agreement_template_id' => $template->id, 'agreement_template_version_id' => $other->id,
        ])->assertJsonPath('status', false);

        $this->assertDatabaseCount('agreement_signs', 0);
    }

    /** A portal that does not send the version yet signs the current one. */
    #[Test]
    public function signing_without_naming_a_version_records_the_current_one(): void
    {
        $template = $this->borrowerTemplate();
        $template->update(['template_html' => '<p>Terms v2</p>']);
        $me = $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->postJson('/api/v1/borrower/agreement-sign/submit', ['agreement_template_id' => $template->id])->assertOk();

        $signed = DB::table('agreement_signs')->where('user_id', $me->id)->value('agreement_template_version_id');
        $this->assertSame('<p>Terms v2</p>', AgreementTemplateVersion::find($signed)->template_html);
    }
}
