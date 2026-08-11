<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\FundingApplication;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\FCH\Admin\FundingApplication\PartnerAssistedApplicationService;
use Exception;
use Illuminate\Support\Facades\Log;

class PartnerAssistedApplicationController extends Controller
{
    protected $partnerAssistedService;

    public function __construct(PartnerAssistedApplicationService $partnerAssistedService)
    {
        $this->partnerAssistedService = $partnerAssistedService;
    }

    public function startAssistedApplication(Request $request, $fundingRequestId)
    {
        try {
            // Validate partner acknowledgment 
            $request->validate([
                'partner_acknowledgment' => 'required|accepted'
            ]);

            $application = $this->partnerAssistedService->startAssistedApplication(
                $fundingRequestId, 
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Partner-assisted draft started successfully',
                'data' => $application
            ]);

        } catch (Exception $e) {
            Log::error('Error starting partner-assisted application: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to start draft',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function sendToBorrower(Request $request, $applicationId)
    {
        try {
            $application = $this->partnerAssistedService->sendToBorrower($applicationId);

            return response()->json([
                'success' => true,
                'message' => 'Application sent to borrower for review successfully',
                'data' => $application
            ]);
            
        } catch (Exception $e) {
            Log::error('Error sending application to borrower: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to send to borrower',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
