<?php

namespace Tests\Feature\CreditBuilder;

use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerCapitalAccessController;
use App\Http\Controllers\Api\V1\FCH\Common\CreditBuilder\CapitalAccessController;
use App\Http\Controllers\Api\V1\FCH\Common\CreditBuilder\VerificationCenterController;
use App\Http\Middleware\HostOnly;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The credit builder screens the partner portal shows the host only (behind
 * HostRoute): the capital access catalogue (/common/capital-accesses*), the
 * credit-account picker it uses and the Verification Center
 * (/common/credit-builder/verification-center-*). Plus a client's capital
 * access view (/common/borrowers/{id}/capital-access-*), which every partner
 * has for its own clients.
 */
#[CoversClass(CapitalAccessController::class)]
#[CoversClass(VerificationCenterController::class)]
#[CoversClass(BorrowerCapitalAccessController::class)]
#[CoversClass(HostOnly::class)]
class HostCreditBuilderApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $hostAdmin;
    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
    }

    private function capitalAccess(array $attributes = []): int
    {
        return DB::table('capital_accesses')->insertGetId(array_merge([
            'name' => 'Starter Stack',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    public static function hostOnlyRoutes(): array
    {
        return [
            'catalogue list' => ['POST', '/api/v1/common/capital-accesses/fetch-all'],
            'catalogue create' => ['POST', '/api/v1/common/capital-accesses'],
            'catalogue item' => ['GET', '/api/v1/common/capital-accesses/1'],
            'catalogue update' => ['PUT', '/api/v1/common/capital-accesses/1'],
            'catalogue status' => ['PATCH', '/api/v1/common/capital-accesses/1/status'],
            'catalogue delete' => ['DELETE', '/api/v1/common/capital-accesses/1'],
            'credit account picker' => ['POST', '/api/v1/common/credit-builder/credit-accounts/all'],
            'verification queue' => ['POST', '/api/v1/common/credit-builder/verification-center-fetch-all'],
            'verification by client' => ['POST', '/api/v1/common/credit-builder/verification-center-borrower-requests'],
            'verification decision' => ['POST', '/api/v1/common/credit-builder/verification-center-status-update'],
        ];
    }

    /**
     * Defect: these sit under /common with no host check, while the portal
     * shows them to the host only - so any partner could rewrite or delete the
     * platform's capital access catalogue and approve or reject other
     * partners' clients' credit builder tasks.
     */
    #[Test]
    #[DataProvider('hostOnlyRoutes')]
    public function a_non_host_partner_is_turned_away(string $method, string $url): void
    {
        $this->capitalAccess(['id' => 1]);

        foreach ([$this->partnerAdmin, $this->makeStaffUser($this->partner), $this->makePartnerAdmin($this->makeCertifiedPartner())] as $outsider) {
            $this->actingAsUser($outsider);
            $this->json($method, $url)->assertForbidden();
        }

        $this->assertDatabaseHas('capital_accesses', ['id' => 1, 'deleted_at' => null]);
    }

    #[Test]
    public function the_host_lists_opens_retires_and_deletes_catalogue_entries(): void
    {
        $id = $this->capitalAccess();
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/capital-accesses/fetch-all')->assertOk();
        $this->getJson("/api/v1/common/capital-accesses/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->patchJson("/api/v1/common/capital-accesses/{$id}/status", ['status' => 'Inactive'])->assertOk();
        $this->assertSame('Inactive', DB::table('capital_accesses')->where('id', $id)->value('status'));

        $this->deleteJson("/api/v1/common/capital-accesses/{$id}")->assertOk();
        $this->assertSoftDeleted('capital_accesses', ['id' => $id]);
    }

    #[Test]
    public function a_new_catalogue_entry_needs_its_ranges_benefits_and_checklist(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/capital-accesses', ['target_exposure_min' => 50, 'target_exposure_max' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'sub_title', 'target_exposure_max', 'est_timeline_min', 'what_you_will_get', 'pre_flight_checklist']);
    }

    /**
     * A strategy's and each step's minimum FICO is compared with the client's
     * FICO. Create took any integer from 0 and update 300-850, and the per-step
     * minimum took any integer at all - so the same field had three answers.
     */
    #[Test]
    public function a_capital_access_fico_requirement_must_be_a_fico_score(): void
    {
        $id = $this->capitalAccess();
        $this->actingAsUser($this->hostAdmin);
        $message = __('common.fico_score_invalid', ['min' => 300, 'max' => 850]);
        $payload = ['minimum_fico_requirement' => 0, 'credit_accounts' => [['minimum_fico_requirement' => 900]]];

        $this->postJson('/api/v1/common/capital-accesses', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['minimum_fico_requirement' => $message, 'credit_accounts.0.minimum_fico_requirement' => $message]);
        $this->putJson("/api/v1/common/capital-accesses/{$id}", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['minimum_fico_requirement' => $message, 'credit_accounts.0.minimum_fico_requirement' => $message]);
    }

    #[Test]
    public function a_stored_fico_requirement_sent_back_as_a_decimal_passes_its_own_check(): void
    {
        // minimum_fico_requirement is a decimal column, so an edit form echoes
        // "700.00" back; that is still the whole number 700. The rest of the
        // form is missing, so the request still fails - but not on this field.
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/capital-accesses', ['minimum_fico_requirement' => '700.00', 'credit_accounts' => [['minimum_fico_requirement' => 650]]])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors(['minimum_fico_requirement', 'credit_accounts.0.minimum_fico_requirement']);
    }

    #[Test]
    public function the_host_rejects_a_clients_task_verification_with_a_note(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $requestId = DB::table('borrower_task_verification_requests')->insertGetId([
            'borrower_id' => $borrower->id,
            'partner_id' => $this->partner->id,
            'status' => BorrowerTaskVerificationRequest::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/credit-builder/verification-center-status-update', [
            'request_ids' => [$requestId],
            'status' => BorrowerTaskVerificationRequest::REJECTED,
        ])->assertStatus(422)->assertJsonValidationErrors(['note']);
    }

    /**
     * The queue groups with MySQL's DATE_FORMAT and CONCAT, which the suite's
     * SQLite could not run (O21) - so the screen the host works the Verification
     * Center from had no test at all.
     */
    #[Test]
    public function the_host_sees_the_verification_queue(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        DB::table('borrower_task_verification_requests')->insert([
            'borrower_id' => $borrower->id,
            'partner_id' => $this->partner->id,
            'status' => BorrowerTaskVerificationRequest::PENDING,
            'created_at' => '2026-06-05 14:07:09',
            'updated_at' => '2026-06-05 14:07:09',
        ]);
        $this->actingAsUser($this->hostAdmin);

        $response = $this->postJson('/api/v1/common/credit-builder/verification-center-fetch-all')->assertOk();

        $this->assertStringContainsString((string) $borrower->id, $response->getContent());
    }

    #[Test]
    public function a_partner_opens_its_own_clients_capital_access_view(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/borrowers/{$borrower->id}/capital-access-overview")->assertOk();
        $this->getJson("/api/v1/common/borrowers/{$borrower->id}/capital-access-strategies")->assertOk();
    }

    /**
     * Defect: the overview's partner check compared user_type with 'partner'
     * (real values are capitalised), so it never ran - any partner could see
     * another partner's client's capital access standing and probabilities.
     */
    #[Test]
    public function a_partner_cannot_open_another_partners_clients_capital_access_view(): void
    {
        $theirs = $this->makeBorrower($this->makeCertifiedPartner());
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/borrowers/{$theirs->id}/capital-access-overview")->assertNotFound();
        $this->getJson("/api/v1/common/borrowers/{$theirs->id}/capital-access-strategies")->assertNotFound();
    }

    /**
     * Defect: the strategies view required the client's partner to be the
     * caller's own, which shut the host out of every partner's clients.
     */
    #[Test]
    public function the_host_opens_any_partners_clients_capital_access_view(): void
    {
        $borrower = $this->makeBorrower($this->partner);
        $this->actingAsUser($this->hostAdmin);

        $this->getJson("/api/v1/common/borrowers/{$borrower->id}/capital-access-overview")->assertOk();
        $this->getJson("/api/v1/common/borrowers/{$borrower->id}/capital-access-strategies")->assertOk();
    }
}
