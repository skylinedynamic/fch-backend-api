<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Partner\Partner;

class FetchPartner extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    /**
     * Unwrap filter values before the rules run.
     *
     * A filter reaches this endpoint in two shapes: a plain scalar from the filter
     * bar dropdowns, and PrimeReact's column-filter object, {value, matchMode}.
     * The other filters cope by having no type rule at all and being unwrapped in
     * the controller - but that runs after validation, so a typed rule here saw the
     * raw array and rejected a perfectly valid status with "must be a string".
     *
     * Normalising first means status can keep a real Rule::in check while still
     * accepting both shapes.
     */
    protected function prepareForValidation(): void
    {
        foreach (['status', 'archived', 'partner_id', 'assigned_user_id', 'is_free_trail'] as $field) {
            if (!$this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if (is_array($value)) {
                $this->merge([$field => $value['value'] ?? null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'partner_id'        => ['nullable'],
            'assigned_user_id'  => ['nullable'],
            'search'            => ['nullable', 'string'],
            // Built from Partner::STATUS so a status added to the model is accepted
            // here without anyone remembering to update a literal.
            'status'            => ['nullable', 'string', Rule::in(array_values(Partner::STATUS))],
            // 'all' keeps archived rows alongside active ones; anything truthy
            // narrows to archived only. Absent means the working list, except
            // during a search, which reaches archived records by design.
            'archived'          => ['nullable'],
            'is_free_trail'     => ['nullable'],
            'filters'           => ['nullable', 'array'],
            'page'              => ['nullable', 'integer', 'min:1'],
            'per_page'          => ['nullable', 'integer', 'min:1'],
            'sort_field'        => ['nullable', 'string'],
            'sort_order'        => ['nullable', 'in:asc,desc'],
        ];
    }
}
