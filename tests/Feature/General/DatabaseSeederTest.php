<?php

namespace Tests\Feature\General;

use App\Models\Partner\SubscriptionPlan;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `php artisan migrate:fresh --seed` on an empty database - the one command a
 * new developer (or CI) runs to get a working app.
 *
 * Defect: the seeders had drifted from the migrations - BorrowerSeeder wrote the
 * dropped last_4_ssn column, the subscription seeders wrote dropped columns, one
 * permission seeder lazy-loaded a relation (an exception outside production), and
 * two vendor/tier seeders used MySQL-only SET FOREIGN_KEY_CHECKS - so the run
 * died part-way and nobody could seed a fresh database.
 *
 * Seeding writes Passport keys and may copy assets, so storage and public paths
 * point at a throwaway folder and no disk, mail or HTTP call leaves the test.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/fch-seed-' . uniqid();
        foreach (['storage/app/public', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs', 'public'] as $dir) {
            File::ensureDirectoryExists("{$this->root}/{$dir}");
        }
        foreach (glob(storage_path('oauth-*.key')) as $key) {
            File::copy($key, "{$this->root}/storage/" . basename($key));
        }
        $this->app->useStoragePath("{$this->root}/storage");
        $this->app->usePublicPath("{$this->root}/public");

        foreach (array_keys(config('filesystems.disks')) as $disk) {
            Storage::fake($disk);
        }
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    #[Test]
    public function the_full_seed_runs_on_a_fresh_database(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('partners', ['is_host' => 1]);
        $this->assertGreaterThan(0, \App\Models\Borrower\Borrower::count());
        $this->assertGreaterThan(0, SubscriptionPlan::count());
        // Billing finds a plan by its group, so a seeded plan without one is unusable.
        $this->assertSame(0, SubscriptionPlan::whereNull('group')->count());
    }

    /**
     * Defect (O11): every partner's seeded staff were user_type Admin, which
     * passes the host-only `admin` middleware. Real staff creation copies the
     * creator's type, so a non-host partner's staff are Partner.
     */
    #[Test]
    public function seeded_staff_get_the_user_type_their_partner_would_give_them(): void
    {
        $this->seed(DatabaseSeeder::class);

        $staff = \App\Models\User\User::where('entity_type', \App\Models\Employee\Employee::class)
            ->with('partner')
            ->get();

        $this->assertNotEmpty($staff);
        foreach ($staff as $user) {
            $expected = $user->partner?->is_host ? \App\Models\User\User::ADMIN : \App\Models\User\User::PARTNER;
            $this->assertSame($expected, $user->user_type, $user->email);
        }
    }

    #[Test]
    public function seeded_borrowers_hold_a_full_encrypted_ssn(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (\App\Models\Borrower\Borrower::whereNotNull('ssn_encrypted')->get() as $borrower) {
            $this->assertMatchesRegularExpression('/^\d{9}$/', (string) $borrower->ssn);
        }
        $this->assertGreaterThan(0, \App\Models\Borrower\Borrower::whereNotNull('ssn_encrypted')->count());
    }
}
