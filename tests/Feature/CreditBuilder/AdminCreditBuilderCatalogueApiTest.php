<?php

namespace Tests\Feature\CreditBuilder;

use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\CreditAccountController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\CreditBuilderVendorController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\TaskVerificationController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\TierController;
use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\TierTaskController;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The host's Credit Builder catalogue: /admin/credit-builder/* - tiers, tier
 * tasks, vendors, credit accounts, and marking a client's tasks verified.
 * The admin route group admits the host's team only.
 */
#[CoversClass(TierController::class)]
#[CoversClass(TierTaskController::class)]
#[CoversClass(CreditBuilderVendorController::class)]
#[CoversClass(CreditAccountController::class)]
#[CoversClass(TaskVerificationController::class)]
class AdminCreditBuilderCatalogueApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
    }

    private function tier(array $attributes = []): Tier
    {
        return Tier::create(array_merge(['name' => 'Tier ' . uniqid(), 'title' => 'Foundation', 'code' => 'T' . random_int(10, 99)], $attributes));
    }

    public static function catalogueRoutes(): array
    {
        return [
            'tiers' => ['POST', '/api/v1/admin/credit-builder/tiers/fetch-tiers'],
            'tier create' => ['POST', '/api/v1/admin/credit-builder/tiers'],
            'tier tasks' => ['POST', '/api/v1/admin/credit-builder/tier-tasks/fetch-tasks'],
            'vendors' => ['POST', '/api/v1/admin/credit-builder/vendors/fetch-vendors'],
            'credit accounts' => ['POST', '/api/v1/admin/credit-builder/credit-accounts/fetch-accounts'],
            'client progress' => ['POST', '/api/v1/admin/credit-builder/tiers/borrowers/progress'],
            'task verification' => ['POST', '/api/v1/admin/credit-builder/tasks/update-status'],
        ];
    }

    #[Test]
    #[DataProvider('catalogueRoutes')]
    public function only_the_hosts_team_reaches_the_catalogue(string $method, string $url): void
    {
        $this->json($method, $url)->assertUnauthorized();

        foreach ([$this->makePartnerAdmin($this->makeWhiteLabelPartner()), $this->makeBorrowerUser($this->host)] as $outsider) {
            $this->actingAsUser($outsider);
            $this->json($method, $url)->assertForbidden();
        }
    }

    #[Test]
    public function the_host_creates_edits_lists_and_deletes_a_tier(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $id = $this->postJson('/api/v1/admin/credit-builder/tiers', ['name' => 'Tier 9', 'title' => 'Scale'])
            ->assertSuccessful()->json('data.id');
        $this->getJson("/api/v1/admin/credit-builder/tiers/{$id}")->assertOk()->assertJsonPath('data.name', 'Tier 9');
        $this->putJson("/api/v1/admin/credit-builder/tiers/{$id}", ['name' => 'Tier 9', 'title' => 'Scale Up'])->assertOk();
        $this->assertSame('Scale Up', Tier::find($id)->title);
        $this->postJson('/api/v1/admin/credit-builder/tiers/fetch-tiers')->assertOk();

        $this->postJson('/api/v1/admin/credit-builder/tiers-multiple-delete', ['ids' => [$id]])->assertOk();
        $this->assertNull(Tier::find($id));
    }

    #[Test]
    public function a_tier_needs_a_name_and_title(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/credit-builder/tiers', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'title']);
    }

    #[Test]
    public function the_host_manages_a_tiers_tasks(): void
    {
        $tier = $this->tier();
        $this->actingAsUser($this->hostAdmin);

        $id = $this->postJson('/api/v1/admin/credit-builder/tier-tasks', ['tier_id' => $tier->id, 'title' => 'Get an EIN'])
            ->assertSuccessful()->json('data.id');
        $this->getJson("/api/v1/admin/credit-builder/tier-tasks/{$id}")->assertOk();
        $this->putJson("/api/v1/admin/credit-builder/tier-tasks/{$id}", ['tier_id' => $tier->id, 'title' => 'Register an EIN'])->assertOk();
        $this->assertSame('Register an EIN', TierTask::find($id)->title);

        $this->postJson('/api/v1/admin/credit-builder/tier-tasks-multiple-delete', ['ids' => [$id]])->assertOk();
        $this->assertNull(TierTask::find($id));
    }

    #[Test]
    public function a_task_belongs_to_an_existing_tier(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/credit-builder/tier-tasks', ['tier_id' => 999999, 'title' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors(['tier_id']);
    }

    #[Test]
    public function the_host_manages_vendors(): void
    {
        $tier = $this->tier();
        $this->actingAsUser($this->hostAdmin);

        $id = $this->postJson('/api/v1/admin/credit-builder/vendors', [
            'name' => 'Net-30 Supply', 'tier_id' => $tier->id, 'status' => 'Active', 'general_url' => 'https://vendor.example.test',
        ])->assertSuccessful()->json('data.id');
        $this->getJson("/api/v1/admin/credit-builder/vendors/{$id}")->assertOk();
        $this->deleteJson("/api/v1/admin/credit-builder/vendors/{$id}")->assertOk();
    }

    #[Test]
    public function a_vendor_needs_a_name_tier_status_and_url(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/credit-builder/vendors', [])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'tier_id', 'status', 'general_url']);
    }

    #[Test]
    public function a_credit_account_needs_its_card_issuer_and_eligibility(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/credit-builder/credit-accounts', ['reference_url' => 'not a url'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['card_name', 'issuer', 'typical_personal_fico_min', 'time_in_business_min_months', 'image_url', 'reference_url']);
        $this->postJson('/api/v1/admin/credit-builder/credit-accounts/fetch-accounts')->assertOk();
    }

    #[Test]
    public function marking_a_clients_tasks_needs_the_tasks_client_tier_and_a_reason_to_reject(): void
    {
        $tier = $this->tier();
        $task = TierTask::create(['tier_id' => $tier->id, 'title' => 'Get an EIN']);
        $borrower = $this->makeBorrower($this->makeWhiteLabelPartner());
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/credit-builder/tasks/update-status', [])
            ->assertStatus(422)->assertJsonValidationErrors(['tasks', 'borrower_id', 'tier_id', 'status']);
        $this->postJson('/api/v1/admin/credit-builder/tasks/update-status', [
            'tasks' => [['task_id' => $task->id]],
            'borrower_id' => $borrower->id,
            'tier_id' => $tier->id,
            'status' => 'rejected',
        ])->assertStatus(422)->assertJsonValidationErrors(['note']);
    }

    #[Test]
    public function a_clients_progress_and_tier_task_list_load_for_the_host(): void
    {
        $tier = $this->tier();
        $borrower = $this->makeBorrower($this->makeWhiteLabelPartner(), ['current_tier' => $tier->id]);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/admin/credit-builder/tiers/borrowers/progress', ['borrower_id' => $borrower->id])->assertOk();
        $this->postJson('/api/v1/admin/credit-builder/tiers/borrowers/tier-wise-tasklist', ['borrower_id' => $borrower->id])->assertOk();
        $this->getJson("/api/v1/admin/credit-builder/tier-related-api/{$tier->id}")->assertOk();
    }

    /**
     * A card's typical minimum FICO is compared with a borrower's FICO, so it
     * must be a FICO score itself. Digits-only let 0 through (one is stored).
     *
     * @return iterable<string, array{mixed}>
     */
    public static function badFicoMinimums(): iterable
    {
        yield 'zero' => [0];
        yield 'below the scale' => [299];
        yield 'above the scale' => [851];
        yield 'a fraction' => ['650.5'];
    }

    #[Test]
    #[DataProvider('badFicoMinimums')]
    public function a_credit_accounts_typical_fico_minimum_must_be_a_fico_score(mixed $minimum): void
    {
        $account = DB::table('credit_accounts')->insertGetId(['card_name' => 'Card', 'status' => 'Active', 'typical_personal_fico_min' => 650, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsUser($this->hostAdmin);
        $message = __('common.fico_score_invalid', ['min' => 300, 'max' => 850]);

        $this->postJson('/api/v1/admin/credit-builder/credit-accounts', ['typical_personal_fico_min' => $minimum])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['typical_personal_fico_min' => $message]);
        $this->putJson("/api/v1/admin/credit-builder/credit-accounts/{$account}", ['typical_personal_fico_min' => $minimum])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['typical_personal_fico_min' => $message]);

        $this->assertSame(650, (int) DB::table('credit_accounts')->where('id', $account)->value('typical_personal_fico_min'));
    }

    #[Test]
    public function a_credit_accounts_typical_fico_minimum_on_the_scale_passes_its_own_check(): void
    {
        $this->actingAsUser($this->hostAdmin);

        // The rest of the form is missing, so the request still fails - but not on this field.
        $this->postJson('/api/v1/admin/credit-builder/credit-accounts', ['typical_personal_fico_min' => '640'])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors(['typical_personal_fico_min']);
    }

    #[Test]
    public function a_credit_account_is_switched_between_active_and_inactive_only(): void
    {
        $account = DB::table('credit_accounts')->insertGetId(['card_name' => 'Card', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->patchJson("/api/v1/admin/credit-builder/credit-accounts/{$account}/status", ['status' => 'Inactive'])->assertOk();
        $this->assertSame('Inactive', DB::table('credit_accounts')->where('id', $account)->value('status'));

        $this->patchJson("/api/v1/admin/credit-builder/credit-accounts/{$account}/status", ['status' => 'Whatever'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
