<?php

namespace App\Services\Borrower\PreQualification;

use App\Helpers\ApiResponse;
use App\Http\Resources\Borrower\PreQualification\BorrowerDetailsProfile;
use App\Http\Resources\Borrower\PreQualification\PreQualificationResource;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Business\Business;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;
use Twilio\Rest\Api;

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

        return $this->paginateBuilder($query->orderByDesc('created_at'),$request);
    }



    public function prequalificationRequest()
    {
        $user = Auth::user();
        $borrower = Borrower::where('id', $user->entity_id)->first();

        $prequalificationRequest = PreQualificationRequest::where('borrower_id', $borrower->id)
            ->where('status', PreQualificationRequest::ON_HOLD)
            ->first();

        $mergedData = $borrower->toArray();
        $prequalificationId = null;

        if ($prequalificationRequest) {
            $prequalificationId = $prequalificationRequest->id;
            
            foreach ($prequalificationRequest->toArray() as $key => $value) {
                if ($this->hasValue($value)) {
                    $mergedData[$key] = $value;
                }
            }
        }

        $mergedData['id'] = $prequalificationId;

        $dataObject = (object) $mergedData;

        return ApiResponse::success(
            new PreQualificationResource($dataObject),
            __('borrower.pre_qualification_request_success')
        );
    }

    private function hasValue($value): bool
    {
        return !empty($value) || $value === 0 || $value === false;
    }


    public function personalInfo(array $data)
    {
       try{
        $user = Auth::user();
        $payload = array_merge($data, [
            'borrower_id' => $user->entity_id,
            'status' => PreQualificationRequest::ON_HOLD,
        ]);

        return PreQualificationRequest::updateOrCreate(
            ['id' => isset($data['id']) ? $data['id'] : null],
            $payload
        );
       }catch(\Exception $e){
           throw $e;
       }
       
    }

    public function businessInfo(array $data)
    {
       try{
        $user = Auth::user();
        $payload = array_merge($data, [
            'borrower_id' => $user->entity_id,
            'status' => PreQualificationRequest::ON_HOLD,
        ]);

        return PreQualificationRequest::updateOrCreate(
            ['id' => isset($data['id']) ? $data['id'] : null],
            $payload
        );
       }catch(\Exception $e){
           throw $e;
       }
       
    }

    public function fundingInfo(array $data)
    {
       try{
        $user = Auth::user();
        $payload = array_merge($data, [
            'borrower_id' => $user->entity_id,
            'status' => PreQualificationRequest::ON_HOLD,
        ]);

        return PreQualificationRequest::updateOrCreate(
            ['id' => isset($data['id']) ? $data['id'] : null],
            $payload
        );
       }catch(\Exception $e){
           throw $e;
       }
       
    }
}