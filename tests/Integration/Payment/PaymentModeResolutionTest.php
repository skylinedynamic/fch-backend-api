<?php

namespace Tests\Integration\Payment;

use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\PaymentMethod\PaymentMethodService;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use App\Services\GlobalPayment\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Stripe;
use Tests\TestCase;

/**
 * The save path and the charge path must agree on which Stripe account is live.
 *
 * These wire the real components together - the settings save service, the
 * key-serving service, the gateway manager, the linkage model - and enter them
 * directly rather than through a route. What is being tested is the seam between
 * them, which is exactly where the production bug lived: each component was
 * correct on its own, and two of them disagreed about where `payment_mode` was
 * stored.
 *
 * `payment_mode` had been promoted from the encrypted `configuration` blob to its
 * own column, and the save path deletes the inline copy on every save - but some
 * readers still looked for it in the blob, found nothing, and defaulted to
 * sandbox. So the publishable key sent to the browser came from production while
 * the secret key used to charge came from sandbox. Cards were tokenized live and
 * charged against test, and Stripe denied all knowledge of the token.
 *
 * Saving is what did the damage, so these never write a linkage by hand. They go
 * through PaymentMethodConfigurationService exactly as the settings screen does -
 * a hand-seeded row would not reproduce the bug at all.
 */
