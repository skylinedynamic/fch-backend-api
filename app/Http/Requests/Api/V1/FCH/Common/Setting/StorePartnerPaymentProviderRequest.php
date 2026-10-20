<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Setting;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Rules\RoutingNumber;
use App\Rules\StripeKeysMatchPaymentMode;
use App\Support\Payment\PaymentCredentials;
use App\Support\Payment\PaymentSetup;
use App\Support\Payment\StripeKeyVerifier;
use Illuminate\Validation\Validator;
use Illuminate\Validation\Rule;

class StorePartnerPaymentProviderRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Name of the provider this configuration is being saved for.
     *
     * Only Stripe's keys carry a mode in their prefix, so the credential check
     * needs to know which provider it is looking at.
     */
    protected function providerName(): ?string
    {
        return PaymentProvider::find($this->input('payment_provider_id'))?->name;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => 'integer|exists:partner_payment_providers,id',
            'payment_provider_id' => 'required|exists:payment_providers,id',
            'payment_mode' => ['required',Rule::in([PaymentProvider::MODE_PRODUCTION, PaymentProvider::MODE_SANDBOX])],
            'status' => 'boolean',
            'is_default' => 'boolean',
            'configuration' => ['nullable', 'array', new StripeKeysMatchPaymentMode($this->providerName())],
            'bank_details' => 'nullable|array',
            'bank_details.*.id' => 'nullable|integer|exists:payment_method_bank_details,id',
            'bank_details.*.bank_name' => 'required|string|max:255',
            'bank_details.*.account_name' => 'required|string|max:255',
            'bank_details.*.account_number' => 'required|string|max:255',
            'bank_details.*.routing_number' => ['nullable', 'string', new RoutingNumber()],
            'bank_details.*.instructions' => 'nullable|string',
            'bank_details.*.payment_mode' => ['required', Rule::in([PaymentProvider::MODE_PRODUCTION, PaymentProvider::MODE_SANDBOX])],
            'bank_details.*.is_default' => 'boolean',
            'bank_details.*.status' => 'boolean',
        ];
    }

    /**
     * Once the rules pass, ask Stripe whether new Stripe keys will work.
     *
     * Each mode that was given keys is checked, but only when they differ from
     * what's saved - re-saving the form to change something else asks Stripe
     * nothing, and can't be blocked by keys that were accepted before. Only a
     * definite "no" from Stripe refuses the save (see StripeKeyVerifier).
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->providerName() !== PaymentProvider::STRIPE) {
                    return;
                }

                $submitted = PaymentCredentials::toArray($this->input('configuration'));
                $saved = PaymentCredentials::toArray($this->savedConfiguration());

                foreach ([PaymentProvider::MODE_SANDBOX, PaymentProvider::MODE_PRODUCTION] as $mode) {
                    if (!isset($submitted[$mode]) || !is_array($submitted[$mode])) {
                        continue;
                    }

                    $publishable = $submitted[$mode]['publishable_key'] ?? null;
                    $secret = $submitted[$mode]['secret_key'] ?? null;

                    if (blank($publishable) && blank($secret)) {
                        continue;
                    }

                    $savedBlock = PaymentCredentials::forMode($saved, $mode);
                    if ($saved !== [] && ($savedBlock['publishable_key'] ?? null) === $publishable && ($savedBlock['secret_key'] ?? null) === $secret) {
                        continue;
                    }

                    $problem = app(StripeKeyVerifier::class)->problem($publishable, $secret, PaymentSetup::modeLabel($mode));
                    if ($problem) {
                        $validator->errors()->add("configuration.{$mode}.{$problem['field']}", $problem['message']);

                        return;
                    }
                }
            },
        ];
    }

    /** The configuration this partner has saved for the provider, if any. */
    private function savedConfiguration(): array
    {
        return PartnerPaymentProvider::where('partner_id', $this->user()?->partner_id)
            ->where('payment_provider_id', $this->input('payment_provider_id'))
            ->first()
            ?->configurationArray() ?? [];
    }
}
