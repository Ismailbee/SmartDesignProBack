<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class FirebaseTokenService
{
    public function verify(string $idToken): ?array
    {
        if ($idToken === '') {
            return null;
        }

        $response = Http::timeout(10)
            ->acceptJson()
            ->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);

        if (! $response->ok()) {
            return null;
        }

        $tokenInfo = $response->json();
        $payload = $this->decodePayload($idToken);
        $claims = array_merge(is_array($payload) ? $payload : [], is_array($tokenInfo) ? $tokenInfo : []);

        $expectedProjectId = (string) config('plutod.firebase_project_id');
        $audience = (string) Arr::get($claims, 'aud', '');

        if ($expectedProjectId !== '' && $audience !== '' && $audience !== $expectedProjectId) {
            return null;
        }

        return $claims;
    }

    public function isAdmin(array $claims): bool
    {
        $email = strtolower((string) Arr::get($claims, 'email', ''));
        $knownAdmins = array_map('strtolower', (array) config('plutod.known_admin_emails', []));

        return Arr::get($claims, 'admin') === true
            || Arr::get($claims, 'role') === 'admin'
            || in_array($email, $knownAdmins, true);
    }

    private function decodePayload(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) < 2) {
            return [];
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'));

        if ($payload === false) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}