#[CoversClass(PaymentMethodConfigurationService::class)]
#[CoversClass(PaymentMethodService::class)]
class PaymentModeResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const PK_LIVE = 'pk_live_integration';
    private const SK_LIVE = 'sk_live_integration';
    private const PK_TEST = 'pk_test_integration';
    private const SK_TEST = 'sk_test_integration';

    private Partner $host;
    private PaymentProvider $stripe;
    private User $partnerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->stripe = PaymentProvider::create([
            'name' => PaymentProvider::STRIPE,
            'display_name' => 'Stripe',
            'status' => true,
        ]);

        // A partner-entity user: an enrollment fee is owed to the host, so both
        // the key lookup and the charge resolve to the host's linkage.
        $this->partnerUser = User::create([
            'name' => 'Partner Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
        ]);
    }

    /** Save through the real service, the way the settings screen does. */
    private function saveConfig(string $mode): PartnerPaymentProvider
    {
        return app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->host->id, [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => $mode,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => self::PK_TEST, 'secret_key' => self::SK_TEST],
                'production' => ['publishable_key' => self::PK_LIVE, 'secret_key' => self::SK_LIVE],
            ],
        ]);
    }

    /**
     * The secret key the charge path would actually transact with.
     *
     * driverFor() initializes the Stripe SDK, so the key it chose is readable
     * from the SDK itself without any request being made.
     */
    private function secretKeyTheGatewayWouldUse(): ?string
    {
        Stripe::setApiKey('sk_unset_sentinel');

        $manager = app(PaymentGatewayManager::class);
        $manager->driverFor($manager->resolvePartnerProvider($this->host->id));

        return Stripe::getApiKey();
    }

    /** The publishable key the browser would be handed. */
    private function keyServedToTheBrowser(): ?string
    {
        $this->actingAs($this->partnerUser);

        $providers = app(PaymentMethodService::class)->getActiveProvidersForPartner();

        return collect($providers)->firstWhere('name', PaymentProvider::STRIPE)['publishable_key'] ?? null;
    }

    private function runCleanupMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_09_23_120000_remove_payment_mode_from_partner_payment_provider_configurations.php'
        );

        $migration->up();
    }

    /**
     * The state the bug depended on: after a real save, the blob carries no
     * `payment_mode` at all, so anything reading it there gets nothing.
     */
    #[Test]
    public function saving_strips_the_inline_mode_and_records_it_on_the_column(): void
    {
        $linkage = $this->saveConfig(PaymentProvider::MODE_PRODUCTION);

        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $linkage->fresh()->payment_mode);
        $this->assertArrayNotHasKey('payment_mode', $linkage->fresh()->configuration);
    }

    /**
     * The failure itself. The browser tokenizes with one key; the server charges
     * with the other. If they come from different Stripe accounts the token does
     * not exist as far as the charge is concerned, which is the production error
     * verbatim.
     */
    #[Test]
    public function the_browsers_key_and_the_charging_key_come_from_the_same_account(): void
    {
        $this->saveConfig(PaymentProvider::MODE_PRODUCTION);

        $this->assertSame(self::PK_LIVE, $this->keyServedToTheBrowser());
        $this->assertSame(self::SK_LIVE, $this->secretKeyTheGatewayWouldUse());
    }

    /**
     * Sandbox must work the same way in reverse - a partner testing an
     * integration must not be handed live keys.
     */
    #[Test]
    public function sandbox_serves_and_charges_with_test_keys(): void
    {
        $this->saveConfig(PaymentProvider::MODE_SANDBOX);

        $this->assertSame(self::PK_TEST, $this->keyServedToTheBrowser());
        $this->assertSame(self::SK_TEST, $this->secretKeyTheGatewayWouldUse());
    }

    /**
     * Switching modes has to move both halves together, not just the badge.
     */
    #[Test]
    public function switching_back_to_sandbox_moves_both_halves(): void
    {
        $this->saveConfig(PaymentProvider::MODE_PRODUCTION);
        $this->assertSame(self::SK_LIVE, $this->secretKeyTheGatewayWouldUse());

        $this->saveConfig(PaymentProvider::MODE_SANDBOX);

        $this->assertSame(self::PK_TEST, $this->keyServedToTheBrowser());
        $this->assertSame(self::SK_TEST, $this->secretKeyTheGatewayWouldUse());
    }

    /**
     * A row as the 2026_08_09 migration left it: mode still inline, column
     * holding nothing but the `sandbox` default it was created with.
     */
    private function legacyRow(): PartnerPaymentProvider
    {
        return PartnerPaymentProvider::create([
            'partner_id' => $this->host->id,
            'payment_provider_id' => $this->stripe->id,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'payment_mode' => PaymentProvider::MODE_PRODUCTION,
                'sandbox' => ['publishable_key' => self::PK_TEST, 'secret_key' => self::SK_TEST],
                'production' => ['publishable_key' => self::PK_LIVE, 'secret_key' => self::SK_LIVE],
            ],
        ]);
    }

    /**
     * Such a row resolves SANDBOX at runtime, not production - and that is
     * deliberate.
     *
     * The column is NOT NULL with a `sandbox` default, so it is never empty and
     * the inline fallback never fires for this table. The two values genuinely
     * conflict here and only one direction of guess is safe: resolving the
     * unverified inline `production` would start moving real money off a row
     * nobody has confirmed. Under-reaching costs a declined test payment;
     * over-reaching charges a live card.
     */
    #[Test]
    public function a_legacy_row_fails_safe_to_sandbox_before_the_cleanup_runs(): void
    {
        $linkage = $this->legacyRow();

        $this->assertSame(PaymentProvider::MODE_SANDBOX, $linkage->fresh()->paymentMode());
        $this->assertSame(self::SK_TEST, $linkage->fresh()->secretKey());
    }

    /**
     * The cleanup migration copies that inline `production` onto the column and
     * drops the copy, so the row ends up resolving what the partner configured.
     *
     * A surviving inline copy means the row has not been saved since 2026-08-09,
     * because saving deletes it - so the copy is the last thing actually chosen,
     * while the column is only what `default('sandbox')` stamped in. That is why
     * the blob is trusted here and not at runtime: the migration can reason about
     * the whole row's history, and it logs every correction it makes.
     */
    #[Test]
    public function the_cleanup_migration_corrects_the_column_from_the_configuration(): void
    {
        $linkage = $this->legacyRow();

        $this->runCleanupMigration();

        $linkage = $linkage->fresh();

        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $linkage->payment_mode);
        $this->assertArrayNotHasKey('payment_mode', $linkage->configuration);
        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $linkage->paymentMode());
        $this->assertSame(self::SK_LIVE, $linkage->secretKey());
    }

    /**
     * The correction runs in both directions - the configuration is the record,
     * whichever way the two disagree.
     */
    #[Test]
    public function the_cleanup_migration_corrects_a_column_in_either_direction(): void
    {
        $linkage = PartnerPaymentProvider::create([
            'partner_id' => $this->host->id,
            'payment_provider_id' => $this->stripe->id,
            'status' => true,
            'is_default' => true,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'configuration' => [
                'payment_mode' => PaymentProvider::MODE_SANDBOX,
                'sandbox' => ['publishable_key' => self::PK_TEST, 'secret_key' => self::SK_TEST],
                'production' => ['publishable_key' => self::PK_LIVE, 'secret_key' => self::SK_LIVE],
            ],
        ]);

        $this->runCleanupMigration();

        $linkage = $linkage->fresh();

        $this->assertSame(PaymentProvider::MODE_SANDBOX, $linkage->payment_mode);
        $this->assertArrayNotHasKey('payment_mode', $linkage->configuration);
        $this->assertSame(self::SK_TEST, $linkage->secretKey());
    }

    /**
     * A malformed stored value must not reach the column verbatim.
     */
    #[Test]
    public function the_cleanup_migration_normalizes_what_it_copies(): void
    {
        $linkage = PartnerPaymentProvider::create([
            'partner_id' => $this->host->id,
            'payment_provider_id' => $this->stripe->id,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'payment_mode' => '  PRODUCTION  ',
                'production' => ['publishable_key' => self::PK_LIVE, 'secret_key' => self::SK_LIVE],
            ],
        ]);

        $this->runCleanupMigration();

        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $linkage->fresh()->payment_mode);
    }

    /**
     * A row with no inline copy is left exactly as it is.
     */
    #[Test]
    public function the_cleanup_migration_leaves_already_clean_rows_alone(): void
    {
        $linkage = $this->saveConfig(PaymentProvider::MODE_PRODUCTION);

        $this->runCleanupMigration();

        $linkage = $linkage->fresh();

        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $linkage->payment_mode);
        $this->assertSame(self::SK_LIVE, $linkage->secretKey());
    }
}
