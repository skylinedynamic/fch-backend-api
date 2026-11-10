<?php

namespace Tests\Feature\CreditProfile;

use App\Http\Controllers\Api\V1\FCH\Common\Borrower\BorrowerController;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-145: Client > Credit Profile > Personal & Address Info showed the
 * report's SSN as all nine digits. The credit profile now carries it masked
 * (***-**-6789) like every other SSN, the full number comes from the logged
 * reveal, and a save that sends the mask back keeps the number on file.
 */
#[CoversClass(BorrowerController::class)]
class CreditProfileSsnMaskingTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Borrower $borrower;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($host);
        $this->borrower = $this->makeBorrower($host);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/borrowers/save-credit-profile', [
            'borrower_id' => $this->borrower->id,
            'metrics' => ['fico_score' => 734],
            'personal_info' => ['name' => 'Report Name', 'ssn' => '123-45-6789'],
        ])->assertOk();
    }

    private function storedSsn(): ?string
    {
        return BorrowerCreditProfile::where('borrower_id', $this->borrower->id)->firstOrFail()->personal_info['ssn'] ?? null;
    }

    #[Test]
    public function the_credit_profile_reads_back_with_the_ssn_masked(): void
    {
        $response = $this->getJson("/api/v1/common/borrowers/get-credit-profile/{$this->borrower->id}")
            ->assertOk()
            ->assertJsonPath('data.personal_info.ssn', '***-**-6789')
            ->assertJsonPath('data.personal_info.name', 'Report Name');

        $this->assertStringNotContainsString('123-45', $response->getContent());
        $this->assertStringNotContainsString('12345', $response->getContent());
    }

    #[Test]
    public function saving_with_the_mask_sent_back_keeps_the_full_number_on_file(): void
    {
        $this->postJson('/api/v1/common/borrowers/save-credit-profile', [
            'borrower_id' => $this->borrower->id,
            'metrics' => ['fico_score' => 740],
            'personal_info' => ['name' => 'Report Name', 'ssn' => '***-**-6789'],
        ])->assertOk();

        $this->assertSame('123-45-6789', $this->storedSsn());
    }

    #[Test]
    public function a_new_number_typed_in_replaces_the_one_on_file(): void
    {
        $this->postJson('/api/v1/common/borrowers/save-credit-profile', [
            'borrower_id' => $this->borrower->id,
            // Every save carries a FICO score (BUG-074).
            'metrics' => ['fico_score' => 740],
            'personal_info' => ['ssn' => '987-65-4321'],
        ])->assertOk();

        $this->assertSame('987-65-4321', $this->storedSsn());
    }

    #[Test]
    public function the_full_number_is_revealed_on_request_and_the_reveal_is_logged(): void
    {
        $this->postJson("/api/v1/common/borrowers/{$this->borrower->id}/reveal", ['field' => 'credit_profile_ssn'])
            ->assertOk()
            ->assertJsonPath('data.value', '123-45-6789');

        $this->assertTrue(
            ActivityLog::where('subject_id', $this->borrower->id)->where('description', 'like', '%Credit Profile SSN%')->exists()
        );
    }
}
