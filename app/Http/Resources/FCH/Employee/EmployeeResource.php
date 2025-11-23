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
            
            // Name fields
            'full_name' => trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            
            // Contact
            'email' => $this->email,
            'phone_number' => $this->phone_number_1, // alias for compatibility
            'phone_number_1' => $this->phone_number_1,
            'phone_number_2' => $this->phone_number_2,
            
            // Personal Info
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'gender' => $this->gender,
            'blood_group' => $this->blood_group,
            'place_of_birth' => $this->place_of_birth,
            'ssn' => $this->ssn,
            'marital_status' => $this->marital_status,
            'no_of_children' => $this->no_of_children,
            
            // Address
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            
            // Employment
            'designation' => $this->designation,
            'department' => $this->department,
            'job_status' => $this->job_status,
            'joining_date' => $this->joining_date,
            'employee_code' => $this->employee_code,
            'identification_number' => $this->identification_number,
            'passport_number' => $this->passport_number,
            // Emergency Contact
            'emergency_contact' => $this->emergency_contact,
            'emergency_phone' => $this->emergency_phone,
            
            // Image
            'image' => $this->image,
          
            // User with partner_id filtered role
            'user' => $this->whenLoaded('user', function () {
                  
                if (!$this->user) {
                    return null;
                }

                $partnerId = auth()->user()?->partner_id;

                // Get first role for this partner
              
                $role = $this->user->roles()
                                   ->where('roles.partner_id', $partnerId)
                                   ->first();

                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'phone_number' => $this->user->phone_number,
                    'user_type' => $this->user->user_type,
                    'created_at' => $this->user->created_at?->format('Y-m-d H:i:s'),
                    'role_id' => $role?->id,
                    'role_name' => $role?->name,
                ];
            }),
            
            // Timestamps
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
