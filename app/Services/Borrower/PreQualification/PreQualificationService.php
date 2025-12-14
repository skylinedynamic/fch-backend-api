<?php

namespace App\Services\Borrower\PreQualification;

use App\Helpers\ApiResponse;
use App\Http\Resources\Borrower\PreQualification\PreQualificationResource;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\PreQualificationRequest;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PreQualificationService
{
    use PaginatesApi;
    
    public function submit(array $data)
    {
        $user = Auth::user();
        $borrower = Borrower::where('id', $user->entity_id)->first();

        $record = PreQualificationRequest::where('id', $data['id'])
            ->where('borrower_id', $borrower->id)
            ->where('status', PreQualificationRequest::ON_HOLD)
            ->first();

        if (!$record) {
            throw new \Exception(__('borrower.pre_qualification_request_not_found'));
        }

        $zapierResponse = $this->sendToZapier($record);

        $record->update(['status' => PreQualificationRequest::SUBMITTED]);
        

        return ApiResponse::success(
            $zapierResponse,
            __('borrower.pre_qualification_submitted_successfully')
        );
    }

    private function sendToZapier(PreQualificationRequest $record)
    {
        try {
            $zapierPayload = [
                'Full Name' => $record->first_name . ' ' . $record->last_name,
                'Phone Number' => $record->phone_number,
                'Email' => $record->email,
                'Business Name' => $record->business_name,
                'Business Website' => $record->business_website,
                'Business Industry' => $record->industry_type,
                'State of Incorporation' => $record->state_of_incorporation,
                'Business Structure' => $record->business_structure,
                'Time in Business' => $record->time_in_business,
                'Average Monthly Revenue' => $record->monthly_revenue,
                'Annual Revenue' => $record->annual_revenue,
                'Estimated Personal Credit Score' => $record->personal_credit_score,
                'Estimated Business Credit Score' => $record->estimated_business_credit_score,
                'Existing Business Debt?' => $record->existing_business_debt ? 'Yes' : 'No',
                'Debt Balance' => $record->debt_balance,
                'Funding Amount Requested' => $record->funding_requested_amount,
                'Purpose of Funding' => $record->purpose_of_funding,
                'Funding Urgency' => $record->funding_urgency,
                'Notes From Borrower' => $record->notes,
            ];

            $response = Http::withoutVerifying()->post('https://hooks.zapier.com/hooks/catch/21994150/ukf0iv0/', $zapierPayload);

            Log::info('Zapier Webhook Response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $zapierPayload
            ]);

            return [
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $zapierPayload
            ];
        } catch (\Exception $e) {
            Log::error('Zapier Webhook Failed', ['error' => $e->getMessage()]);
            throw new \Exception(__('borrower.zapier_webhook_failed'));
        }
    }


    public function prequalificationRequest()
    {
        $user = Auth::user();
        $borrower = Borrower::where('id', $user->entity_id)->first();

        $prequalificationRequest = PreQualificationRequest::where('borrower_id', $borrower->id)
            ->where('status', PreQualificationRequest::ON_HOLD)
            ->first();

        $mergedData = [];

        
        $mappedData = $this->mappingData();

        if (!$prequalificationRequest) {
            $mergedData = [
                // Personal Info
                'first_name' => $borrower->first_name,
                'last_name' => $borrower->last_name,
                'email' => $borrower->email,
                'phone_number' => $borrower->phone_number,
                'business_website' => $borrower->business_website,
                'state_of_incorporation' => $borrower->state,

                // Business Info
                'business_name' => $borrower->business_name,
                'industry_type' => $borrower->business_industry_type,
                'business_structure' => $borrower->business_entity_type,
                'time_in_business' => $borrower->years_in_business,
                'monthly_revenue' => $borrower->monthly_revenue,
                'annual_revenue' => $borrower->annual_revenue,

                // Financial Info
                'funding_requested_amount' => null,
                'funding_urgency' => null,
                'purpose_of_funding' => null,
                'personal_credit_score' => null,
                'estimated_business_credit_score' => null,
                'existing_business_debt' => false,
                'debt_balance' => null,

                //others
                'notes' => null,
                'status' => PreQualificationRequest::ON_HOLD,
                'borrower_id' => $borrower->id,
                ];

                $prequalificationRequest = PreQualificationRequest::create($mergedData)->toArray();

        }else{
            $mergedData['id'] = $prequalificationRequest->id;
             foreach ($prequalificationRequest->toArray() as $key => $value) {
                if (!$this->hasValue($value) && isset($mappedData[$key])) {
                    $mergedData[$key] = $borrower->{$mappedData[$key]};
                 }else{
                    $mergedData[$key] = $value;
                }
            }
        }

        $mergedData['country'] = $borrower->country;
        $mergedData['country_code'] = $borrower->country_code;

        $dataObject = (object) $mergedData;

        return ApiResponse::success(
            new PreQualificationResource($dataObject),
            __('borrower.pre_qualification_request_success')
        );
    }

    /**
     * Get a specific pre-qualification application by ID
     */
    public function getPreQualificationById($id)
    {
        $user = Auth::user();
        $borrower = Borrower::where('id', $user->entity_id)->first();

        if (!$borrower) {
            throw new \Exception(__('borrower.borrower_not_found'));
        }

        // Get pre-qualification request that belongs to this borrower
        $preQualification = PreQualificationRequest::where('id', $id)
            ->where('borrower_id', $borrower->id)
            ->where('status', '!=', PreQualificationRequest::ON_HOLD)
            ->first();

        return $preQualification;
    }

    private function hasValue($value): bool
    {
        return !empty($value) || $value === false;
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
            [
                'id' => isset($data['id']) ? $data['id'] : null,
                'borrower_id' => $user->entity_id,
            ],
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
            [
                'id' => isset($data['id']) ? $data['id'] : null,
                'borrower_id' => $user->entity_id,
            ],  
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
            [
                'id' => isset($data['id']) ? $data['id'] : null,
                'borrower_id' => $user->entity_id,
            ],
            $payload
        );
       }catch(\Exception $e){
           throw $e;
       }
       
    }

    private function mappingData(){
        return [
             // Personal Info
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'email' => 'email',
            'phone_number' => 'phone_number',
            'business_website' => 'business_website',
            'state_of_incorporation' => 'state',

            // Business Info
            'business_name' => 'business_name',
            'industry_type' => 'business_industry_type',
            'business_structure' => 'business_entity_type',
            'time_in_business' => 'years_in_business',
            'monthly_revenue' => 'monthly_revenue',
            'annual_revenue' => 'annual_revenue',
        ];
    }   
}