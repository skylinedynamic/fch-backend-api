<?php

namespace App\Http\Resources\Borrower\Funding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = $request->header('X-Timezone') ?? config('app.timezone');

        $uploadedDocNames = is_array($this->documents_uploaded) ? $this->documents_uploaded : json_decode($this->documents_uploaded, true) ?? [];
        
        $actualDocs = [];
        if ($this->borrower && count($uploadedDocNames) > 0) {
            $allBorrowerDocs = $this->borrower->borrowerDocuments()->with('documentType')->get();
            
            foreach ($uploadedDocNames as $idx => $name) {
                $nameSmart = str_replace("'", "’", $name);
                $nameStraight = str_replace(['’', '‘'], "'", $name);
                
                $matchingDoc = $allBorrowerDocs->filter(function($doc) use ($name, $nameSmart, $nameStraight) {
                    if (!$doc->documentType) return false;
                    $docName = $doc->documentType->name;
                    return $docName === $name || $docName === $nameSmart || $docName === $nameStraight;
                })->sortByDesc('id')->first();
                
                if ($matchingDoc) {
                    $actualDocs[] = [
                        'id' => $matchingDoc->id,
                        'document_name' => $name,
                        'file_name' => $matchingDoc->file_name,
                        'file_path' => $matchingDoc->file_path,
                        'document_url' => $matchingDoc->url,
                        'status' => $matchingDoc->status,
                        'created_at' => $matchingDoc->created_at,
                        'rejection_reason' => $matchingDoc->rejection_reason,
                    ];
                } else {
                    $actualDocs[] = [
                        'id' => 'missing_' . $idx,
                        'document_name' => $name,
                        'file_name' => null,
                        'file_path' => null,
                        'document_url' => null,
                        'status' => 'Pending',
                        'created_at' => null,
                        'rejection_reason' => null,
                    ];
                }
            }
        }

        return [
            'id' => $this->id,
            'borrower_id' => $this->borrower_id,
            'funding_type' => $this->funding_type,
            'requested_amount' => $this->requested_amount ? currencyFormat($this->requested_amount, 0) : null,
            'use_of_funds' => $this->use_of_funds,
            'status' => $this->status,
            'documents_uploaded' => $this->documents_uploaded,
            'actual_documents' => $actualDocs,
            'estimated_speed' => $this->estimated_speed,
            'reject_reason' => $this->reject_reason,
            'approved_at' => $this->approved_at?->timezone($timezone)->format('M d Y, g:i A'),
            'created_at' => $this->created_at?->timezone($timezone)->format('M d Y, g:i A'),
            'updated_at' => $this->updated_at?->timezone($timezone)->format('M d Y, g:i A'),
            'lender_ids' => $this->lender_ids,
            'matched_lenders' => $this->matched_lenders,
            'lender_names' => $this->lender_names,
            'funding_sequence' => [
                'id' => $this->fundingSequence->id ?? null,
                'name' => $this->fundingSequence->name ?? null,
                'amount_range' => (function () {
                    $range = $this->fundingSequence->amount_range ?? null;
                    if (!$range) return null;
                    $parts = explode('-', $range);
                    if (count($parts) === 2) {
                        return currencyFormat(trim($parts[0]), 0) . ' - ' . currencyFormat(trim($parts[1]), 0);
                    }
                    return currencyFormat(trim($range), 0);
                })(),
            ],
            'borrower' => [
                'id' => $this->borrower->id ?? null,
                'first_name' => $this->borrower->first_name ?? null,
                'last_name' => $this->borrower->last_name ?? null,
                'company_name' => $this->borrower->company_name ?? null,
            ],
            'reviewer' => $this->whenLoaded('reviewer', function () {
                return [
                    'id' => $this->reviewer->id ?? null,
                    'name' => $this->reviewer->name ?? null,
                ];
            }),
        ];
    }
}
