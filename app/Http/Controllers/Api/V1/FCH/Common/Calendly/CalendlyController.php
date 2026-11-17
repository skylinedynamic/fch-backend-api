<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Calendly;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Calendly\BookCalendlyAppointmentRequest;
use App\Http\Requests\Api\V1\FCH\Common\Calendly\CalendlyAvailableTimesRequest;
use App\Http\Requests\Api\V1\FCH\Common\Calendly\StoreCalendlySettingsRequest;
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
 * A partner's Calendly: its status and settings (own app keys), connecting it (OAuth), event
 * types, open slots and booking - and the webhook Calendly calls.
 */
class CalendlyController extends Controller
{
    public function __construct(private readonly CalendlyService $calendlyService) {}

    public function status(Request $request): JsonResponse
    {
        return $this->respond(fn () => ApiResponse::success($this->calendlyService->status($request->user()), __('calendly.status_retrieved')));
    }

    /** Settings > Calendly: the partner's saved app keys in full (for its own account or the host) and its connection. */
    public function settings(Request $request): JsonResponse
    {
        return $this->respond(fn () => ApiResponse::success(
            $this->calendlyService->settings($request->user(), $this->partnerId($request)),
            __('calendly.settings_retrieved'),
        ));
    }

    public function saveSettings(StoreCalendlySettingsRequest $request): JsonResponse
    {
        return $this->respond(fn () => ApiResponse::success(
            $this->calendlyService->saveSettings($request->user(), $this->partnerId($request), $request->validated()),
            __('calendly.settings_saved'),
        ));
    }

    /** Connect Calendly: the sign-in link, with the partner's own saved app keys. */
    public function connect(Request $request): JsonResponse
    {
        return $this->respond(fn () => ApiResponse::success(['url' => $this->calendlyService->connect($request->user(), $this->partnerId($request))], __('calendly.connect_url')));
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

    public function disconnect(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $this->calendlyService->disconnect($request->user(), $this->partnerId($request));

            return ApiResponse::success(null, __('calendly.disconnected'));
        });
    }

    public function sync(Request $request): JsonResponse
    {
        return $this->respond(fn () => ApiResponse::success(['synced' => $this->calendlyService->syncNow($request->user(), $this->partnerId($request))], __('calendly.synced')));
    }

    /** The partner the host account acts for (Partners > partner > Configuration); none = the caller's own. */
    private function partnerId(Request $request): ?int
    {
        return $request->integer('partner_id') ?: null;
    }

    public function eventTypes(Request $request): JsonResponse
    {
        return $this->respond(fn () => ApiResponse::success($this->calendlyService->eventTypes($request->user()), __('calendly.event_types_retrieved')));
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

    /** Calendly's signed webhook for one connection: a booking or a cancellation on that owner's Calendly. */
    public function webhook(Request $request, string $connection): JsonResponse
    {
        try {
            if (! $this->calendlyService->handleWebhook($connection, $request->getContent(), $request->header(CalendlyWebhookSignature::HEADER))) {
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
