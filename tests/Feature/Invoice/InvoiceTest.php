<?php

namespace Tests\Feature\Invoice;

use App\Mail\Invoice\InvoiceMail;
use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Functional HTTP tests for invoice endpoints:
 *  - Borrower: POST /api/v1/borrower/invoice, GET /api/v1/borrower/invoice/{id},
 *              GET /api/v1/borrower/invoice/{id}/download
 *  - Common/FCH: POST /api/v1/common/invoices/fetch-invoices, GET /api/v1/common/invoices/{id},
 *              GET /api/v1/common/invoices/{id}/download, POST /api/v1/common/invoices/{id}/send
 *
 * BUG FOUND (documented, not fixed — see final report): when a partner has no
 * `secondary_logo` AppConfiguration branding row (the default/unconfigured
 * state), resources/views/invoices/download.blade.php resolves
 * `public_path('')` — which is the public/ directory itself — sees it exists,
 * and calls file_get_contents() on it, throwing "Failed to open stream:
 * Permission denied" (or similar, OS-dependent). This breaks invoice
 * download/send for every partner that hasn't configured a secondary logo,
 * which is the default state for a freshly onboarded partner. Tests below
 * seed a (non-existent-file) secondary_logo value to route around the crash
 * and exercise the rest of the download/send flow, and one test explicitly
 * characterizes the crash itself.
 */
