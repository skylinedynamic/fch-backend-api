<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\CMS;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');
        $partner_id = $this->user()->partner_id;

        $rules = [
            'title' => 'required|string|max:255',
            'slug' => [
                'nullable',
                'string',
                Rule::unique('blogs')->where(function ($query) use ($partner_id) {
                    return $query->where('partner_id', $partner_id);
                })->ignore($id),
            ],
            'category_id' => 'nullable|exists:blog_categories,id',
            'blog_tag_id' => 'nullable|array',
            'blog_tag_id.*' => 'exists:blog_tags,id',
            'content' => 'required|string',
            'summary' => 'nullable|string',
            'featured_image' => [
                'nullable', 
                'sometimes', 
                request()->hasFile('featured_image') ? 'image' : 'string',
                request()->hasFile('featured_image') ? 'mimes:png,jpg,jpeg,svg,webp' : 'nullable',
                'max:2048'
            ],
            'og_image' => [
                'nullable', 
                'sometimes', 
                request()->hasFile('og_image') ? 'image' : 'string',
                request()->hasFile('og_image') ? 'mimes:png,jpg,jpeg,svg,webp' : 'nullable',
                'max:2048'
            ],
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'keywords' => 'nullable|string',
            'is_published' => 'nullable',
            'is_featured' => 'nullable',
            'published_at' => 'nullable|date',
        ];

        // For updates, we use 'sometimes' logic or just keep them required if that's the intent
        if ($id) {
            foreach ($rules as $key => $rule) {
                if (is_string($rule) && !str_contains($rule, 'sometimes')) {
                    $rules[$key] = 'sometimes|' . $rule;
                }
            }
        }

        return $rules;
    }

    protected function prepareForValidation()
    {
        if ($this->has('is_published')) {
            $this->merge([
                'is_published' => $this->is_published === 'true' || $this->is_published === '1' || $this->is_published === true,
            ]);
        }
        if ($this->has('is_featured')) {
            $this->merge([
                'is_featured' => $this->is_featured === 'true' || $this->is_featured === '1' || $this->is_featured === true,
            ]);
        }
    }
}
