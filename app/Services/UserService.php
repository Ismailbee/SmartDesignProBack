<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Template;
use App\Models\User;
use Illuminate\Support\Str;

class UserService
{
    public function __construct(private readonly AppSettingService $appSettingService)
    {
    }

    public function findByApiId(string $apiId): ?User
    {
        return User::query()
            ->where('firebase_uid', $apiId)
            ->orWhere('id', ctype_digit($apiId) ? (int) $apiId : -1)
            ->first();
    }

    public function findOrCreateByApiId(string $apiId, ?string $email = null, ?string $name = null): User
    {
        $user = $this->findByApiId($apiId);

        if ($user) {
            $updates = ['last_active_at' => now()];

            if ($email && $user->email !== $email) {
                $updates['email'] = strtolower(trim($email));
            }

            if ($name && $user->name !== $name) {
                $updates['name'] = trim($name);
            }

            $user->fill($updates)->save();

            return $user->refresh();
        }

        $settings = $this->appSettingService->get();
        $starterTokens = (int) config('plutod.starter_tokens', 16);

        $user = User::query()->create([
            'firebase_uid' => $apiId,
            'email' => strtolower(trim((string) $email)) ?: sprintf('%s@plutod.local', $apiId),
            'name' => $name ? trim($name) : 'PlutoD User',
            'role' => 'user',
            'status' => 'active',
            'plan' => $settings->default_user_plan,
            'tokens' => $starterTokens,
            'referral_code' => $this->generateReferralCode(),
            'last_active_at' => now(),
        ]);

        $this->logActivity($user, 'user_registered', 'New user registered', [
            'source' => 'laravel-backend',
            'starterTokens' => $starterTokens,
        ]);

        return $user;
    }

    public function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (User::query()->where('referral_code', $code)->exists());

        return $code;
    }

    public function toApiArray(User $user): array
    {
        [$firstName, $lastName] = $this->splitName($user->name);

        return [
            'id' => $user->api_id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => strstr($user->email, '@', true) ?: $user->name,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'avatar' => $user->avatar,
            'plan' => $user->plan,
            'planExpiryDate' => optional($user->plan_expiry_at)?->toIso8601String(),
            'planExpiry' => optional($user->plan_expiry_at)?->toIso8601String(),
            'tokens' => (int) $user->tokens,
            'adminCreditTokens' => (int) $user->admin_credit_tokens,
            'paidTokensBalance' => max(0, (int) $user->tokens - (int) $user->admin_credit_tokens),
            'totalDesignsGenerated' => (int) $user->total_designs_generated,
            'referralCode' => $user->referral_code,
            'referredBy' => $user->referred_by,
            'referralCount' => (int) $user->total_referrals,
            'status' => $user->status,
            'role' => $user->role,
            'emailVerified' => $user->email_verified_at !== null,
            'googleId' => $user->google_id,
            'hasGoogleProvider' => filled($user->google_id),
            'hasPasswordProvider' => filled($user->password),
            'lastActiveAt' => optional($user->last_active_at)?->toIso8601String(),
            'createdAt' => optional($user->created_at)?->toIso8601String(),
            'updatedAt' => optional($user->updated_at)?->toIso8601String(),
        ];
    }

    public function toAdminArray(User $user): array
    {
        return [
            'id' => $user->api_id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'role' => $user->role,
            'status' => $user->status,
            'plan' => $user->plan,
            'joinedDate' => optional($user->created_at)?->toIso8601String(),
            'lastActive' => optional($user->last_active_at ?? $user->updated_at)?->toIso8601String(),
            'designsCreated' => (int) $user->total_designs_generated,
            'templatesUploaded' => Template::query()->where('creator_id', $user->id)->count(),
            'totalSpent' => (int) $user->transactions()->where('status', 'success')->sum('amount'),
            'tokens' => (int) $user->tokens,
            'adminCreditTokens' => (int) $user->admin_credit_tokens,
            'paidTokensBalance' => max(0, (int) $user->tokens - (int) $user->admin_credit_tokens),
            'suspended' => $user->status === 'suspended',
            'planExpiryDate' => optional($user->plan_expiry_at)?->toIso8601String(),
        ];
    }

    public function logActivity(?User $user, string $type, string $description, array $metadata = []): Activity
    {
        return Activity::query()->create([
            'id' => (string) Str::ulid(),
            'user_id' => $user?->id,
            'type' => $type,
            'description' => $description,
            'metadata' => $metadata,
            'timestamp' => now(),
        ]);
    }

    private function splitName(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [];

        return [
            $parts[0] ?? null,
            $parts[1] ?? null,
        ];
    }
}
