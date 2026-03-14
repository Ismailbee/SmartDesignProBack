<?php

namespace App\Http\Middleware;

use App\Services\ApiTokenService;
use App\Services\FirebaseTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFirebaseToken
{
    public function __construct(
        private readonly FirebaseTokenService $firebaseTokenService,
        private readonly ApiTokenService $apiTokenService,
    )
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return new JsonResponse(['error' => 'No auth token'], 401);
        }

        $apiUser = $this->apiTokenService->resolveAccessToken($token);

        if ($apiUser) {
            $request->attributes->set('auth_user', $apiUser);

            return $next($request);
        }

        $claims = $this->firebaseTokenService->verify($token);

        if (! $claims) {
            return new JsonResponse(['error' => 'Invalid auth token'], 401);
        }

        $request->attributes->set('firebase_claims', $claims);

        return $next($request);
    }
}