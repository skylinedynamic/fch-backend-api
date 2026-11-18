<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ManageAgreement;

use App\Models\Common\AgreementSavedTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Agreement Builder › Save as Template: the agreement being edited, under a
 * name. Which types the owner may save, and whether the name is free, depend on
 * whose agreement it is - checked by AgreementSavedTemplateService.
 */
class AgreementSavedTemplateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', Rule::in(AgreementSavedTemplate::slugs())],
            'name' => ['required', 'string', 'max:' . AgreementSavedTemplate::NAME_MAX],
            'template_json' => ['required', 'string', 'json'],
        ];
    }
}
