<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class NlpService
{
    public function extractWeddingEntities(array $payload): array
    {
        $baseUrl = trim((string) env('RAILWAY_NLP_BASE_URL', ''));
        $apiKey = trim((string) env('RAILWAY_NLP_API_KEY', ''));

        if ($baseUrl === '') {
            throw new RuntimeException('RAILWAY_NLP_BASE_URL is not configured');
        }

        $request = Http::baseUrl($baseUrl)->acceptJson()->timeout(30);

        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        $response = $request->post('/extract', $payload);

        if (! $response->ok()) {
            throw new RuntimeException($response->json('message') ?: 'Failed to extract wedding entities');
        }

        return $response->json();
    }
}