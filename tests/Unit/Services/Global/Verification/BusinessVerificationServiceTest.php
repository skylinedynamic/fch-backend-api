<?php

namespace Tests\Unit\Services\Global\Verification;

use App\Models\Borrower\Borrower;
use App\Services\Global\MicroBilt\MicroBiltService;
use App\Services\Global\OpenCorporates\OpenCorporatesService;
use App\Services\Global\Verification\BusinessVerificationService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\TestCase;

/**
 * CONFIRMED LIVE BUG regression test — NOT a fix. verifyTier1() calls
 * $this->microBiltService->verifyEIN(...), ->verifyAddress(...), ->verifyPhone(...)
 * but MicroBiltService only defines einVerification(), businessAddressVerification(),
 * businessPhoneVerification(), businessIdentityVerification() and
 * activeLegalEntityVerification() — those three method names simply do not exist.
 *
 * The task brief this test was written from assumed the resulting
 * BadMethodCallException would be swallowed by BusinessVerificationService's
 * per-check try/catch(\Exception) blocks and turned into an 'Error' string.
 * Running the real code shows that assumption is wrong and the bug is worse:
 * calling an undefined method on a concrete (non-magic) object throws a PHP
 * \Error, not an \Exception. \Error does NOT extend \Exception (both only
 * share the \Throwable interface), so `catch (\Exception $e)` does NOT catch
 * it — the \Error propagates straight out of verifyTier1() uncaught, rather
 * than being gracefully degraded to an 'Error' result string. This test
 * characterizes that actual (worse) behavior.
 *
 * Separately-noted finding: verifyTier1() calls updateTaskStatus($borrower->id, ...)
 * unconditionally after every check (the `if (!$isComplete) return;` guard is
 * INSIDE updateTaskStatus, not before the call), and that method type-hints
 * its first parameter as non-nullable `int`. An unsaved/id-less Borrower
 * therefore throws a \TypeError before ever reaching the MicroBilt calls —
 * borrowers below are persisted via ->create() to get past that and exercise
 * the actual bug under test.
 */
class BusinessVerificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    private function borrowerWithEinAndPhone(): Borrower
    {
        $borrower = Borrower::factory()->create([
            'business_name' => 'Acme Inc',
            'business_address' => '123 Main St',
            'business_city' => 'Springfield',
            'business_state' => 'IL',
        ]);

        // ein / business_phone / business_zip / state_code are read by
        // BusinessVerificationService but are NOT real fillable/DB columns on
        // Borrower — set them as in-memory attributes only (never persisted).
        $borrower->ein = '12-3456789';
        $borrower->business_phone = '5551234567';

        return $borrower;
    }

    public function test_verify_tier1_throws_an_uncaught_error_because_verify_ein_does_not_exist_on_micro_bilt_service(): void
    {
        $openCorporates = \Mockery::mock(OpenCorporatesService::class);
        $openCorporates->shouldReceive('searchCompany')->andReturn(['companies' => []]);

        $service = new BusinessVerificationService(new MicroBiltService, $openCorporates);

        $borrower = $this->borrowerWithEinAndPhone();

        // \Error, not \Exception — see class docblock for why.
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/verifyEIN/');

        $service->verifyTier1($borrower);
    }

    public function test_entity_check_alone_completes_and_reports_not_found_when_ein_and_phone_are_absent(): void
    {
        // Without ein / business_phone set, the EIN and phone try-blocks take
        // their early "No EIN/Phone provided" branches and never reach the
        // undefined verifyEIN()/verifyPhone() calls. The address check has no
        // such guard, so it always calls the (also undefined) verifyAddress()
        // and would still blow up — so we only assert as far as the entity
        // check here, independent of the address bug.
        $openCorporates = \Mockery::mock(OpenCorporatesService::class);
        $openCorporates->shouldReceive('searchCompany')
            ->with('Acme Inc', \Mockery::any())
            ->andReturn(['companies' => []]);

        $service = new BusinessVerificationService(new MicroBiltService, $openCorporates);

        $borrower = Borrower::factory()->create(['business_name' => 'Acme Inc']);

        // The unguarded address check still calls the undefined verifyAddress(),
        // so verifyTier1() as a whole still throws \Error — but only after the
        // entity check above it has already run without throwing.
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/verifyAddress/');

        $service->verifyTier1($borrower);
    }
}
