<?php

namespace Tests\Integration\Support;

use App\Models\Borrower\FundingApplication;
use App\Rules\VisibleToUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The "an id you may see" validation rule, checked against the signed-in
 * user's tenant scope.
 */
#[CoversClass(VisibleToUser::class)]
class VisibleToUserTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    #[Test]
    public function an_id_passes_only_when_the_signed_in_user_may_see_it(): void
    {
        $this->makeHostPartner();
        $partner = $this->makeWhiteLabelPartner();
        $mine = FundingApplication::forceCreate(['borrower_id' => $this->makeBorrower($partner)->id]);
        $theirs = FundingApplication::forceCreate(['borrower_id' => $this->makeBorrower($this->makeCertifiedPartner())->id]);
        $this->actingAsUser($this->makePartnerAdmin($partner));

        $rule = ['id' => [new VisibleToUser(FundingApplication::class)]];

        $this->assertTrue(Validator::make(['id' => $mine->id], $rule)->passes());
        $this->assertTrue(Validator::make(['id' => $theirs->id], $rule)->fails());
        $this->assertTrue(Validator::make(['id' => 999999], $rule)->fails());
        $this->assertTrue(Validator::make(['id' => [$mine->id]], $rule)->fails(), 'an array is not an id');
    }
}
