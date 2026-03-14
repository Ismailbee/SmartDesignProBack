<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NlpService;
use App\Services\PaymentService;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly PaystackService $paystackService,
        private readonly NlpService $nlpService,
    ) {
    }

    public function initialize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'userId' => ['required', 'string'],
            'email' => ['required', 'email'],
            'amount' => ['required', 'integer'],
            'type' => ['required', 'string'],
            'tokens' => ['nullable', 'integer'],
            'plan' => ['nullable', 'string'],
            'planId' => ['nullable', 'string'],
            'name' => ['nullable', 'string'],
            'callbackUrl' => ['nullable', 'url'],
        ]);

        try {
            return response()->json($this->paymentService->initialize($data));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string']]);

        try {
            return response()->json($this->paymentService->verifyAndCredit($data['reference']));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }

    public function verifyFromPath(string $reference): JsonResponse
    {
        try {
            return response()->json($this->paymentService->verifyAndCredit($reference, 'verifyPaymentApi'));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }

    public function adminVerifyReference(Request $request): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string']]);

        try {
            return response()->json($this->paymentService->adminVerifyReference($data['reference']));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }

    public function webhook(Request $request): JsonResponse
    {
        $signature = $request->header('x-paystack-signature');
        $rawPayload = $request->getContent();

        if (! $this->paystackService->verifyWebhookSignature($rawPayload, $signature)) {
            return response()->json(['error' => 'Invalid webhook signature'], 401);
        }

        $event = $request->json()->all();
        $eventType = $event['event'] ?? null;

        try {
            if ($eventType === 'charge.success') {
                $reference = (string) ($event['data']['reference'] ?? '');
                if ($reference !== '') {
                    $this->paymentService->verifyAndCredit($reference, 'webhook');
                }
            }

            return response()->json(['message' => 'Webhook processed']);
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 500);
        }
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'userId' => ['nullable', 'string'],
            'email' => ['required', 'email'],
            'reference' => ['required', 'string'],
            'amount' => ['nullable', 'integer'],
            'tokens' => ['nullable', 'integer'],
            'description' => ['nullable', 'string'],
        ]);

        try {
            return response()->json($this->paymentService->report($data));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }

    public function extractWeddingEntities(Request $request): JsonResponse
    {
        try {
            return response()->json($this->nlpService->extractWeddingEntities($request->json()->all()));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }
}