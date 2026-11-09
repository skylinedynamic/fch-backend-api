<?php

namespace Tests\Unit\Commission;

use App\Models\Compensation\CompensationSetting;
use App\Models\Employee\Employee;
use App\Services\FCH\Common\Compensation\CompensationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * CompensationService reads a login's linked record to find whose compensation
 * it is, so a caller working through many users eager-loads [entity]. Once it
 * has, the lookup must not touch the relation again.
 */
#[CoversClass(CompensationService::class)]
class CompensationOwnerTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    #[Test]
    public function an_eager_loaded_employee_is_used_under_strict_lazy_loading(): void
    {
        Mail::fake();
        Notification::fake();

        $host = $this->makeHostPartner();
        $staff = $this->makeStaffUser($host);
        $setting = CompensationSetting::create([
            'partner_id' => $host->id,
            'entity_type' => Employee::class,
            'entity_id' => $staff->entity_id,
            'paid_by' => CompensationSetting::PAID_BY_SALARY,
            'amount' => 5000,
        ]);

        $staff->setRelation('entity', Employee::find($staff->entity_id));
        $staff->preventsLazyLoading = true;

        $rows = app(CompensationService::class)->getFor($staff);

        $this->assertSame([$setting->id], $rows->pluck('id')->all());
    }
}
