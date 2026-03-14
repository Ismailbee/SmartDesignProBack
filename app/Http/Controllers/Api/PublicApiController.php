<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Services\AppSettingService;
use App\Services\NotificationService;
use App\Services\PaymentService;
use App\Services\ReferralService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PublicApiController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
        private readonly NotificationService $notificationService,
        private readonly ReferralService $referralService,
        private readonly PaymentService $paymentService,
        private readonly AppSettingService $appSettingService,
    ) {
    }

    public function showUser(Request $request, string $userId): JsonResponse
    {
        $user = $this->userService->findOrCreateByApiId(
            $userId,
            $request->query('email'),
            $request->query('name')
        );

        return response()->json($this->userService->toApiArray($user));
    }

    public function deductTokens(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string'],
        ]);

        $user = $this->userService->findByApiId($userId);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if ($user->tokens < $data['amount']) {
            return response()->json(['error' => 'Insufficient tokens'], 400);
        }

        $user->forceFill([
            'tokens' => $user->tokens - $data['amount'],
            'total_designs_generated' => $user->total_designs_generated + 1,
            'last_active_at' => now(),
        ])->save();

        $this->userService->logActivity($user, 'design_created', 'Tokens deducted for design generation', [
            'tokensDeducted' => $data['amount'],
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'newBalance' => (int) $user->tokens,
            'deducted' => (int) $data['amount'],
            'reason' => $data['reason'] ?? null,
            'totalDesignsGenerated' => (int) $user->total_designs_generated,
            'tokens' => (int) $user->tokens,
        ]);
    }

    public function trackFeature(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate(['feature' => ['required', 'string']]);
        $user = $this->userService->findByApiId($userId);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $user->forceFill([
            'last_feature_used' => $data['feature'],
            'last_active_at' => now(),
        ])->save();

        $this->userService->logActivity($user, 'feature_used', 'Feature usage tracked', ['feature' => $data['feature']]);

        return response()->json(['success' => true]);
    }

    public function notifications(string $userId): JsonResponse
    {
        $user = $this->userService->findByApiId($userId);

        if (! $user) {
            return response()->json(['notifications' => []]);
        }

        $notifications = $this->notificationService->latestForUser($user)
            ->map(fn (Notification $notification) => $this->notificationService->toArray($notification, $user))
            ->values()
            ->all();

        return response()->json(['notifications' => $notifications]);
    }

    public function markNotificationRead(string $userId, string $notificationId): JsonResponse
    {
        $user = $this->userService->findByApiId($userId);

        if (! $user) {
            return response()->json(['success' => false], 404);
        }

        $notification = Notification::query()
            ->where('id', $notificationId)
            ->where('user_id', $user->id)
            ->first();

        if (! $notification) {
            return response()->json(['success' => false], 404);
        }

        $notification->forceFill(['read' => true, 'read_at' => now()])->save();

        return response()->json(['success' => true]);
    }

    public function markAllNotificationsRead(string $userId): JsonResponse
    {
        $user = $this->userService->findByApiId($userId);

        if (! $user) {
            return response()->json(['success' => false], 404);
        }

        Notification::query()
            ->where('user_id', $user->id)
            ->where('read', false)
            ->update([
                'read' => true,
                'read_at' => now(),
            ]);

        return response()->json(['success' => true]);
    }

    public function userPayments(Request $request, string $userId): JsonResponse
    {
        $user = $this->userService->findByApiId($userId);

        if (! $user) {
            return response()->json(['payments' => [], 'pagination' => ['page' => 1, 'limit' => 20, 'total' => 0, 'totalPages' => 1]]);
        }

        return response()->json($this->paymentService->userPayments($user, $request->query()));
    }

    public function validateReferral(Request $request): JsonResponse
    {
        $data = $request->validate(['referralCode' => ['required', 'string']]);

        return response()->json($this->referralService->validate($data['referralCode']));
    }

    public function applyReferral(Request $request): JsonResponse
    {
        $data = $request->validate([
            'referralCode' => ['required', 'string'],
            'userId' => ['required', 'string'],
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string'],
        ]);

        try {
            return response()->json($this->referralService->apply(
                $data['referralCode'],
                $data['userId'],
                $data['email'],
                $data['name'] ?? null
            ));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }

    public function referralStats(string $userId): JsonResponse
    {
        $user = $this->userService->findByApiId($userId);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        return response()->json($this->referralService->stats($user));
    }

    public function referralCode(string $userId): JsonResponse
    {
        $user = $this->userService->findByApiId($userId);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        return response()->json(['referralCode' => $user->referral_code]);
    }

    public function subscriptionPlans(): JsonResponse
    {
        $plans = array_values(array_map(function (array $plan) {
            return [
                'id' => $plan['id'],
                'name' => $plan['name'],
                'price' => $plan['price'],
                'duration' => $plan['duration'],
                'features' => $plan['features'],
                'tokenBonus' => $plan['token_bonus'],
                'color' => $plan['color'],
                'icon' => $plan['icon'],
                'popular' => $plan['popular'] ?? false,
                'recommended' => $plan['recommended'] ?? false,
            ];
        }, config('plutod.plans', [])));

        return response()->json(['plans' => $plans]);
    }

    public function subscriptionStatus(string $userId): JsonResponse
    {
        $user = $this->userService->findByApiId($userId);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $isExpired = $user->plan_expiry_at ? $user->plan_expiry_at->isPast() : false;

        return response()->json([
            'plan' => $user->plan,
            'planExpiryDate' => optional($user->plan_expiry_at)?->toIso8601String(),
            'isExpired' => $isExpired,
            'daysRemaining' => $user->plan_expiry_at ? now()->diffInDays($user->plan_expiry_at, false) : null,
            'canUpgrade' => $user->plan !== 'Premium' || $isExpired,
        ]);
    }

    public function maintenanceStatus(): JsonResponse
    {
        return response()->json($this->appSettingService->maintenanceStatus());
    }

    public function logActivity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'userId' => ['nullable', 'string'],
            'type' => ['required', 'string'],
            'description' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $user = isset($data['userId']) ? $this->userService->findByApiId($data['userId']) : null;
        $this->userService->logActivity($user, $data['type'], $data['description'], $data['metadata'] ?? []);

        return response()->json(['success' => true]);
    }
}