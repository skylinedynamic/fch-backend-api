<?php

namespace App\Http\Requests\Api\V1\FCH\Common\ManageAgreement;

use App\Models\Common\AgreementSavedTemplate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing a saved template: a new name, the agreement now on the page
 * (Save as Template › Replace an existing template), or both. Its type never
 * changes. Whether the name is free is checked by AgreementSavedTemplateService.
 */
class AgreementSavedTemplateUpdateRequest extends FormRequest
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
            'name' => ['required_without:template_json', 'string', 'max:' . AgreementSavedTemplate::NAME_MAX],
            'template_json' => ['required_without:name', 'string', 'json'],
        ];
    }
}
