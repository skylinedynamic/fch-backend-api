<?php

namespace Tests\Feature\Agreement;

use App\Http\Controllers\Api\V1\FCH\Common\ManageAgreement\AgreementSavedTemplateController;
use App\Models\Common\AgreementSavedTemplate;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Agreement Builder › Save as Template: a partner keeps the agreement it is
 * editing as a named template to start from later (Templates). A partner saves
 * its borrower agreement; the host saves both its partner and its borrower
 * agreement. Templates belong to the partner that saved them.
 */
#[CoversClass(AgreementSavedTemplateController::class)]
class AgreementSavedTemplateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const URL = '/api/v1/common/agreement-templates/saved';

    private const JSON = '{"blocks":[{"id":"a1","type":"text","content":"Our terms"}],"pagePad":80,"docBg":"#ffffff"}';

    private Partner $host;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner(['company_name' => 'Acme Lending']);
    }

    private function signIn(Partner $partner, array $permissions = []): User
    {
        return $this->actingAsUser($this->makePartnerAdmin($partner, $permissions));
    }

    private function save(array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::URL, array_merge([
            'slug' => 'borrower',
            'name' => 'Short form',
            'template_json' => self::JSON,
        ], $data));
    }

    #[Test]
    public function a_partner_saves_its_borrower_agreement_as_a_template_and_lists_it(): void
    {
        $this->signIn($this->partner);

        $id = $this->save()->assertOk()
            ->assertJsonPath('data.name', 'Short form')
            ->assertJsonPath('data.slug', 'borrower')
            ->json('data.id');

        $this->assertSame($this->partner->id, AgreementSavedTemplate::find($id)->partner_id);

        $this->getJson(self::URL . '?slug=borrower')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.name', 'Short form')
            // The list is light: the blocks come with the one template picked.
            ->assertJsonMissingPath('data.0.template_json');

        $this->getJson(self::URL . "/{$id}")->assertOk()
            ->assertJsonPath('data.template_json', self::JSON);
    }

    #[Test]
    public function a_partner_cannot_save_a_partner_agreement_template(): void
    {
        $this->signIn($this->partner);

        $this->save(['slug' => 'partner'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->assertSame(0, AgreementSavedTemplate::count());
    }

    #[Test]
    public function the_host_saves_both_agreements_and_each_lists_only_its_own(): void
    {
        $this->signIn($this->host);

        $this->save(['slug' => 'partner', 'name' => 'Partner short'])->assertOk();
        $this->save(['slug' => 'borrower', 'name' => 'Borrower short'])->assertOk();

        $this->getJson(self::URL . '?slug=partner')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Partner short');
        $this->getJson(self::URL . '?slug=borrower')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Borrower short');
    }

    #[Test]
    public function the_newest_template_is_listed_first(): void
    {
        $this->signIn($this->partner);

        $this->save(['name' => 'Older'])->assertOk();
        $this->travel(1)->minutes();
        $this->save(['name' => 'Newer'])->assertOk();

        $this->assertSame(['Newer', 'Older'], array_column($this->getJson(self::URL . '?slug=borrower')->json('data'), 'name'));
    }

    #[Test]
    public function a_name_and_the_agreement_are_required(): void
    {
        $this->signIn($this->partner);

        $this->save(['name' => '', 'template_json' => ''])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'template_json']);
        $this->save(['name' => str_repeat('a', AgreementSavedTemplate::NAME_MAX + 1)])->assertStatus(422)
            ->assertJsonValidationErrors('name');
        $this->save(['slug' => 'vendor'])->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    #[Test]
    public function a_name_is_used_once_per_agreement_type(): void
    {
        $this->signIn($this->host);

        $this->save(['name' => 'Short form'])->assertOk();

        $this->save(['name' => ' short FORM '])->assertStatus(422)->assertJsonValidationErrors('name');
        // The other agreement type may use it.
        $this->save(['slug' => 'partner', 'name' => 'Short form'])->assertOk();
    }

    #[Test]
    public function another_partner_may_use_the_same_name(): void
    {
        $this->signIn($this->host);
        $this->save(['name' => 'Short form'])->assertOk();

        $this->signIn($this->partner);
        $this->save(['name' => 'Short form'])->assertOk();
    }

    #[Test]
    public function a_partner_never_sees_or_deletes_another_partners_templates(): void
    {
        $this->signIn($this->host);
        $id = $this->save()->assertOk()->json('data.id');

        $this->signIn($this->partner);
        $this->getJson(self::URL . '?slug=borrower')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::URL . "/{$id}")->assertNotFound();
        $this->deleteJson(self::URL . "/{$id}")->assertNotFound();

        $this->assertNotNull(AgreementSavedTemplate::find($id));
    }

    #[Test]
    public function a_partner_deletes_its_own_template(): void
    {
        $this->signIn($this->partner);
        $id = $this->save()->assertOk()->json('data.id');

        $this->deleteJson(self::URL . "/{$id}")->assertOk();

        $this->assertNull(AgreementSavedTemplate::find($id));
        $this->getJson(self::URL . '?slug=borrower')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_host_saves_a_template_for_a_partner_from_its_configuration(): void
    {
        $this->signIn($this->host, ['manage partner configuration']);

        $id = $this->save(['partner_id' => $this->partner->id])->assertOk()->json('data.id');

        $this->assertSame($this->partner->id, AgreementSavedTemplate::find($id)->partner_id);
        $this->getJson(self::URL . "?slug=borrower&partner_id={$this->partner->id}")->assertOk()->assertJsonCount(1, 'data');
        // Not the host's own list.
        $this->getJson(self::URL . '?slug=borrower')->assertOk()->assertJsonCount(0, 'data');
        // A partner's agreement there is its borrower agreement only.
        $this->save(['slug' => 'partner', 'name' => 'Other', 'partner_id' => $this->partner->id])
            ->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    #[Test]
    public function naming_another_partner_needs_the_host_and_its_permission(): void
    {
        $this->signIn($this->host);
        $this->save(['partner_id' => $this->partner->id])->assertForbidden();

        $other = $this->makeWhiteLabelPartner();
        $this->signIn($other, ['manage partner configuration']);
        $this->save(['partner_id' => $this->partner->id])->assertForbidden();
        $this->getJson(self::URL . "?slug=borrower&partner_id={$this->partner->id}")->assertForbidden();

        $this->assertSame(0, AgreementSavedTemplate::count());
    }

    #[Test]
    public function a_guest_cannot_use_saved_templates(): void
    {
        $this->getJson(self::URL . '?slug=borrower')->assertUnauthorized();
        $this->save()->assertUnauthorized();
    }
}
