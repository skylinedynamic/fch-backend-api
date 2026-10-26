<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CMS;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');
        $partner_id = $this->user()->partner_id;

        return [
            // One name per partner, so the picker never shows the same entry twice.
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('blog_tags', 'name')->where(function ($query) use ($partner_id) {
                    return $query->where('partner_id', $partner_id)->whereNull('deleted_at');
                })->ignore($id),
            ],
            'slug' => [
                'nullable',
                'string',
                Rule::unique('blog_tags')->where(function ($query) use ($partner_id) {
                    return $query->where('partner_id', $partner_id);
                })->ignore($id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => __('blog.tag_name_taken'),
        ];
    }
}
