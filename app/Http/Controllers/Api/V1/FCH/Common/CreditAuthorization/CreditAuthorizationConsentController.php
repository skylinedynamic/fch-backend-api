<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\CreditAuthorization;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authorisation records behind each credit pull, and the PDF sent to
 * iSoftPull when they ask for proof.
 *
 * Host partner only. These records exist to answer an audit of the platform's
 * own permissible purpose, and they carry the consumer's IP and device - not
 * something to hand to every partner who can see a client.
 */
class CreditAuthorizationConsentController extends Controller
{
    /**
     * One record as a PDF - the document that goes to iSoftPull.
     *
     * Everything on it comes off the stored row, never off today's config, so a
     * later change to the published wording cannot rewrite what a past client
     * agreed to.
     */
    public function download(Request $request, int $id): Response|JsonResponse
    {
        if (!$this->isHostUser()) {
            return ApiResponse::error(__('auth.unauthorized'), 403);
        }

        $consent = CreditAuthorizationConsent::with('borrower')->find($id);

        if (!$consent) {
            return ApiResponse::notFound(__('credit_profile_request.consent_not_found'));
        }

        $borrower = $consent->borrower;

        $pdf = Pdf::loadView('credit-authorization.consent', [
            'consent' => $consent,
            'borrowerName' => personName($borrower?->first_name, $borrower?->middle_name, $borrower?->last_name)
                ?: ('Client #' . $consent->borrower_id),
            'borrowerEmail' => $borrower?->email,
            'generatedAt' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
        ])->setPaper('a4')->setWarnings(false);

        // Named so a folder of these stays sortable and unambiguous in an audit
        // response: who, which record.
        $filename = sprintf(
            'credit-authorization-client-%d-record-%d.pdf',
            $consent->borrower_id,
            $consent->id
        );

        return $pdf->download($filename);
    }

    /**
     * Only the host partner. Checked here rather than left to a permission name
     * so that adding this route cannot quietly widen who sees a consumer's IP.
     */
    private function isHostUser(): bool
    {
        return (bool) (Auth::user()?->partner?->is_host);
    }
}
