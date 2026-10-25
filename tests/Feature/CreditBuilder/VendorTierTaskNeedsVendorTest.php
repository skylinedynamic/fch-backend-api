<?php

namespace Tests\Feature\CreditBuilder;

use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\TierTaskController;
use App\Models\CreditBuilder\CreditBuilderVendor;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-095: a task on the vendor tier (Tier 1, has_vendor) that is not
 * manually verified is verified through its vendor, so it needs one. Only the
 * Add Task dialog checked that, and only once it knew the tier - so a Tier 1
 * task could be saved without a vendor. The API now refuses it.
 */
#[CoversClass(TierTaskController::class)]
class VendorTierTaskNeedsVendorTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $hostAdmin;
    private Tier $vendorTier;
    private Tier $otherTier;
    private CreditBuilderVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostAdmin = $this->makePartnerAdmin($this->makeHostPartner());
        $this->vendorTier = Tier::create(['name' => 'Tier 1', 'title' => 'Vendor Credit', 'code' => 'T1', 'has_vendor' => true]);
        $this->otherTier = Tier::create(['name' => 'Tier 0', 'title' => 'Foundation', 'code' => 'T0', 'has_vendor' => false]);
        $this->vendor = CreditBuilderVendor::create(['name' => 'Uline', 'tier_id' => $this->vendorTier->id, 'status' => CreditBuilderVendor::STATUS_ACTIVE]);
        $this->actingAsUser($this->hostAdmin);
    }

    private function create(array $task)
    {
        return $this->postJson('/api/v1/admin/credit-builder/tier-tasks', array_merge(['title' => 'Open a vendor account'], $task));
    }

    #[Test]
    public function a_vendor_tier_task_without_a_vendor_is_refused(): void
    {
        $this->create(['tier_id' => $this->vendorTier->id, 'vendor_id' => null, 'manual_verification' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vendor_id' => __('tier_task.validation_vendor_required')]);

        $this->assertSame(0, TierTask::count());
    }

    #[Test]
    public function a_vendor_tier_task_with_its_vendor_is_saved(): void
    {
        $this->create(['tier_id' => $this->vendorTier->id, 'vendor_id' => $this->vendor->id])->assertSuccessful();

        $this->assertSame($this->vendor->id, TierTask::first()->vendor_id);
    }

    #[Test]
    public function a_manually_verified_vendor_tier_task_needs_no_vendor(): void
    {
        $this->create(['tier_id' => $this->vendorTier->id, 'vendor_id' => null, 'manual_verification' => 1])->assertSuccessful();
    }

    #[Test]
    public function another_tiers_task_needs_no_vendor(): void
    {
        $this->create(['tier_id' => $this->otherTier->id, 'vendor_id' => null])->assertSuccessful();
    }

    #[Test]
    public function an_edit_cannot_take_the_vendor_away(): void
    {
        $task = TierTask::create(['tier_id' => $this->vendorTier->id, 'title' => 'Open a vendor account', 'vendor_id' => $this->vendor->id]);

        $this->putJson("/api/v1/admin/credit-builder/tier-tasks/{$task->id}", ['title' => 'Open an account', 'vendor_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vendor_id']);

        $this->assertSame($this->vendor->id, $task->fresh()->vendor_id);
    }

    #[Test]
    public function an_edit_that_leaves_the_vendor_alone_is_saved(): void
    {
        $task = TierTask::create(['tier_id' => $this->vendorTier->id, 'title' => 'Open a vendor account', 'vendor_id' => $this->vendor->id]);

        $this->putJson("/api/v1/admin/credit-builder/tier-tasks/{$task->id}", ['title' => 'Open an account'])->assertOk();

        $this->assertSame('Open an account', $task->fresh()->title);
    }
}
