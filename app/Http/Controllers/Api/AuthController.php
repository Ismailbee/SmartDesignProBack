<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiTokenService;
use App\Services\OtpService;
use App\Services\PasswordResetService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use RuntimeException;

class AuthController extends Controller
{
    public function __construct(
        private readonly ApiTokenService $apiTokenService,
        private readonly OtpService $otpService,
        private readonly PasswordResetService $passwordResetService,
        private readonly UserService $userService,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'username' => ['nullable', 'string', 'max:255'],
            'firstName' => ['nullable', 'string', 'max:255'],
            'lastName' => ['nullable', 'string', 'max:255'],
        ]);

        $name = trim(implode(' ', array_filter([$data['firstName'] ?? null, $data['lastName'] ?? null])));

        $user = User::query()->create([
            'email' => strtolower(trim($data['email'])),
            'password' => $data['password'],
            'name' => $name !== '' ? $name : ($data['username'] ?? strstr($data['email'], '@', true)),
            'role' => 'user',
            'status' => 'active',
            'plan' => config('plutod.plans.0.name', 'Basic'),
            'tokens' => (int) config('plutod.starter_tokens', 16),
            'referral_code' => $this->userService->generateReferralCode(),
            'last_active_at' => now(),
            'email_verified_at' => now(),
        ]);

        $this->userService->logActivity($user, 'user_registered', 'User registered with backend auth', [
            'source' => 'laravel-auth',
        ]);

        return response()->json([
            'message' => 'Registration successful.',
            'user' => $this->userService->toApiArray($user),
            ...$this->apiTokenService->issueTokens($user, (string) $request->userAgent()),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();

        if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        if ($user->status === 'suspended') {
            return response()->json(['message' => 'This account has been suspended.'], 403);
        }

        $user->forceFill(['last_active_at' => now()])->save();

        return response()->json([
            'message' => 'Login successful.',
            'user' => $this->userService->toApiArray($user),
            ...$this->apiTokenService->issueTokens($user, (string) $request->userAgent()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refreshToken' => ['nullable', 'string'],
        ]);

        $token = $request->bearerToken();

        if ($token) {
            $user = $this->apiTokenService->resolveAccessToken($token);

            if ($user) {
                $this->apiTokenService->revokeAllForUser($user);
            }
        }

        $this->apiTokenService->revokeRefreshToken($data['refreshToken'] ?? null);

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'refreshToken' => ['required', 'string'],
        ]);

        $refreshed = $this->apiTokenService->refresh($data['refreshToken'], (string) $request->userAgent());

        if (! $refreshed) {
            return response()->json(['message' => 'Invalid or expired refresh token.'], 401);
        }

        return response()->json([
            'user' => $this->userService->toApiArray($refreshed['user']),
            'accessToken' => $refreshed['accessToken'],
            'refreshToken' => $refreshed['refreshToken'],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'user' => $this->userService->toApiArray($user),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8'],
        ]);

        if (! Hash::check($data['currentPassword'], (string) $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->forceFill(['password' => $data['newPassword']])->save();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'firstName' => ['nullable', 'string', 'max:255'],
            'lastName' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'string'],
        ]);

        $name = trim((string) ($data['name'] ?? implode(' ', array_filter([$data['firstName'] ?? null, $data['lastName'] ?? null]))));

        $user->forceFill([
            'name' => $name !== '' ? $name : $user->name,
            'avatar' => $data['avatar'] ?? $user->avatar,
            'last_active_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->userService->toApiArray($user->refresh()),
        ]);
    }

    public function sendLoginOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        try {
            return response()->json($this->otpService->send($data['email'], $request->ip() ?? 'unknown'));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 429);
        }
    }

    public function verifyLoginOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string'],
        ]);

        try {
            return response()->json($this->otpService->verify($data['email'], $data['otp']));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }

    public function sendPasswordReset(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        return response()->json($this->passwordResetService->request($data['email'], $request->ip() ?? 'unknown'));
    }

    public function confirmPasswordReset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'newPassword' => ['required', 'string', 'min:8'],
        ]);

        $status = Password::broker()->reset([
            'email' => strtolower(trim($data['email'])),
            'token' => $data['token'],
            'password' => $data['newPassword'],
            'password_confirmation' => $data['newPassword'],
        ], function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }
}