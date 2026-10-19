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
            'name' => 'required|string|max:255',
            'slug' => [
                'nullable',
                'string',
                Rule::unique('blog_tags')->where(function ($query) use ($partner_id) {
                    return $query->where('partner_id', $partner_id);
                })->ignore($id),
            ],
        ];
    }
}
