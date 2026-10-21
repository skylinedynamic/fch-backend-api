<?php

namespace Tests\Unit\Partner;

use App\Http\Resources\FCH\Partner\PartnerOptionResource;
use App\Models\Partner\Partner;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A partner in a filter dropdown: its id, name and company - nothing else. */
#[CoversClass(PartnerOptionResource::class)]
class PartnerOptionResourceTest extends TestCase
{
    #[Test]
    public function it_is_only_the_id_name_and_company(): void
    {
        $partner = new Partner([
            'full_name' => 'Wil Label',
            'company_name' => 'WLP Co',
            'email' => 'wil@example.test',
            'phone_number' => '(555) 123-4567',
            'is_host' => false,
        ]);
        $partner->id = 7;

        $this->assertSame(
            ['id' => 7, 'full_name' => 'Wil Label', 'company_name' => 'WLP Co'],
            (new PartnerOptionResource($partner))->toArray(new Request())
        );
    }

    #[Test]
    public function a_missing_company_stays_null(): void
    {
        $partner = new Partner(['full_name' => 'No Co']);
        $partner->id = 8;

        $this->assertNull((new PartnerOptionResource($partner))->toArray(new Request())['company_name']);
    }
}
