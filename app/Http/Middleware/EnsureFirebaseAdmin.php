<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ApiTokenService;
use App\Services\FirebaseTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFirebaseAdmin
{
    public function __construct(
        private readonly FirebaseTokenService $firebaseTokenService,
        private readonly ApiTokenService $apiTokenService,
    )
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $authUser = $request->attributes->get('auth_user');

        if ($authUser instanceof User) {
            if (! in_array($authUser->role, ['admin', 'moderator'], true)) {
                return new JsonResponse(['error' => 'Admin privileges required'], 403);
            }

            return $next($request);
        }

        $claims = $request->attributes->get('firebase_claims');

        if (! is_array($claims)) {
            $token = $request->bearerToken();

            if (! $token) {
                return new JsonResponse(['error' => 'No auth token'], 401);
            }

            $apiUser = $this->apiTokenService->resolveAccessToken($token);

            if ($apiUser instanceof User) {
                $request->attributes->set('auth_user', $apiUser);

                if (! in_array($apiUser->role, ['admin', 'moderator'], true)) {
                    return new JsonResponse(['error' => 'Admin privileges required'], 403);
                }

                return $next($request);
            }

            $claims = $this->firebaseTokenService->verify($token);

            if (! $claims) {
                return new JsonResponse(['error' => 'Invalid auth token'], 401);
            }

            $request->attributes->set('firebase_claims', $claims);
        }

        if (! $this->firebaseTokenService->isAdmin($claims)) {
            return new JsonResponse(['error' => 'Admin privileges required'], 403);
        }

        return $next($request);
    }
}