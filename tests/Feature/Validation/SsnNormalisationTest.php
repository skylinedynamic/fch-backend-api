<?php

namespace Tests\Feature\Validation;

use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreOwnershipInfoRequest as BorrowerStoreOwnershipInfoRequest;
use App\Http\Requests\Api\V1\Borrower\MyApplication\CoBorrowerValidationRequest as BorrowerCoBorrowerValidationRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\CoBorrowerValidationRequest as PartnerCoBorrowerValidationRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\StoreOwnershipInfoRequest as PartnerStoreOwnershipInfoRequest;
use App\Http\Requests\Api\V1\Global\Website\EmbedForm\SubmitEmbedFormRequest;
use Illuminate\Foundation\Http\FormRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * That the requests which used to demand `digits:9` still hand on nine digits.
 *
 * They now validate with the shared Ssn rule, which also accepts the written
 * form XXX-XX-XXXX. These paths store the value as sent (encrypted on the
 * co-borrower and embed-form rows), so without reducing it first the same
 * SSN would be stored in two forms. A value carrying letters is left as sent,
 * so the rule still refuses it rather than seeing the letters stripped.
 */
class SsnNormalisationTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<FormRequest>}>
     */
    public static function requests(): iterable
    {
        yield 'borrower ownership info' => [BorrowerStoreOwnershipInfoRequest::class];
        yield 'borrower co-borrower' => [BorrowerCoBorrowerValidationRequest::class];
        yield 'partner ownership info' => [PartnerStoreOwnershipInfoRequest::class];
        yield 'partner co-borrower' => [PartnerCoBorrowerValidationRequest::class];
        yield 'embedded form' => [SubmitEmbedFormRequest::class];
    }

    #[Test]
    #[DataProvider('requests')]
    public function a_written_ssn_reaches_validation_as_its_nine_digits(string $requestClass): void
    {
        $this->assertSame('123456789', $this->prepared($requestClass, ['ssn' => '123-45-6789'])->input('ssn'));
    }

    #[Test]
    #[DataProvider('requests')]
    public function an_ssn_with_letters_is_left_for_the_rule_to_refuse(string $requestClass): void
    {
        $this->assertSame('123-45-6789abc', $this->prepared($requestClass, ['ssn' => '123-45-6789abc'])->input('ssn'));
    }

    #[Test]
    public function each_embedded_form_co_borrower_ssn_is_reduced_too(): void
    {
        $request = $this->prepared(SubmitEmbedFormRequest::class, [
            'co_borrowers' => [
                ['first_name' => 'Ann', 'ssn' => '123-45-6789'],
                ['first_name' => 'Bob', 'ssn' => '987-65-4321x'],
                ['first_name' => 'Cy'],
            ],
        ]);

        $this->assertSame('123456789', $request->input('co_borrowers.0.ssn'));
        $this->assertSame('987-65-4321x', $request->input('co_borrowers.1.ssn'));
        $this->assertSame('Ann', $request->input('co_borrowers.0.first_name'));
        $this->assertFalse(array_key_exists('ssn', $request->input('co_borrowers.2')));
    }

    /**
     * @param class-string<FormRequest> $requestClass
     */
    private function prepared(string $requestClass, array $input): FormRequest
    {
        $request = $requestClass::create('/ssn', 'POST', $input);
        $request->setContainer($this->app);

        $prepare = new ReflectionMethod($request, 'prepareForValidation');
        $prepare->invoke($request);

        return $request;
    }
}
