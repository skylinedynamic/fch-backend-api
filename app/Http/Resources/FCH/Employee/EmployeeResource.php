<?php

namespace App\Http\Resources\FCH\Employee;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Employee\Employee */
class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')),
            'phone_number' => $this->phone_number_1,
            'email' => $this->email,
            'department' => $this->department,
            'designation' => $this->designation,
            'job_status' => $this->job_status,
            'address' => $this->address ?? implode(', ', array_filter([
                $this->city,
                $this->state,
                $this->country
            ])),
        ];
    }
}
