<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Audit;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Audit\FundingApplicationAuditTrailRequest;
use App\Http\Resources\FCH\Common\Audit\FundingApplicationAuditEventResource;
use App\Models\Audit\FundingApplicationAuditEvent;
use App\Services\Audit\FundingApplicationAuditTrail;
use App\Services\Audit\FundingApplicationAuditTrailPdf;
use App\Support\Audit\AuditLabels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\Borrower\FundingApplication;
use App\Models\User\User;
use App\Support\Files\Disk;

/**
 * A funding application's audit trail: the host reads every application's,
 * a partner its own applications', a client none (partner-portal routes).
 */
class FundingApplicationAuditTrailController extends Controller
{
    public function __construct(private FundingApplicationAuditTrail $trail, private FundingApplicationAuditTrailPdf $pdf)
    {
    }

    /** GET common/request-application/funding-application/{id}/audit-trail */
    public function index(FundingApplicationAuditTrailRequest $request, int $id): JsonResponse
    {
        if (!$this->readable($id)) {
            return ApiResponse::notFound(__('audit.not_found'));
        }

        $page = $this->filtered($request, $id)->paginate($request->perPage());

        return response()->json([
            'status' => true,
            'message' => __('audit.listed'),
            'data' => FundingApplicationAuditEventResource::collection($page->items())->resolve($request),
            'pagination' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
            'integrity' => $this->trail->verify($id),
        ]);
    }

    /** GET .../audit-trail/export?format=csv|pdf - the filtered trail as CSV (the default) or PDF. */
    public function export(FundingApplicationAuditTrailRequest $request, int $id): StreamedResponse|JsonResponse
    {
        if (!$this->readable($id)) {
            return ApiResponse::notFound(__('audit.not_found'));
        }

        $query = $this->filtered($request, $id);

        $format = $request->exportFormat();
        $fileName = __('audit.export_file', ['id' => $id, 'ext' => $format]);
        $headers = ['Content-Type' => FundingApplicationAuditTrailRequest::EXPORT_MIME_TYPES[$format]];

        if ($format === FundingApplicationAuditTrailRequest::EXPORT_PDF) {
            $pdf = $this->pdf->render($id, $query->get());

            return response()->streamDownload(function () use ($pdf) {
                echo $pdf;
            }, $fileName, $headers);
        }

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_values(__('audit.csv')));

            foreach ($query->cursor() as $event) {
                fputcsv($out, [
                    $event->occurred_at?->utc()->format(config('audit.date_format')),
                    AuditLabels::event($event->event),
                    $event->actor_name,
                    $event->actor_type,
                    $event->ip_address,
                    AuditLabels::changes($event->changes),
                    AuditLabels::context($event->context),
                    $event->file_hash,
                    $event->hash,
                    __($event->is_legacy ? 'audit.yes' : 'audit.no'),
                ]);
            }

            fclose($out);
        }, $fileName, $headers);
    }

    /** GET .../audit-trail/{eventId}/file - the copy of a document the trail kept. */
    public function file(int $id, int $eventId): StreamedResponse|JsonResponse
    {
        $event = FundingApplicationAuditEvent::visibleTo(Auth::user())
            ->where('funding_application_id', $id)
            ->whereKey($eventId)
            ->first();

        $path = $event ? $this->trail->storedFile($event) : null;
        if (!$path) {
            return ApiResponse::notFound(__('audit.file_not_found'));
        }

        $name = $event->context['name'] ?? basename($path);

        return Storage::disk(Disk::UPLOADS)->download($path, $name);
    }

    private function readable(int $id): bool
    {
        // A partner reads its own applications' trail only; the host reads every one.
        if (Auth::user()?->user_type !== User::ADMIN
            && !FundingApplication::withTrashed()->reviewableBy(Auth::user())->whereKey($id)->exists()) {
            return false;
        }

        return true;
    }

    private function filtered(FundingApplicationAuditTrailRequest $request, int $id): Builder
    {
        $search = $request->validated('search');

        return FundingApplicationAuditEvent::visibleTo(Auth::user())
            ->where('funding_application_id', $id)
            ->when($request->validated('event'), fn ($q, $event) => $q->where('event', $event))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->whereContains('actor_name', $search)
                ->orWhereContains('ip_address', $search)
                ->orWhereContains('file_hash', $search)
                ->orWhereContains('changes', $search)
                ->orWhereContains('context', $search)))
            ->orderBy('id', $request->sortDirection());
    }
}
