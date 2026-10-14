<?php

namespace Tests\Feature\Funding;

use App\Models\Borrower\Borrower;
use App\Models\Funding\FundingSequencing;
use App\Models\Funding\LenderVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /borrower/funding evaluates every lender on every funding sequence for
 * the signed-in borrower. Two of those checks - "has any funding application"
 * and "has any uploaded document" - depend only on the borrower, yet ran as a
 * fresh EXISTS query per lender, per evaluation: ~3,300 identical queries and
 * ~12s per page load on the local data set. These pin the borrower facts to
 * one query each, and the sequence's engine status to one evaluation, without
 * changing the status the engine returns.
 */
class BorrowerFundingEngineStatusQueryTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;
    private FundingSequencing $sequence;

    protected function setUp(): void
    {
        parent::setUp();

        $partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner-' . uniqid() . '@example.com',
            'phone_number' => '(111) 111-1111',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $borrowerId = DB::table('borrowers')->insertGetId([
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'business_name' => 'Alpha Tech Solutions',
            'email' => 'borrower-' . uniqid() . '@example.com',
            'phone_number' => '(546) 456-4520',
            'partner_id' => $partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->borrower = Borrower::findOrFail($borrowerId);

        $sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Business Lines of Credit',
            'subtitle' => 'Test sequence',
            'amount_range' => '10000-250000',
            'status' => FundingSequencing::STATUS_ACTIVE,
            'require_completed_application' => true,
            'require_documents_uploaded' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->sequence = FundingSequencing::findOrFail($sequenceId);

        foreach (range(1, 5) as $i) {
            $lenderId = DB::table('lenders')->insertGetId([
                'name' => "Lender {$i}",
                'status' => 'Active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('lender_visibilities')->insert([
                'lender_id' => $lenderId,
                'funding_sequencing_id' => $sequenceId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function countQueries(string $table, callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = collect(DB::getQueryLog())
            // Quote style differs by driver (` on MySQL, " on SQLite).
            ->filter(fn ($q) => str_contains($q['query'], 'exists') && preg_match('/[`"]' . $table . '[`"]/', $q['query']))
            ->count();
        DB::disableQueryLog();

        return $count;
    }

    #[Test]
    public function borrower_application_and_document_checks_run_once_across_all_lenders(): void
    {
        $lenders = LenderVisibility::with('lender')->where('funding_sequencing_id', $this->sequence->id)->get();

        $evaluate = function () use ($lenders) {
            foreach ($lenders as $lender) {
                $lender->setRelation('fundingSequencing', $this->sequence);
                $this->assertSame(
                    FundingSequencing::ENGINE_STATUS_WARNING,
                    $lender->getEngineStatusForBorrower($this->borrower),
                    'no application and no documents still means WARNING'
                );
            }
        };

        $this->assertSame(1, $this->countQueries('funding_applications', $evaluate));
        $this->assertSame(0, $this->countQueries('funding_applications', $evaluate), 'second pass reuses the answer');

        $this->assertSame(0, $this->countQueries('borrower_documents', $evaluate));
    }

    #[Test]
    public function borrower_documents_check_runs_once_across_all_lenders(): void
    {
        $lenders = LenderVisibility::with('lender')->where('funding_sequencing_id', $this->sequence->id)->get();

        $count = $this->countQueries('borrower_documents', function () use ($lenders) {
            foreach ($lenders as $lender) {
                $lender->setRelation('fundingSequencing', $this->sequence);
                $lender->getEngineStatusForBorrower($this->borrower);
            }
        });

        $this->assertSame(1, $count);
    }

    #[Test]
    public function engine_status_is_evaluated_once_per_sequence_instance(): void
    {
        $user = new \App\Models\User\User();
        $user->forceFill(['id' => 999, 'user_type' => \App\Models\User\User::BORROWER]);
        $user->setRelation('borrower', $this->borrower);
        $this->actingAs($user);

        // No application/document requirement: the lenders pass, so the engine
        // goes on to the (query-heavy) readiness checks.
        $this->sequence->forceFill(['require_completed_application' => false, 'require_documents_uploaded' => false])->save();

        $first = $this->sequence->engine_status;

        DB::flushQueryLog();
        DB::enableQueryLog();
        $again = $this->sequence->engine_status;
        $label = $this->sequence->engine_status_label;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $again);
        $this->assertNotSame(FundingSequencing::ENGINE_STATUS_HIDDEN, $first);
        $this->assertNotSame('Unknown', $label);
        $this->assertSame(0, $queries, 'engine_status / engine_status_label reuse the first evaluation');
    }

    #[Test]
    public function borrower_facts_reflect_the_data_when_loaded(): void
    {
        DB::table('funding_applications')->insert([
            'borrower_id' => $this->borrower->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($this->borrower->hasAnyFundingApplication());
        $this->assertFalse($this->borrower->hasAnyDocument());
    }
}
