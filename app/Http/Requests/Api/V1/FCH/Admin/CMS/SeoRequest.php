<?php
namespace App\Http\Requests\Api\V1\FCH\Admin\CMS;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\CMS\SeoSetting;
use App\Rules\SafeUpload;

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
            'meta_title' => ['nullable', 'string', 'max:' . $this->metaTitleMaxLength($id, $partner_id)],
            'meta_description' => 'nullable|string',
            'keywords' => 'nullable|string',
            'og_image' => [
                'nullable', 
                'sometimes', 
                request()->hasFile('og_image') ? 'image' : 'string',
                request()->hasFile('og_image') ? 'mimes:png,jpg,jpeg,svg,webp' : 'nullable',
                'max:2048',
                SafeUpload::image(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'meta_title.max' => __('seo.validation_meta_title_max', ['max' => SeoSetting::META_TITLE_MAX_LENGTH]),
        ];
    }

    /**
     * A title saved before the limit existed may be longer; it is kept while
     * an edit sends it back unchanged. Any new or changed title gets the limit.
     */
    private function metaTitleMaxLength($id, $partnerId): int
    {
        if ($id && is_string($this->input('meta_title'))) {
            $current = SeoSetting::where('partner_id', $partnerId)->whereKey($id)->value('meta_title');
            if ($current !== null && $current === $this->input('meta_title')) {
                return max(SeoSetting::META_TITLE_MAX_LENGTH, mb_strlen($current));
            }
        }

        return SeoSetting::META_TITLE_MAX_LENGTH;
    }
}
