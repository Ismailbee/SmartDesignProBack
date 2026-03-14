<?php

namespace App\Services;

use App\Models\ApiAccessToken;
use App\Models\ApiRefreshToken;
use App\Models\User;
use Illuminate\Support\Str;

class ApiTokenService
{
    public function issueTokens(User $user, string $deviceName = 'plutod-web'): array
    {
        $accessToken = 'ptd_at_'.Str::random(64);
        $refreshToken = 'ptd_rt_'.Str::random(64);

        ApiAccessToken::query()->create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $accessToken),
            'name' => $deviceName,
            'abilities' => ['*'],
            'expires_at' => now()->addMinutes((int) env('AUTH_ACCESS_TOKEN_TTL_MINUTES', 120)),
        ]);

        ApiRefreshToken::query()->create([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $refreshToken),
            'expires_at' => now()->addDays((int) env('AUTH_REFRESH_TOKEN_TTL_DAYS', 30)),
        ]);

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
        ];
    }

    public function resolveAccessToken(?string $token): ?User
    {
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $record = ApiAccessToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with('user')
            ->first();

        if (! $record || ! $record->user) {
            return null;
        }

        $record->forceFill(['last_used_at' => now()])->save();
        $record->user->forceFill(['last_active_at' => now()])->save();

        return $record->user->refresh();
    }

    public function refresh(string $refreshToken, string $deviceName = 'plutod-web'): ?array
    {
        $record = ApiRefreshToken::query()
            ->where('token_hash', hash('sha256', $refreshToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->with('user')
            ->first();

        if (! $record || ! $record->user) {
            return null;
        }

        $record->forceFill([
            'revoked_at' => now(),
            'last_used_at' => now(),
        ])->save();

        return [
            'user' => $record->user->refresh(),
            ...$this->issueTokens($record->user, $deviceName),
        ];
    }

    public function revokeRefreshToken(?string $refreshToken): void
    {
        if (! is_string($refreshToken) || trim($refreshToken) === '') {
            return;
        }

        ApiRefreshToken::query()
            ->where('token_hash', hash('sha256', $refreshToken))
            ->update(['revoked_at' => now()]);
    }

    public function revokeAllForUser(User $user): void
    {
        ApiAccessToken::query()->where('user_id', $user->id)->delete();
        ApiRefreshToken::query()->where('user_id', $user->id)->update(['revoked_at' => now()]);
    }
}