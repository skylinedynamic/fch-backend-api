<?php

namespace Tests\Integration\Lender;

use App\Models\Lender\Lender;
use Database\Seeders\LenderCodeSeeder;
use Database\Seeders\LenderProductCategorySeeder;
use Database\Seeders\LenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every lender has a Lender Code: "LND-", four random digits, then the
 * lender's own id ("LND-" . rand(4 digits) . id - lender 5: "LND-93245").
 * The id at the end keeps every code unique. The system makes it when the
 * lender is created - never typed in. Lenders saved before it existed are
 * given one (by the migration, and by LenderCodeSeeder).
 */
#[CoversClass(Lender::class)]
#[CoversClass(LenderCodeSeeder::class)]
class LenderCodeTest extends TestCase
{
    use RefreshDatabase;

    private function lender(array $attributes = []): Lender
    {
        return Lender::create(array_merge(['name' => 'Acme Capital', 'legal_name' => 'Acme Capital LLC', 'status' => Lender::STATUS_ACTIVE], $attributes));
    }

    /** Inserts lenders directly, as rows saved before the Lender Code existed. */
    private function rawLender(?string $code, array $attributes = []): int
    {
        return DB::table('lenders')->insertGetId(array_merge([
            'name' => 'Old Lender',
            'legal_name' => 'Old Lender Inc.',
            'status' => Lender::STATUS_ACTIVE,
            'lender_code' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    /** "LND-", four digits, then exactly this lender's id. */
    private function assertCodeFor(int $id, ?string $code): void
    {
        $this->assertMatchesRegularExpression('/^LND-\d{4}'.$id.'$/', (string) $code);
    }

    #[Test]
    public function a_new_lender_gets_lnd_four_random_digits_and_its_id(): void
    {
        $lender = $this->lender();

        $this->assertCodeFor($lender->id, $lender->lender_code);
        $this->assertSame($lender->lender_code, DB::table('lenders')->where('id', $lender->id)->value('lender_code'));
    }

    #[Test]
    public function the_code_is_made_of_the_digits_and_the_id_given(): void
    {
        $this->assertSame('LND-93245', Lender::lenderCodeFor(5, 9324));
        $this->assertSame('LND-0007112', Lender::lenderCodeFor(112, 7));
        $this->assertCodeFor(41, Lender::lenderCodeFor(41));
    }

    #[Test]
    public function lender_codes_are_unique(): void
    {
        $lenders = collect(range(1, 40))->map(fn ($i) => $this->lender(['name' => "Lender {$i}"]));

        $this->assertCount(40, $lenders->pluck('lender_code')->unique());
        $lenders->each(fn (Lender $lender) => $this->assertCodeFor($lender->id, $lender->lender_code));
    }

    #[Test]
    public function a_lender_code_cannot_be_chosen_through_mass_assignment(): void
    {
        $lender = $this->lender(['lender_code' => 'MY-OWN-ID']);

        $this->assertCodeFor($lender->id, $lender->lender_code);
        $this->assertNotContains('lender_code', $lender->getFillable());
    }

    #[Test]
    public function an_update_keeps_the_lender_code(): void
    {
        $lender = $this->lender();
        $code = $lender->lender_code;

        $lender->update(['name' => 'Acme Renamed']);

        $this->assertSame($code, $lender->fresh()->lender_code);
    }

    /** A lender already holding a code in the right format, for this id. */
    private function keptLender(): array
    {
        $id = $this->rawLender(null, ['name' => 'Kept']);
        $code = Lender::lenderCodeFor($id, 1234);
        DB::table('lenders')->where('id', $id)->update(['lender_code' => $code]);

        return [$id, $code];
    }

    #[Test]
    public function lenders_without_a_lender_code_are_given_one_and_others_keep_theirs(): void
    {
        [$kept, $keptCode] = $this->keptLender();
        $missing = $this->rawLender(null, ['name' => 'Missing']);
        $deleted = $this->rawLender(null, ['name' => 'Deleted', 'deleted_at' => now()]);

        $this->assertSame(2, Lender::fillMissingLenderCodes());

        $this->assertSame($keptCode, DB::table('lenders')->where('id', $kept)->value('lender_code'));
        $this->assertCodeFor($missing, DB::table('lenders')->where('id', $missing)->value('lender_code'));
        $this->assertCodeFor($deleted, DB::table('lenders')->where('id', $deleted)->value('lender_code'));
        $this->assertSame(0, Lender::fillMissingLenderCodes());
    }

    /**
     * Found 2026-11-11: a database that ran the first version of the migration
     * holds "LND-" + 4 digits (LND-5890) - no lender id at the end. Those are
     * replaced; a code already ending in its own id is kept.
     */
    #[Test]
    public function codes_in_the_old_format_or_for_another_lender_are_replaced(): void
    {
        [$kept, $keptCode] = $this->keptLender();
        $old = $this->rawLender('LND-5890', ['name' => 'Old format']);
        $other = $this->rawLender('LND-12349999', ['name' => 'Another id at the end']);
        $junk = $this->rawLender('ABC', ['name' => 'Not a code']);

        $this->assertSame(3, Lender::fillMissingLenderCodes());

        $this->assertSame($keptCode, DB::table('lenders')->where('id', $kept)->value('lender_code'));
        foreach ([$old, $other, $junk] as $id) {
            $this->assertCodeFor($id, DB::table('lenders')->where('id', $id)->value('lender_code'));
        }
        $this->assertSame(0, Lender::fillMissingLenderCodes());
    }

    #[Test]
    public function lenders_the_lender_seeder_creates_get_a_lender_code(): void
    {
        $this->seed(LenderProductCategorySeeder::class);

        $this->seed(LenderSeeder::class);

        $this->assertGreaterThan(1, Lender::count());
        $this->assertSame(0, Lender::whereNull('lender_code')->count());
        Lender::all()->each(fn (Lender $lender) => $this->assertCodeFor($lender->id, $lender->lender_code));
    }

    #[Test]
    public function the_lender_code_seeder_fixes_missing_and_old_codes_and_can_run_again(): void
    {
        [$kept, $keptCode] = $this->keptLender();
        $missing = $this->rawLender(null, ['name' => 'Saved Before']);
        $old = $this->rawLender('LND-5890', ['name' => 'Old format']);

        $this->seed(LenderCodeSeeder::class);
        $given = DB::table('lenders')->where('id', $missing)->value('lender_code');
        $fixed = DB::table('lenders')->where('id', $old)->value('lender_code');
        $this->seed(LenderCodeSeeder::class);

        $this->assertSame($keptCode, DB::table('lenders')->where('id', $kept)->value('lender_code'));
        $this->assertCodeFor($missing, $given);
        $this->assertCodeFor($old, $fixed);
        $this->assertSame($given, DB::table('lenders')->where('id', $missing)->value('lender_code'));
        $this->assertSame($fixed, DB::table('lenders')->where('id', $old)->value('lender_code'));
    }
}
