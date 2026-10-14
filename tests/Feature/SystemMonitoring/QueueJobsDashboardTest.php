<?php

namespace Tests\Feature\SystemMonitoring;

use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The "Queue Jobs" menu of the system monitoring app, which shows Horizon.
 *
 * Job payloads carry borrower data (emails, names, invoice details), so both
 * the menu page and Horizon itself must stay behind the monitoring login.
 */
class QueueJobsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function developer(): User
    {
        return User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'Developer',
            'email' => 'dev-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/dashboard/queues')->assertRedirect(route('login'));
    }

    #[Test]
    public function a_signed_in_user_sees_horizon_embedded(): void
    {
        $this->actingAs($this->developer())
            ->get(route('dashboard.queues'))
            ->assertOk()
            ->assertSee('Queue Jobs')
            ->assertSee('src="/horizon"', false);
    }

    #[Test]
    public function the_sidebar_links_to_the_queue_jobs_page(): void
    {
        $this->actingAs($this->developer())
            ->get(route('dashboard'))
            ->assertSee(route('dashboard.queues'), false);
    }

    #[Test]
    public function horizon_is_closed_to_guests(): void
    {
        $this->assertFalse(Gate::forUser(null)->allows('viewHorizon'));
        $this->get('/horizon')->assertForbidden();
    }

    #[Test]
    public function horizon_is_open_to_a_signed_in_monitoring_user(): void
    {
        $this->assertTrue(Gate::forUser($this->developer())->allows('viewHorizon'));
    }
}