class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        Mail::fake();

        // InvoiceDetailResource::resolveBrandingData() -> getConfigPartner() falls
        // back to Partner::host() when the caller's own partner is not a host/WL
        // partner; without a host Partner row in the DB this throws. Every
        // invoice "show" / download / send test below goes through that
        // resource, so a host partner must always exist.
        $this->host = Partner::factory()->host()->create();
    }

    /**
     * Route around the secondary_logo crash described in the class docblock by
     * seeding a branding config value that resolves to a non-existent file
     * (so the view's file_exists() check is false and it falls back to using
     * the raw URL string, never calling file_get_contents on a directory).
     */
    protected function seedBrandingLogo(Partner $partner): void
    {
        AppConfiguration::set('secondary_logo', 'uploads/branding/does-not-exist.png', AppConfiguration::MODULE_BRANDING, $partner->id);
    }

    // ─────────────────────────────────────────────────────────────
    // Borrower-scoped endpoints
    // ─────────────────────────────────────────────────────────────

    public function test_borrower_invoice_list_only_returns_own_invoices(): void
    {
        $partner = Partner::factory()->create();
        $borrower = Borrower::factory()->create(['partner_id' => $partner->id]);
        $otherBorrower = Borrower::factory()->create(['partner_id' => $partner->id]);

        $ownInvoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create();
        Invoice::factory()->forBorrower($otherBorrower)->unpaid()->create();

        $user = User::factory()->borrower($borrower)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->postJson('/api/v1/borrower/invoice', []);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($ownInvoice->id, $ids);
        foreach ($ids as $id) {
            $this->assertSame($borrower->id, Invoice::find($id)->borrower_id);
        }
    }

    public function test_borrower_can_view_own_invoice_by_id(): void
    {
        $partner = Partner::factory()->create();
        $borrower = Borrower::factory()->create(['partner_id' => $partner->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create();

        $user = User::factory()->borrower($borrower)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->getJson("/api/v1/borrower/invoice/{$invoice->id}");

        $response->assertOk();
        $response->assertJson(['status' => true]);
        $this->assertSame($invoice->id, $response->json('data.id'));
    }

    public function test_borrower_cannot_view_another_borrowers_invoice(): void
    {
        $partner = Partner::factory()->create();
        $borrower = Borrower::factory()->create(['partner_id' => $partner->id]);
        $otherBorrower = Borrower::factory()->create(['partner_id' => $partner->id]);
        $otherInvoice = Invoice::factory()->forBorrower($otherBorrower)->unpaid()->create();

        $user = User::factory()->borrower($borrower)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->getJson("/api/v1/borrower/invoice/{$otherInvoice->id}");

        $response->assertStatus(404);
    }

    public function test_borrower_check_unpaid_invoice_returns_only_unpaid(): void
    {
        $partner = Partner::factory()->create();
        $borrower = Borrower::factory()->create(['partner_id' => $partner->id]);
        $unpaid = Invoice::factory()->forBorrower($borrower)->unpaid()->create();
        Invoice::factory()->forBorrower($borrower)->paid()->create();

        $user = User::factory()->borrower($borrower)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->getJson('/api/v1/borrower/invoice/check-unpaid-invoice');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($unpaid->id, $ids);
    }

    public function test_borrower_invoice_download_returns_base64_pdf(): void
    {
        $this->seedBrandingLogo($this->host);

        $partner = Partner::factory()->create();
        $borrower = Borrower::factory()->create(['partner_id' => $partner->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->paid()->create();

        $user = User::factory()->borrower($borrower)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->getJson("/api/v1/borrower/invoice/{$invoice->id}/download");

        $response->assertOk();
        $response->assertJson(['status' => true]);
        $response->assertJsonStructure(['data' => ['file', 'mime_type', 'file_name']]);
        $this->assertSame('application/pdf', $response->json('data.mime_type'));
    }

    /**
     * Characterizes the real bug described in the class docblock: without a
     * secondary_logo branding row, invoice download crashes.
     */
    public function test_borrower_invoice_download_fails_when_no_branding_logo_configured(): void
    {
        $partner = Partner::factory()->create();
        $borrower = Borrower::factory()->create(['partner_id' => $partner->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->paid()->create();

        $user = User::factory()->borrower($borrower)->create();
        $token = $user->createToken('test')->accessToken;

        // No branding logo seeded for $this->host: reproduces the public_path('')
        // directory-read crash.
        $response = $this->withToken($token)->getJson("/api/v1/borrower/invoice/{$invoice->id}/download");

        $response->assertStatus(404);
        $response->assertJson(['status' => false]);
    }

    public function test_borrower_invoice_endpoints_require_authentication(): void
    {
        $response = $this->postJson('/api/v1/borrower/invoice', []);

        $response->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────
    // Common/FCH-scoped endpoints (partner RBAC)
    // ─────────────────────────────────────────────────────────────

    public function test_non_host_partner_invoice_list_excludes_other_partners_invoices(): void
    {
        $myPartner = Partner::factory()->create();
        $otherPartner = Partner::factory()->create();

        $myBorrower = Borrower::factory()->create(['partner_id' => $myPartner->id]);
        $myInvoice = Invoice::factory()->forBorrower($myBorrower)->unpaid()->create();

        $otherBorrower = Borrower::factory()->create(['partner_id' => $otherPartner->id]);
        $otherInvoice = Invoice::factory()->forBorrower($otherBorrower)->unpaid()->create();

        $user = User::factory()->partner($myPartner)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->postJson('/api/v1/common/invoices/fetch-invoices', []);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($myInvoice->id, $ids);
        $this->assertNotContains($otherInvoice->id, $ids);
    }

    public function test_common_invoice_show_returns_invoice_for_owning_partner(): void
    {
        $partner = Partner::factory()->create();
        $borrower = Borrower::factory()->create(['partner_id' => $partner->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create();

        $user = User::factory()->partner($partner)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->getJson("/api/v1/common/invoices/{$invoice->id}");

        $response->assertOk();
        $this->assertSame($invoice->id, $response->json('data.id'));
    }

    public function test_common_invoice_download_returns_base64_pdf(): void
    {
        $this->seedBrandingLogo($this->host);

        $borrower = Borrower::factory()->create(['partner_id' => $this->host->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->paid()->create();

        $user = User::factory()->partner($this->host)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->getJson("/api/v1/common/invoices/{$invoice->id}/download");

        $response->assertOk();
        $response->assertJson(['status' => true]);
        $this->assertSame('application/pdf', $response->json('data.mime_type'));
    }

    public function test_common_invoice_send_emails_recipient(): void
    {
        $this->seedBrandingLogo($this->host);

        $borrower = Borrower::factory()->create(['partner_id' => $this->host->id]);
        $invoice = Invoice::factory()->forBorrower($borrower)->paid()->create();

        $user = User::factory()->partner($this->host)->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->withToken($token)->postJson("/api/v1/common/invoices/{$invoice->id}/send");

        $response->assertOk();
        $response->assertJson(['status' => true]);
        Mail::assertQueued(InvoiceMail::class);
    }
}
