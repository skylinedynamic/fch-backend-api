<?php

namespace App\Http\Resources\FCH\CMS;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'partner_id' => $this->partner_id,
            'site_name' => $this->site_name,
            'site_slogan' => $this->site_slogan,
            'address' => $this->address,
            'phone_number' => $this->phone_number,
            'email' => $this->email,
            'header_logo' => $this->header_logo,
            'footer_logo' => $this->footer_logo,
            'partner_logo' => $this->partner_logo,
            'favicon' => $this->favicon,
            'map_iframe' => $this->map_iframe,
            'social_links' => $this->social_links,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
