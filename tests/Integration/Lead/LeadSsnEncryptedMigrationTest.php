<?php

namespace Tests\Integration\Lead;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * leads.ssn_last_4 (four digits, plain text) becomes leads.ssn_encrypted: the
 * full SSN, encrypted, as borrowers already keep it. The last four already on
 * file move across encrypted, so nothing a partner entered is lost.
 */
class LeadSsnEncryptedMigrationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_19_120000_encrypt_ssn_on_leads_table.php');
    }

    #[Test]
    public function a_lead_keeps_its_ssn_encrypted_only(): void
    {
        $this->assertTrue(Schema::hasColumn('leads', 'ssn_encrypted'));
        $this->assertFalse(Schema::hasColumn('leads', 'ssn_last_4'));
    }

    #[Test]
    public function the_last_four_on_file_move_across_encrypted_and_back_again(): void
    {
        $migration = $this->migration();
        $migration->down();

        $partner = $this->makeCertifiedPartner();
        $withSsn = DB::table('leads')->insertGetId(['partner_id' => $partner->id, 'company_name' => 'A', 'ssn_last_4' => '6789']);
        $withoutSsn = DB::table('leads')->insertGetId(['partner_id' => $partner->id, 'company_name' => 'B', 'ssn_last_4' => null]);

        $migration->up();

        $sealed = DB::table('leads')->where('id', $withSsn)->value('ssn_encrypted');
        $this->assertNotSame('6789', $sealed);
        $this->assertSame('6789', Crypt::decryptString($sealed));
        $this->assertNull(DB::table('leads')->where('id', $withoutSsn)->value('ssn_encrypted'));

        $migration->down();

        $this->assertSame('6789', DB::table('leads')->where('id', $withSsn)->value('ssn_last_4'));
        $this->assertFalse(Schema::hasColumn('leads', 'ssn_encrypted'));

        $migration->up();
    }
}
