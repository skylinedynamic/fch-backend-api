<?php

namespace Tests\Feature\Validation;

use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreBusinessInfoRequest as BorrowerStoreBusinessInfoRequest;
use App\Http\Requests\Api\V1\Borrower\FundApplication\StoreOwnershipInfoRequest as BorrowerStoreOwnershipInfoRequest;
use App\Http\Requests\Api\V1\Borrower\MyApplication\CoBorrowerValidationRequest as BorrowerCoBorrowerValidationRequest;
use App\Http\Requests\Api\V1\Borrower\PreQualification\PersonalInfoRequest;
use App\Http\Requests\Api\V1\Borrower\Profile\StoreChangeRequest;
use App\Http\Requests\Api\V1\Borrower\Profile\StoreCreditProfileRequestRequest;
use App\Http\Requests\Api\V1\Borrower\Profile\UpdateBusinessInfoRequest;
use App\Http\Requests\Api\V1\Borrower\Profile\UpdatePersonalProfileRequest;
use App\Http\Requests\Api\V1\FCH\Admin\CMS\UpdateSiteSettingsRequest;
use App\Http\Requests\Api\V1\FCH\Admin\DemoRequest\DemoRequestStoreOrUpdateRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\StoreLenderRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\UpdateLenderRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\StorePartnerRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerRequest;
use App\Http\Requests\Api\V1\FCH\Common\AuthProfile\UpdateProfileRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\StoreBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Borrower\UpdateBorrowerRequest;
use App\Http\Requests\Api\V1\FCH\Common\Contact\StoreContactRequest;
use App\Http\Requests\Api\V1\FCH\Common\Contact\UpdateContactRequest;
use App\Http\Requests\Api\V1\FCH\Common\Employee\StoreEmployeeRequest;
use App\Http\Requests\Api\V1\FCH\Common\Employee\UpdateEmployeeRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\StoreLeadRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\UpdateLeadContactInfoRequest;
use App\Http\Requests\Api\V1\FCH\Common\Lead\UpdateLeadRequest;
use App\Http\Requests\Api\V1\FCH\Common\Setting\SendTestSmsRequest;
use App\Http\Requests\Api\V1\FCH\Common\User\StoreUserRequest;
use App\Http\Requests\Api\V1\FCH\Common\User\UpdateUserRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\CoBorrowerValidationRequest as PartnerCoBorrowerValidationRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\StoreBusinessInfoRequest as PartnerStoreBusinessInfoRequest;
use App\Http\Requests\Api\V1\FCH\Partner\FundApplication\StoreOwnershipInfoRequest as PartnerStoreOwnershipInfoRequest;
use App\Http\Requests\Api\V1\Global\Website\EmbedForm\SubmitEmbedFormRequest;
use App\Http\Requests\Api\V1\Global\Website\Lead\StoreLeadRequest as WebsiteStoreLeadRequest;
use App\Http\Requests\Api\V1\Global\Website\PreQualification\StorePreQualificationRequest;
use App\Models\User\User;
use App\Rules\BusinessEin;
use App\Rules\DunsNumber;
use App\Rules\Ssn;
use App\Rules\UsPhoneNumber;
use App\Rules\UsPostalCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * That every request taking a zip code, phone number, SSN, EIN or DUNS number
 * validates it with the one shared rule for that format.
 *
 * Each format has a single definition in app/Rules (5-digit ZIP, NANP phone,
 * SSA-issuable SSN, 9-digit EIN and DUNS), but most requests carried their own
 * `max:20` / `digits:9` / loose regex instead, so the same field was accepted
 * on one screen and refused on another. What each rule accepts is covered by
 * its own unit test; this covers that the requests actually use it.
 *
 * A Feature test because several rules() methods read the signed-in user, a
 * route parameter or the database.
 */
class IdentifierFormatRuleWiringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{class-string<FormRequest>, string, class-string, array<string, mixed>}>
     */
    public static function fields(): iterable
    {
        $wired = [
            UsPostalCode::class => [
                BorrowerStoreBusinessInfoRequest::class => ['business_postal_code'],
                BorrowerStoreOwnershipInfoRequest::class => ['zip_code'],
                BorrowerCoBorrowerValidationRequest::class => ['zip_code'],
                StoreCreditProfileRequestRequest::class => ['zip'],
                UpdateBusinessInfoRequest::class => ['business_zip_code'],
                UpdatePersonalProfileRequest::class => ['zip_code'],
                DemoRequestStoreOrUpdateRequest::class => ['zip_code'],
                StorePartnerRequest::class => ['zip_code'],
                UpdatePartnerRequest::class => ['postal_code'],
                StoreBorrowerRequest::class => ['postal_code', 'business_postal_code'],
                UpdateBorrowerRequest::class => ['postal_code', 'business_postal_code'],
                StoreContactRequest::class => ['zip_code'],
                UpdateContactRequest::class => ['zip_code'],
                StoreEmployeeRequest::class => ['postal_code'],
                UpdateEmployeeRequest::class => ['postal_code'],
                StoreLeadRequest::class => ['zip_code'],
                UpdateLeadContactInfoRequest::class => ['zip_code'],
                UpdateLeadRequest::class => ['zip_code'],
                PartnerCoBorrowerValidationRequest::class => ['zip_code'],
                PartnerStoreBusinessInfoRequest::class => ['business_postal_code'],
                PartnerStoreOwnershipInfoRequest::class => ['zip_code'],
                SubmitEmbedFormRequest::class => ['business_postal_code', 'zip_code', 'co_borrowers.*.zip_code'],
            ],
            UsPhoneNumber::class => [
                BorrowerStoreBusinessInfoRequest::class => ['business_phone_no'],
                BorrowerStoreOwnershipInfoRequest::class => ['phone_number'],
                BorrowerCoBorrowerValidationRequest::class => ['mobile_phone', 'home_phone'],
                PersonalInfoRequest::class => ['phone_number'],
                StoreCreditProfileRequestRequest::class => ['phone'],
                UpdateBusinessInfoRequest::class => ['business_phone'],
                UpdatePersonalProfileRequest::class => ['phone_number', 'home_phone'],
                UpdateSiteSettingsRequest::class => ['phone_number'],
                DemoRequestStoreOrUpdateRequest::class => ['phone', 'assigned_phone'],
                StoreLenderRequest::class => ['contact_phone'],
                UpdateLenderRequest::class => ['contact_phone'],
                StorePartnerRequest::class => ['phone_number', 'contact_person_phone_number'],
                UpdatePartnerRequest::class => ['phone_number', 'contact_person_phone_number'],
                UpdateProfileRequest::class => ['phone_number'],
                StoreBorrowerRequest::class => ['phone_number', 'business_phone'],
                UpdateBorrowerRequest::class => ['phone_number', 'business_phone'],
                StoreContactRequest::class => ['mobile_number'],
                UpdateContactRequest::class => ['mobile_number'],
                StoreEmployeeRequest::class => ['emergency_phone', 'phone_number_1', 'phone_number_2'],
                UpdateEmployeeRequest::class => ['emergency_phone', 'phone_number_1', 'phone_number_2'],
                StoreLeadRequest::class => ['phone_number', 'business_phone'],
                UpdateLeadContactInfoRequest::class => ['phone_number', 'business_phone'],
                UpdateLeadRequest::class => ['phone_number', 'business_phone'],
                SendTestSmsRequest::class => ['phone'],
                StoreUserRequest::class => ['phone_number'],
                UpdateUserRequest::class => ['phone_number'],
                PartnerCoBorrowerValidationRequest::class => ['mobile_phone', 'home_phone'],
                PartnerStoreBusinessInfoRequest::class => ['business_phone_no'],
                PartnerStoreOwnershipInfoRequest::class => ['phone_number'],
                SubmitEmbedFormRequest::class => ['business_phone_no', 'phone', 'co_borrowers.*.mobile_phone', 'co_borrowers.*.home_phone'],
                WebsiteStoreLeadRequest::class => ['phone_number'],
                StorePreQualificationRequest::class => ['phone_number'],
            ],
            Ssn::class => [
                BorrowerStoreOwnershipInfoRequest::class => ['ssn'],
                BorrowerCoBorrowerValidationRequest::class => ['ssn'],
                StoreCreditProfileRequestRequest::class => ['ssn'],
                UpdatePersonalProfileRequest::class => ['ssn'],
                StoreBorrowerRequest::class => ['ssn'],
                UpdateBorrowerRequest::class => ['ssn'],
                PartnerCoBorrowerValidationRequest::class => ['ssn'],
                PartnerStoreOwnershipInfoRequest::class => ['ssn'],
                SubmitEmbedFormRequest::class => ['ssn', 'co_borrowers.*.ssn'],
            ],
            BusinessEin::class => [
                UpdateBusinessInfoRequest::class => ['business_ein'],
                StoreBorrowerRequest::class => ['business_ein'],
                UpdateBorrowerRequest::class => ['business_ein'],
                StoreLeadRequest::class => ['business_ein'],
                UpdateLeadContactInfoRequest::class => ['business_ein'],
                UpdateLeadRequest::class => ['business_ein'],
                // A partner's tax id is its EIN: partners are US businesses.
                DemoRequestStoreOrUpdateRequest::class => ['tax_id'],
                StorePartnerRequest::class => ['tax_id'],
                UpdatePartnerRequest::class => ['tax_id'],
            ],
            DunsNumber::class => [
                UpdateBusinessInfoRequest::class => ['duns_number'],
                StoreBorrowerRequest::class => ['duns_number'],
                UpdateBorrowerRequest::class => ['duns_number'],
                StoreLeadRequest::class => ['duns_number'],
                UpdateLeadContactInfoRequest::class => ['duns_number'],
                UpdateLeadRequest::class => ['duns_number'],
            ],
        ];

        foreach ($wired as $rule => $requests) {
            foreach ($requests as $request => $keys) {
                foreach ($keys as $key) {
                    // Labelled by namespace: the borrower and partner portals have
                    // requests of the same name.
                    $label = str_replace('App\\Http\\Requests\\Api\\V1\\', '', $request);
                    yield "{$label} {$key}" => [$request, $key, $rule, []];
                }
            }
        }

        // The change-request modal validates one field at a time as `new_value`,
        // with the rule of whichever field it names.
        $changeRequest = [
            'business_zip_code' => UsPostalCode::class,
            'zip_code' => UsPostalCode::class,
            'business_phone' => UsPhoneNumber::class,
            'phone_number' => UsPhoneNumber::class,
            'home_phone' => UsPhoneNumber::class,
            'business_ein' => BusinessEin::class,
            'duns_number' => DunsNumber::class,
        ];

        foreach ($changeRequest as $field => $rule) {
            yield "StoreChangeRequest {$field}" => [StoreChangeRequest::class, 'new_value', $rule, ['field_name' => $field]];
        }
    }

    #[Test]
    #[DataProvider('fields')]
    public function it_validates_the_field_with_the_shared_rule(string $requestClass, string $key, string $ruleClass, array $input): void
    {
        $rules = $this->rulesOf($requestClass, $input);

        $this->assertArrayHasKey($key, $rules, "{$requestClass} no longer validates {$key}");
        $this->assertTrue(
            collect((array) $rules[$key])->contains(fn ($rule) => $rule instanceof $ruleClass),
            "{$requestClass} must validate {$key} with the shared " . class_basename($ruleClass) . ' rule'
        );
    }

    /**
     * @param class-string<FormRequest> $requestClass
     */
    private function rulesOf(string $requestClass, array $input): array
    {
        $user = User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'Test User',
            'email' => 'user-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->actingAs($user, 'api');

        /** @var FormRequest $request */
        $request = $requestClass::create('/wiring/1', 'POST', $input);
        $request->setContainer($this->app);
        $request->setUserResolver(fn () => $user);

        $route = (new Route('POST', '/wiring/{id}', []))->bind($request);
        foreach (['id' => 999999, 'contact' => 999999, 'employee' => 999999, 'lead' => 999999, 'user' => $user] as $name => $value) {
            $route->setParameter($name, $value);
        }
        $request->setRouteResolver(fn () => $route);

        return $request->rules();
    }
}
