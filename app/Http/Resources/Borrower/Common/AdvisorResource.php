<?php

namespace App\Http\Resources\Borrower\Common;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvisorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $image = null;

        if($this->entity_type == Partner::class){
            $image = $this->partner->image;
        }
         if($this->entity_type == Employee::class){
            $image = $this->employee->image;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone_number,
            'avatar' => $image,
            'created_at' => $this->created_at,
        ];
    }
}
