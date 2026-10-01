<?php

namespace Tests\Unit\CreditAuthorization;

use App\Http\Requests\Api\V1\Borrower\Profile\RequestCreditProfileUpdateRequest;
use App\Http\Requests\Api\V1\Borrower\Profile\StoreCreditProfileRequestRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The server's own refusal to pull credit without consent.
 *
 * A disabled button is not evidence. What has to be demonstrable after the fact
 * is that the API itself would not accept a request with either box unticked -
 * otherwise "the client agreed" rests on the browser having behaved.
 *
 * Both client-initiated paths are covered: the first request and every later
 * request to update the profile, because the boxes are ticked again each time.
 */
class ConsentRequiredRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function clientConsentPaths(): iterable
    {
        yield 'first request' => [StoreCreditProfileRequestRequest::class];
        yield 'update request' => [RequestCreditProfileUpdateRequest::class];
    }

    private function consentRules(string $requestClass): array
    {
        $rules = (new $requestClass())->rules();

        return [
            'credit_authorization_accepted' => $rules['credit_authorization_accepted'],
            'e_consent_accepted' => $rules['e_consent_accepted'],
        ];
    }

    #[Test]
    #[DataProvider('clientConsentPaths')]
    public function both_boxes_ticked_is_accepted(string $requestClass): void
    {
        $validator = Validator::make([
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
        ], $this->consentRules($requestClass));

        $this->assertFalse($validator->fails());
    }

    #[Test]
    #[DataProvider('clientConsentPaths')]
    public function an_unticked_credit_authorization_is_refused(string $requestClass): void
    {
        $validator = Validator::make([
            'credit_authorization_accepted' => false,
            'e_consent_accepted' => true,
        ], $this->consentRules($requestClass));

        $this->assertTrue($validator->fails());
    }

    #[Test]
    #[DataProvider('clientConsentPaths')]
    public function an_unticked_electronic_consent_is_refused(string $requestClass): void
    {
        $validator = Validator::make([
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => false,
        ], $this->consentRules($requestClass));

        $this->assertTrue($validator->fails());
    }

    #[Test]
    #[DataProvider('clientConsentPaths')]
    public function omitting_them_entirely_is_refused(string $requestClass): void
    {
        // The shape an older client build would post, and the one that has to
        // fail rather than quietly pull credit with no record behind it.
        $validator = Validator::make([], $this->consentRules($requestClass));

        $this->assertTrue($validator->fails());
    }
}
