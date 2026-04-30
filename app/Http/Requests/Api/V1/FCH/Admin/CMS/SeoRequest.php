<?php
namespace App\Http\Requests\Api\V1\FCH\Admin\CMS;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SeoRequest extends FormRequest
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
            'page_name' => 'required|string|max:255',
            'path' => [
                'required',
                'string',
                Rule::unique('seo_settings')->where(function ($query) use ($partner_id) {
                    return $query->where('partner_id', $partner_id);
                })->ignore($id),
            ],
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'keywords' => 'nullable|string',
            'og_image' => [
                'nullable', 
                'sometimes', 
                request()->hasFile('og_image') ? 'image' : 'string',
                request()->hasFile('og_image') ? 'mimes:png,jpg,jpeg,svg,webp' : 'nullable',
                'max:2048'
            ],
        ];
    }
}
