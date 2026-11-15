<?php

namespace Tests\Integration\Partner;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Partner\Partner;
use App\Services\FCH\Partner\PartnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * PartnerService::updateStatusAgent - the Status & Agent tab's save.
 */
#[CoversClass(PartnerService::class)]
class PartnerStatusAgentServiceTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    #[Test]
    public function suspending_a_partner_signs_its_login_out_and_records_the_change(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeHostPartner()));
        $agent = $this->makeUser(['name' => 'Alex Agent']);
        $partner = $this->makeCertifiedPartner(['status' => Partner::STATUS_ACTIVE]);
        $login = $this->makePartnerAdmin($partner);
        DB::table('oauth_access_tokens')->insert([
            'id' => Str::random(40), 'user_id' => $login->id, 'client_id' => 1,
            'name' => 'test', 'scopes' => '[]', 'revoked' => false,
            'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addDay(),
        ]);

        $updated = app(PartnerService::class)->updateStatusAgent($partner, [
            'status' => Partner::STATUS_SUSPENDED,
            'assign_to' => $agent->id,
        ]);

        $this->assertSame(Partner::STATUS_SUSPENDED, $updated->status);
        $this->assertSame('Alex Agent', $updated->assignedUser->name);
        $this->assertFalse(DB::table('oauth_access_tokens')->where('user_id', $login->id)->where('revoked', false)->exists());

        $log = ActivityLog::where('subject_id', $partner->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Suspended', (string) $log->description);
    }

    #[Test]
    public function saving_the_same_status_and_agent_records_nothing(): void
    {
        $agent = $this->makeUser();
        $partner = $this->makeCertifiedPartner(['status' => Partner::STATUS_ACTIVE, 'assign_to' => $agent->id]);
        $before = ActivityLog::count();

        app(PartnerService::class)->updateStatusAgent($partner, [
            'status' => Partner::STATUS_ACTIVE,
            'assign_to' => $agent->id,
        ]);

        $this->assertSame($before, ActivityLog::count());
    }
}
