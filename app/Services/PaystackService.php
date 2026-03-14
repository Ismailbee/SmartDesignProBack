<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaystackService
{
    public function initialize(array $payload): array
    {
        $response = $this->client()->post('/transaction/initialize', $payload);

        if (! $response->ok() || ! $response->json('status')) {
            throw new RuntimeException($response->json('message') ?: 'Failed to initialize payment');
        }

        return (array) $response->json('data');
    }

    public function verifyReference(string $reference): array
    {
        $response = $this->client()->get('/transaction/verify/'.rawurlencode($reference));

        if (! $response->ok() || ! $response->json('status')) {
            throw new RuntimeException($response->json('message') ?: 'Failed to verify payment');
        }

        return (array) $response->json('data');
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        $secret = (string) config('services.paystack.secret_key');

        if ($secret === '' || ! $signature) {
            return false;
        }

        $computed = hash_hmac('sha512', $rawPayload, $secret);

        return hash_equals($computed, $signature);
    }

    public function normalizeMetadata(array $payload): array
    {
        $metadata = (array) Arr::get($payload, 'metadata', []);
        $normalized = $metadata;

        if (isset($metadata['metadata']) && is_array($metadata['metadata'])) {
            $normalized = array_merge($metadata['metadata'], $normalized);
        }

        foreach ((array) Arr::get($metadata, 'custom_fields', []) as $field) {
            $key = $field['variable_name'] ?? $field['display_name'] ?? null;
            if ($key) {
                $normalized[$key] = $field['value'] ?? null;
            }
        }

        return $normalized;
    }

    public function sanitizeCallbackUrl(?string $callbackUrl): string
    {
        $candidate = trim((string) $callbackUrl);

        if ($candidate === '') {
            return (string) config('plutod.callback_url');
        }

        $parts = parse_url($candidate);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true))) {
            return $candidate;
        }

        return (string) config('plutod.callback_url');
    }

    private function client()
    {
        $secret = (string) config('services.paystack.secret_key');

        if ($secret === '') {
            throw new RuntimeException('PAYSTACK_SECRET_KEY is not configured');
        }

        return Http::baseUrl((string) config('services.paystack.base_url'))
            ->acceptJson()
            ->withToken($secret)
            ->timeout(20);
    }
}