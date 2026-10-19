<?php

namespace Tests\Unit\Profile;

use App\Models\Borrower\BorrowerChangeRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * An SSN in a change request - the number on file and the one asked for - is
 * stored encrypted and read back as the digits.
 */
#[CoversClass(BorrowerChangeRequest::class)]
class BorrowerChangeRequestSsnEncryptionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private function create(array $attributes): BorrowerChangeRequest
    {
        $borrower = $this->makeBorrower($this->makeCertifiedPartner());

        return BorrowerChangeRequest::create($attributes + [
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
            'status' => BorrowerChangeRequest::STATUS_PENDING,
        ]);
    }

    private function raw(BorrowerChangeRequest $request, string $column): ?string
    {
        return DB::table('borrower_change_requests')->where('id', $request->id)->value($column);
    }

    #[Test]
    public function an_ssn_is_sealed_even_when_its_value_is_filled_before_the_field_name(): void
    {
        $request = $this->create(['new_value' => '234567890', 'old_value' => '123456789', 'field_name' => 'ssn']);

        $this->assertStringNotContainsString('7890', $this->raw($request, 'new_value'));
        $this->assertStringNotContainsString('6789', $this->raw($request, 'old_value'));
        $this->assertSame('234567890', $request->fresh()->new_value);
        $this->assertSame('123456789', $request->fresh()->old_value);
    }

    #[Test]
    public function writing_the_decision_does_not_seal_the_value_twice(): void
    {
        $request = $this->create(['field_name' => 'ssn', 'new_value' => '234567890']);

        $request->update(['status' => BorrowerChangeRequest::STATUS_APPROVED]);

        $this->assertSame('234567890', $request->fresh()->new_value);
    }

    #[Test]
    public function a_request_filed_before_encryption_still_reads_back(): void
    {
        $request = $this->create(['field_name' => 'business_name', 'new_value' => 'x']);
        DB::table('borrower_change_requests')->where('id', $request->id)->update(['field_name' => 'last_4_ssn', 'new_value' => '6789']);

        $this->assertSame('6789', BorrowerChangeRequest::find($request->id)->new_value);
    }

    #[Test]
    public function any_other_field_is_stored_as_it_is(): void
    {
        $request = $this->create(['field_name' => 'business_name', 'old_value' => 'Old Co', 'new_value' => 'New Co']);

        $this->assertSame('New Co', $this->raw($request, 'new_value'));
        $this->assertSame('Old Co', $request->fresh()->old_value);
    }
}
