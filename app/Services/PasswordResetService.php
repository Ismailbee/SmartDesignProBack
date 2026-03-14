<?php

namespace App\Services;

use App\Models\PasswordResetRequestLog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetService
{
    public function request(string $email, string $ipAddress): array
    {
        $email = strtolower(trim($email));
        $this->log($email, $ipAddress, 'requested');

        $windowStart = now()->subHour();

        $emailCount = PasswordResetRequestLog::query()
            ->where('email', $email)
            ->where('created_at', '>=', $windowStart)
            ->count();

        $ipCount = PasswordResetRequestLog::query()
            ->where('ip_address', $ipAddress)
            ->where('created_at', '>=', $windowStart)
            ->count();

        if ($emailCount >= 5 || $ipCount >= 20) {
            $this->log($email, $ipAddress, 'rate_limited');

            return [
                'success' => true,
                'message' => 'If an account exists for that email, a reset link has been sent.',
            ];
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->log($email, $ipAddress, 'missing_user');

            return [
                'success' => true,
                'message' => 'If an account exists for that email, a reset link has been sent.',
            ];
        }

        $token = Password::broker()->createToken($user);
        $link = rtrim((string) config('plutod.frontend_url'), '/').'/reset-password?token='.urlencode($token).'&email='.urlencode($email);

        Mail::html(
            '<p>We received a request to reset your PlutoD password.</p><p><a href="'.$link.'">Reset your password</a></p><p>If you did not request this, you can ignore this email.</p>',
            function ($message) use ($email) {
                $message->to($email)->subject('Reset Your PlutoD Password');
            }
        );

        $this->log($email, $ipAddress, 'sent');

        return [
            'success' => true,
            'message' => 'If an account exists for that email, a reset link has been sent.',
        ];
    }

    private function log(string $email, string $ipAddress, string $status): void
    {
        PasswordResetRequestLog::query()->create([
            'id' => (string) Str::ulid(),
            'email' => $email,
            'ip_address' => $ipAddress,
            'status' => $status,
        ]);
    }
}