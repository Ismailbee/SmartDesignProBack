<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ReferralService
{
    public function __construct(
        private readonly UserService $userService,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function validate(string $referralCode): array
    {
        $code = strtoupper(trim($referralCode));
        $referrer = User::query()->where('referral_code', $code)->first();

        if (! $referrer) {
            return ['valid' => false, 'error' => 'Referral code not found'];
        }

        return [
            'valid' => true,
            'referrer' => [
                'id' => $referrer->api_id,
                'name' => $referrer->name,
            ],
            'referrerName' => $referrer->name,
            'referrerEmail' => $referrer->email,
        ];
    }

    public function apply(string $code, string $userId, string $email, ?string $name = null): array
    {
        $code = strtoupper(trim($code));
        $referrer = User::query()->where('referral_code', $code)->first();

        if (! $referrer) {
            throw new RuntimeException('Invalid referral code');
        }

        $referee = $this->userService->findOrCreateByApiId($userId, $email, $name);

        if ($referee->id === $referrer->id || $referee->referral_code === $referrer->referral_code) {
            throw new RuntimeException('You cannot apply your own referral code');
        }

        if ($referee->referred_by) {
            throw new RuntimeException('A referral code has already been applied to this account');
        }

        $existing = Referral::query()
            ->where('referred_user_id', $referee->id)
            ->first();

        if ($existing) {
            throw new RuntimeException('A referral code has already been applied to this account');
        }

        $referrerBonus = (int) config('plutod.referrals.referrer_bonus', 500);
        $refereeBonus = (int) config('plutod.referrals.referee_bonus', 750);

        DB::transaction(function () use ($referrer, $referee, $code, $referrerBonus, $refereeBonus) {
            $referrer->increment('tokens', $referrerBonus);
            $referrer->increment('total_referrals');

            $referee->forceFill([
                'referred_by' => $code,
                'tokens' => $referee->tokens + $refereeBonus,
            ])->save();

            Referral::query()->create([
                'id' => (string) Str::ulid(),
                'referrer_id' => $referrer->id,
                'referred_user_id' => $referee->id,
                'referred_user_email' => $referee->email,
                'referred_user_name' => $referee->name,
                'referral_code' => $code,
                'tokens_awarded' => [
                    'referrer' => $referrerBonus,
                    'referred' => $refereeBonus,
                ],
                'status' => 'completed',
            ]);
        });

        $this->notificationService->createForUser(
            $referrer,
            'Referral reward earned',
            "You received {$referrerBonus} tokens for referring {$referee->name}.",
            'tokens_credited'
        );

        return [
            'success' => true,
            'message' => 'Referral code applied successfully',
            'tokensAwarded' => $refereeBonus,
            'rewards' => [
                'referrer' => [
                    'id' => $referrer->api_id,
                    'name' => $referrer->name,
                    'tokensAwarded' => $referrerBonus,
                ],
                'referee' => [
                    'id' => $referee->api_id,
                    'tokensAwarded' => $refereeBonus,
                ],
            ],
        ];
    }

    public function stats(User $user): array
    {
        $referrals = Referral::query()
            ->where('referrer_id', $user->id)
            ->latest()
            ->get();

        $count = $referrals->count();
        $totalEarned = (int) $referrals->sum(fn (Referral $referral) => (int) ($referral->tokens_awarded['referrer'] ?? 0));
        $tier = $this->currentTier($count);
        $nextMilestone = $this->nextMilestone($count);

        return [
            'referralCode' => $user->referral_code,
            'referralCount' => $count,
            'totalReferrals' => $count,
            'totalTokensEarned' => $totalEarned,
            'tier' => $tier,
            'nextMilestone' => $nextMilestone,
            'referrals' => $referrals->map(fn (Referral $referral) => [
                'id' => $referral->id,
                'referredName' => $referral->referred_user_name,
                'tokensAwarded' => (int) ($referral->tokens_awarded['referrer'] ?? 0),
                'createdAt' => optional($referral->created_at)?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function currentTier(int $count): string
    {
        $current = 'starter';

        foreach ((array) config('plutod.referrals.tiers', []) as $tier) {
            if ($count >= (int) ($tier['threshold'] ?? 0)) {
                $current = (string) ($tier['name'] ?? $current);
            }
        }

        return $current;
    }

    private function nextMilestone(int $count): ?array
    {
        foreach ((array) config('plutod.referrals.tiers', []) as $tier) {
            $threshold = (int) ($tier['threshold'] ?? 0);

            if ($threshold > $count) {
                return ['tier' => $tier['name'], 'threshold' => $threshold];
            }
        }

        return null;
    }
}