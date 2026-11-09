<?php

namespace Tests\Unit\Funding;

use App\Http\Requests\Api\V1\Global\General\FetchFundingSequenceOptionsRequest;
use App\Http\Resources\Global\General\FundingSequenceOptionResource;
use App\Models\Funding\FundingSequencing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /common/funding-sequences: what it takes (a funding type) and what one
 * option looks like (id and name only - not the model's appended engine
 * status, which runs several queries per sequence).
 */
#[CoversClass(FetchFundingSequenceOptionsRequest::class)]
#[CoversClass(FundingSequenceOptionResource::class)]
class FundingSequenceOptionsShapeTest extends TestCase
{
    #[Test]
    public function an_option_is_its_id_and_name_only(): void
    {
        $sequence = (new FundingSequencing)->newFromBuilder([
            'id' => 6, 'name' => 'Term Loans', 'status' => 'Active', 'description' => 'Not sent',
        ]);

        $this->assertSame(['id' => 6, 'name' => 'Term Loans'], (new FundingSequenceOptionResource($sequence))->toArray(new Request));
    }

    #[Test]
    public function it_takes_a_funding_type(): void
    {
        $request = new FetchFundingSequenceOptionsRequest;
        $this->assertTrue($request->authorize());

        $valid = fn (array $query) => Validator::make($query, $request->rules())->passes();

        $this->assertTrue($valid(['funding_type' => 'Term Loan']));
        $this->assertFalse($valid([]));
        $this->assertFalse($valid(['funding_type' => '']));
        $this->assertFalse($valid(['funding_type' => ['Term Loan']]));
        $this->assertFalse($valid(['funding_type' => str_repeat('x', 256)]));
    }
}
