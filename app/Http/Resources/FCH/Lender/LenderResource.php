<?php

namespace App\Http\Resources\FCH\Lender;

use Illuminate\Http\Resources\Json\JsonResource;

class LenderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id'                          => $this->id,
            'name'                        => $this->name,
            'legal_name'                  => $this->legal_name,
            'product_categories'          => $this->product_categories,
            'contact_name'                => $this->contact_name,
            'contact_title'               => $this->contact_title,
            'contact_email'               => $this->contact_email,
            'contact_phone'               => $this->contact_phone,
            'website'                     => $this->website,
            'submission_method'           => $this->submission_method,
            'submission_email'            => $this->submission_email,
            'submission_portal_link'      => $this->submission_portal_link,
            'iso_status'                  => $this->iso_status,
            'status'                      => $this->status,
            'internal_notes'              => $this->internal_notes,
            'last_verified_date'          => $this->last_verified_date ? $this->last_verified_date->format('Y-m-d') : null,
            'created_at'                  => $this->created_at,
            'updated_at'                  => $this->updated_at,
        ];
    }
}
