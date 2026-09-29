<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\CreditAuthorization;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Borrower\Borrower;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use App\Support\Branding\PdfLogo;
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

            'logoSrc' => $this->logoFor($borrower),
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
     * The mark this document carries: the BORROWER'S OWN partner's logo.
     *
     * Never the host's by inheritance. getBrandingSettings() inherits logos
     * from the host by default, which is right for a Certified partner - it
     * trades under the host's brand deliberately - but wrong here: this PDF
     * goes to iSoftPull saying the record belongs to that tenant, and a
     * white-label tenant's paperwork carrying the platform's logo announces
     * precisely what the tier exists to hide. A tenant with nothing uploaded
     * gets a document with no logo, not one with someone else's.
     *
     * The host's own borrowers still get the host's logo, because then it IS
     * their partner's - the rule is "the borrower's partner", not "never the
     * host".
     *
     * Surfaces are tried document -> mail -> sidebar over what is actually on
     * disk. Branding chains these keys too, but only by value: a key naming a
     * file that has since gone still counts as set there, so resolution would
     * stop at it and the masthead would come out blank.
     *
     * Embedded rather than linked, because Dompdf fetches no remote images and
     * this document is not a good reason to turn that on.
     */
    private function logoFor(?Borrower $borrower): ?string
    {
        if (!$borrower?->partner_id) {
            return null;
        }

        $branding = app(AppConfigurationService::class)->getBrandingSettings(
            $borrower->partner_id,
            (bool) $borrower->partner?->is_host
        );

        return PdfLogo::firstAvailable([
            $branding['logo_document'] ?? null,
            $branding['logo_mail'] ?? null,
            $branding['logo_sidebar'] ?? null,
        ]);
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
