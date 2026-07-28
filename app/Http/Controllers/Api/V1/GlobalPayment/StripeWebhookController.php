<?php

namespace App\Http\Controllers\Api\V1\GlobalPayment;

use App\Http\Controllers\Controller;
use App\Services\GlobalPayment\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Exception;

class StripeWebhookController extends Controller
{
    public function __construct(
        protected StripePaymentService $paymentService
    ) {}

    /**
     * Handle incoming Stripe webhook request.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = env('STRIPE_WEBHOOK_SECRET');

        // Extract event array
        $event = json_decode($payload, true);

        // Verification (Only verify signature if webhook secret is configured)
        if ($endpointSecret && $sigHeader) {
            try {
                $stripeEvent = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
                $event = $stripeEvent->toArray();
            } catch (Exception $e) {
                Log::error('[Stripe Webhook Controller] Signature verification failed', ['error' => $e->getMessage()]);
                return response()->json(['error' => 'Webhook Signature Verification Failed: ' . $e->getMessage()], 400);
            }
        }

        try {
            $this->paymentService->handleWebhook($event);
            return response()->json(['status' => 'success']);
        } catch (Exception $e) {
            Log::error('[Stripe Webhook Controller] Processing error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Webhook Processing Failed: ' . $e->getMessage()], 400);
        }
    }
}
