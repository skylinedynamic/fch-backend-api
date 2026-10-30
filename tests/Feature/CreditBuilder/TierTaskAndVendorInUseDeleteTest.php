<?php

namespace Tests\Feature\CreditBuilder;

use App\Models\Borrower\MigratedTask;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\CreditBuilderVendor;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-095 follow-up: a tier task or vendor clients are already using cannot
 * be deleted. Deleting one left the clients' records pointing at nothing, and
 * the Credit Guide, the client's Credit Builder tab and approving its pending
 * verification failed on the missing task. A delete that names one in use
 * deletes nothing and says which.
 */
class TierTaskAndVendorInUseDeleteTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $host;

    private Tier $tier;

    private CreditBuilderVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->actingAsUser($this->makePartnerAdmin($this->host));
        $this->tier = Tier::create(['name' => 'Tier 1', 'title' => 'Vendor Credit', 'code' => 'T1', 'has_vendor' => true]);
        $this->vendor = CreditBuilderVendor::create(['name' => 'Uline', 'tier_id' => $this->tier->id, 'status' => CreditBuilderVendor::STATUS_ACTIVE]);
    }

    private function task(string $title, ?CreditBuilderVendor $vendor = null): TierTask
    {
        return TierTask::create(['tier_id' => $this->tier->id, 'title' => $title, 'vendor_id' => $vendor?->id]);
    }

    private function verificationFor(TierTask $task): void
    {
        $client = $this->makeBorrower($this->host);
        BorrowerTaskVerificationRequest::create([
            'borrower_id' => $client->id,
            'partner_id' => $this->host->id,
            'tier_id' => $task->tier_id,
            'task_id' => $task->id,
            'status' => BorrowerTaskVerificationRequest::COMPLETED,
        ]);
    }

    private function deleteTasks(array $ids)
    {
        return $this->postJson('/api/v1/admin/credit-builder/tier-tasks-multiple-delete', ['ids' => $ids]);
    }

    #[Test]
    public function a_task_a_client_has_worked_on_is_not_deleted(): void
    {
        $task = $this->task('Open a Uline account');
        $this->verificationFor($task);

        $this->deleteTasks([$task->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('tier_task.error_in_use'))
            ->assertJsonPath('errors.in_use', [['id' => $task->id, 'name' => 'Open a Uline account']]);

        $this->assertNotSoftDeleted($task);
    }

    #[Test]
    public function a_task_migrated_to_a_client_is_not_deleted(): void
    {
        $task = $this->task('Open a Uline account', $this->vendor);
        $task->update(['vendor_id' => null]);
        $client = $this->makeBorrower($this->host);
        MigratedTask::create(['borrower_id' => $client->id, 'partner_id' => $this->host->id, 'task_id' => $task->id, 'vendor_id' => $this->vendor->id, 'tier_from' => $this->tier->id, 'tier_to' => $this->tier->id + 1, 'status' => 'pending']);

        $this->deleteTasks([$task->id])->assertStatus(422);

        $this->assertNotSoftDeleted($task);
    }

    #[Test]
    public function one_task_in_use_keeps_the_whole_delete_from_happening(): void
    {
        $used = $this->task('Open a Uline account');
        $this->verificationFor($used);
        $unused = $this->task('Unused task');

        // Only the one in use is named, so the user knows what to leave out.
        $this->deleteTasks([$unused->id, $used->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.in_use', [['id' => $used->id, 'name' => 'Open a Uline account']]);

        $this->assertNotSoftDeleted($unused);
        $this->assertNotSoftDeleted($used);
    }

    #[Test]
    public function a_task_no_client_has_used_is_deleted(): void
    {
        $task = $this->task('Unused task');

        $this->deleteTasks([$task->id])->assertOk();

        $this->assertSoftDeleted($task);
    }

    #[Test]
    public function a_vendor_linked_to_a_task_is_not_deleted(): void
    {
        $this->task('Open a Uline account', $this->vendor);

        $this->deleteJson("/api/v1/admin/credit-builder/vendors/{$this->vendor->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', __('credit_builder.vendor_in_use'))
            ->assertJsonPath('errors.in_use', [['id' => $this->vendor->id, 'name' => 'Uline']]);

        $this->assertNotSoftDeleted($this->vendor);
    }

    #[Test]
    public function a_vendor_no_task_uses_is_deleted(): void
    {
        $this->deleteJson("/api/v1/admin/credit-builder/vendors/{$this->vendor->id}")->assertOk();

        $this->assertSoftDeleted($this->vendor);
    }

    #[Test]
    public function several_vendors_are_deleted_together_unless_one_is_in_use(): void
    {
        $spare = CreditBuilderVendor::create(['name' => 'Quill', 'tier_id' => $this->tier->id, 'status' => CreditBuilderVendor::STATUS_ACTIVE]);
        $other = CreditBuilderVendor::create(['name' => 'Grainger', 'tier_id' => $this->tier->id, 'status' => CreditBuilderVendor::STATUS_ACTIVE]);
        $this->task('Open a Uline account', $this->vendor);

        $this->postJson('/api/v1/admin/credit-builder/vendors-multiple-delete', ['ids' => [$spare->id, $this->vendor->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.in_use', [['id' => $this->vendor->id, 'name' => 'Uline']]);
        $this->assertNotSoftDeleted($spare);

        $this->postJson('/api/v1/admin/credit-builder/vendors-multiple-delete', ['ids' => [$spare->id, $other->id]])->assertOk();
        $this->assertSoftDeleted($spare);
        $this->assertSoftDeleted($other);
    }

    #[Test]
    public function deleting_vendors_needs_ids(): void
    {
        $this->postJson('/api/v1/admin/credit-builder/vendors-multiple-delete', ['ids' => []])->assertStatus(422);
    }
}
