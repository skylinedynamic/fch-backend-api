<?php

namespace App\Services\Borrower\PreQualification;

use App\Models\Borrower\PreQualificationRequest;
use App\Models\Business\Business;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;

class PreQualificationService
{
    use PaginatesApi;
    
    public function submit(array $data): PreQualificationRequest
    {
       try{
        $user = Auth::user();
        $business = Business::where('id', $user->business->id)->first();

        $payload = array_merge($data, [
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        return PreQualificationRequest::updateOrCreate(
            ['id' => isset($data['id']) ? $data['id'] : null],
            $payload
        );
       }catch(\Exception $e){
           throw $e;
       }
       
    }

    public function prequalificationList(?int $userId, Request $request): LengthAwarePaginator
    {
        $query = PreQualificationRequest::query();
        if (!is_null($userId)) {
            $query->where('user_id', $userId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $query->search($request->query('query'));

        return $this->paginateBuilder($request, $query->orderByDesc('created_at'));
    }
}