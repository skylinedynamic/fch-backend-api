<?php

namespace App\Http\Resources\FCH\Partner;

use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class DemoRequestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'company_name'   => $this->company_name,
            'customer_name'  => $this->customer_name,
            'demo_access' => ($this->start_date && $this->end_date)
                    ? Carbon::parse($this->start_date)->format('M j') . ' - ' . Carbon::parse($this->end_date)->format('M j, Y')
                    : 'Not started',
            'phone_number'   => $this->phone_number,
            'email'          => $this->email,
            'assign_to'      => $this->assign_to,
            'status'         => $this->status,
        ];
    }
}
