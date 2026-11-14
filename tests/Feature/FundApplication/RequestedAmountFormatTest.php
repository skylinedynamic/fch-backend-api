<?php

namespace Tests\Feature\FundApplication;

use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-022: "5e4" passed the requested amount's number check on the
 * funding request step. Each form that takes a requested amount - the
 * partner's (QA's), the client's, and the client's funding request - now
 * refuses it, and still answers "abc", "-100" and "" as before.
 *
 * Only the requested_amount error is looked at: the other fields are left out.
 */
class RequestedAmountFormatTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $partnerAdmin;
    private User $borrowerUser;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->borrowerUser = $this->makeBorrowerUser($this->partner);
    }

    /** @return iterable<string, array{string, string}> */
    public static function forms(): iterable
    {
        yield 'partner funding request step (QA)' => ['partner', '/api/v1/common/funding-application/funding-request'];
        yield 'client funding request step' => ['borrower', '/api/v1/borrower/funding-application/funding-request'];
        yield 'client funding request' => ['borrower', '/api/v1/borrower/funding/requests'];
    }

    private function amountError(string $as, string $url, mixed $amount): ?string
    {
        $this->actingAsUser($as === 'partner' ? $this->partnerAdmin : $this->borrowerUser);
        $errors = $this->postJson($url, ['requested_amount' => $amount])->json('errors') ?? [];
        $error = $errors['requested_amount'] ?? null;

        return is_array($error) ? ($error[0] ?? null) : $error;
    }

    #[Test]
    #[DataProvider('forms')]
    public function scientific_notation_is_refused(string $as, string $url): void
    {
        $this->assertSame('Enter the amount as a plain number, e.g. 50000 or 50000.50.', $this->amountError($as, $url, '5e4'));
    }

    #[Test]
    #[DataProvider('forms')]
    public function the_other_bad_amounts_keep_their_messages(string $as, string $url): void
    {
        $this->assertSame(__('borrower_validation.requested_amount_numeric'), $this->amountError($as, $url, 'abc'));
        $this->assertSame(__('borrower_validation.requested_amount_min'), $this->amountError($as, $url, '-100'));
        $this->assertNotNull($this->amountError($as, $url, ''));
    }

    #[Test]
    #[DataProvider('forms')]
    public function a_plain_amount_has_no_amount_error(string $as, string $url): void
    {
        $this->assertNull($this->amountError($as, $url, '50000'));
        $this->assertNull($this->amountError($as, $url, 50000.5));
    }
}
