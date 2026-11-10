<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Calendly;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Calendly\BookCalendlyAppointmentRequest;
use App\Http\Requests\Api\V1\FCH\Common\Calendly\CalendlyAvailableTimesRequest;
use App\Http\Resources\FCH\Appointment\AppointmentResource;
use App\Services\FCH\Common\Calendly\CalendlyService;
use App\Services\Global\Calendly\CalendlyException;
use App\Services\Global\Calendly\CalendlyWebhookSignature;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The company Calendly account: its status, connecting it (OAuth), event
 * types, open slots and booking - and the webhook Calendly calls.
 */
class CalendlyController extends Controller
{
    public function __construct(private readonly CalendlyService $calendlyService) {}

    public function status(Request $request): JsonResponse
    {
        return $this->respond(fn () => ApiResponse::success($this->calendlyService->status($request->user()), __('calendly.status_retrieved')));
    }

    public function connectUrl(Request $request): JsonResponse
    {
        return $this->respond(fn () => ApiResponse::success(['url' => $this->calendlyService->connectUrl($request->user())], __('calendly.connect_url')));
    }

    /** Calendly sends the browser back here after the account owner allows (or declines) access. */
    public function callback(Request $request): RedirectResponse
    {
        return redirect()->away($this->calendlyService->handleCallback(
            $request->query('code'),
            $request->query('state'),
            $request->query('error'),
        ));
    }

    public function disconnect(): JsonResponse
    {
        return $this->respond(function () {
            $this->calendlyService->disconnect();

            return ApiResponse::success(null, __('calendly.disconnected'));
        });
    }

    public function sync(): JsonResponse
    {
        return $this->respond(fn () => ApiResponse::success(['synced' => $this->calendlyService->syncNow()], __('calendly.synced')));
    }

    public function eventTypes(): JsonResponse
    {
        return $this->respond(fn () => ApiResponse::success($this->calendlyService->eventTypes(), __('calendly.event_types_retrieved')));
    }

    public function availableTimes(CalendlyAvailableTimesRequest $request): JsonResponse
    {
        return $this->respond(fn () => ApiResponse::success(
            $this->calendlyService->availableTimes($request->user(), $request->validated()),
            __('calendly.available_times_retrieved')
        ));
    }

    public function book(BookCalendlyAppointmentRequest $request): JsonResponse
    {
        return $this->respond(fn () => ApiResponse::success(
            new AppointmentResource($this->calendlyService->book($request->user(), $request->validated())),
            __('calendly.booked')
        ));
    }

    /** Calendly's signed webhook: a booking or a cancellation on the company account. */
    public function webhook(Request $request): JsonResponse
    {
        try {
            if (! $this->calendlyService->handleWebhook($request->getContent(), $request->header(CalendlyWebhookSignature::HEADER))) {
                return ApiResponse::unauthorized(__('calendly.webhook_invalid_signature'));
            }

            return ApiResponse::success(null, __('calendly.webhook_received'));
        } catch (Throwable $e) {
            Log::error('[CALENDLY] Webhook delivery failed', ['error' => $e->getMessage()]);

            // Not 2xx, so Calendly delivers it again.
            return ApiResponse::serverError();
        }
    }

    private function respond(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            throw $e;
        } catch (CalendlyException $e) {
            return ApiResponse::error($e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            Log::error('[CALENDLY] Request failed', ['error' => $e->getMessage()]);

            return ApiResponse::serverError();
        }
    }
}
