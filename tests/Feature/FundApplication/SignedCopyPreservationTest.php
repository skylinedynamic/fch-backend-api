<?php

namespace Tests\Feature\FundApplication;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Application\FundingApplicationDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every signing keeps its own copy of the application exactly as signed, with
 * the certificate for it, numbered. Editing and signing again adds a new
 * version; it never overwrites the one signed before.
 */
class SignedCopyPreservationTest extends TestCase
{
    use RefreshDatabase;

    private FundingApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);
        $borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Ava',
            'last_name' => 'Ortiz',
            'email' => 'ava@example.test',
        ]);

        $id = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-0005151',
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'first_name' => 'Ava',
            'last_name' => 'Ortiz',
            'business_name' => 'Pearl Black',
            'status' => FundingApplication::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->application = FundingApplication::findOrFail($id);
    }

    private function sign(): array
    {
        $signature = ['signature_name' => 'Ava Ortiz', 'signed_at' => now()->utc()->toIso8601String()];
        app(FundingApplicationDocumentService::class)->generateAndLockFinalDocuments($this->application->fresh(), $signature);

        return $this->application->fresh()->signature_data;
    }

    #[Test]
    public function signing_keeps_the_application_as_signed_and_its_certificate(): void
    {
        $signature = $this->sign();

        $this->assertSame(1, $signature['application_version']);
        Storage::disk('public')->assertExists($signature['signed_application_path']);
        Storage::disk('public')->assertExists($signature['certificate_path']);
        $this->assertSame($signature['sha256'], hash('sha256', Storage::disk('public')->get($signature['signed_application_path'])));
    }

    #[Test]
    public function signing_again_after_an_edit_adds_a_version_and_leaves_the_first_alone(): void
    {
        $first = $this->sign();
        $firstCopy = Storage::disk('public')->get($first['signed_application_path']);

        DB::table('funding_applications')->where('id', $this->application->id)->update(['business_name' => 'Pearl Black LLC']);
        $second = $this->sign();

        $this->assertSame(2, $second['application_version']);
        $this->assertNotSame($first['signed_application_path'], $second['signed_application_path']);
        $this->assertNotSame($first['certificate_path'], $second['certificate_path']);
        $this->assertNotSame($first['sha256'], $second['sha256']);

        // The first signed copy is exactly as it was.
        $this->assertSame($firstCopy, Storage::disk('public')->get($first['signed_application_path']));
        Storage::disk('public')->assertExists($first['certificate_path']);
    }

    #[Test]
    public function the_signed_copy_records_who_the_application_was_for_at_signing(): void
    {
        // Frozen with the signature, so the certificate does not follow later edits.
        $signature = $this->sign();

        $this->assertSame('Ava Ortiz', $signature['borrower_name']);
        $this->assertSame('Pearl Black', $signature['business_name']);
        $this->assertSame(FundingApplication::PENDING, $signature['status_at_signing']);
    }
}
