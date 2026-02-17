<?php

namespace App\Http\Resources\FCH\Appointment;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $type = User::USER;
        if($this->entity_type == Borrower::class){
            $type = User::BORROWER;
        }
        if($this->entity_type == Employee::class){
            $type = User::EMPLOYEE;
        }
        if($this->entity_type == Partner::class){
            if($this->partner->is_host){
                $type = User::ADMIN;
            }else{
                $type = User::PARTNER;
            }
        }

        return [
            'id' => $this->id,
            'name' => $this->name . ' ('.$type.')',
            'entity_type' => $this->entity_name,
            'entity_id' => $this->entity_id,
        ];
    }
}
