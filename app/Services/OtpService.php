<?php

namespace App\Services;

use App\Models\OtpVerification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class OtpService
{
    public function send(string $email, string $ipAddress): array
    {
        $email = strtolower(trim($email));

        $windowStart = now()->subMinutes((int) config('plutod.otp.window_minutes', 10));
        $sentRecently = OtpVerification::query()
            ->where('email', $email)
            ->where('created_at', '>=', $windowStart)
            ->count();

        if ($sentRecently >= (int) config('plutod.otp.max_per_email_per_window', 5)) {
            throw new RuntimeException('Too many verification code requests. Please wait a few minutes and try again.');
        }

        OtpVerification::query()
            ->where('email', $email)
            ->where('verified', false)
            ->delete();

        $otp = (string) random_int(100000, 999999);
        $record = OtpVerification::query()->create([
            'id' => (string) Str::ulid(),
            'email' => $email,
            'otp_hash' => hash('sha256', $otp),
            'expires_at' => now()->addMinutes((int) config('plutod.otp.expiry_minutes', 5)),
            'verified' => false,
            'attempts' => 0,
            'ip_address' => $ipAddress,
        ]);

        Mail::raw("Your PlutoD verification code is {$otp}. It expires in 5 minutes.", function ($message) use ($email) {
            $message->to($email)->subject('Your PlutoD verification code');
        });

        $response = [
            'success' => true,
            'message' => 'Verification code sent successfully.',
            'expiresInSeconds' => 300,
        ];

        if (config('app.debug')) {
            $response['_devOtp'] = $otp;
            $response['_recordId'] = $record->id;
        }

        return $response;
    }

    public function verify(string $email, string $otp): array
    {
        $email = strtolower(trim($email));
        $record = OtpVerification::query()
            ->where('email', $email)
            ->latest()
            ->first();

        if (! $record) {
            throw new RuntimeException('No verification code found for this email.');
        }

        if ($record->verified) {
            return ['success' => true, 'message' => 'Verification already completed.'];
        }

        if ($record->expires_at?->isPast()) {
            throw new RuntimeException('Verification code has expired. Please request a new one.');
        }

        if ($record->attempts >= (int) config('plutod.otp.max_attempts', 5)) {
            throw new RuntimeException('Too many invalid attempts. Please request a new verification code.');
        }

        $hashedOtp = hash('sha256', trim($otp));

        if (! hash_equals($record->otp_hash, $hashedOtp)) {
            $record->increment('attempts');
            $remaining = max(0, (int) config('plutod.otp.max_attempts', 5) - $record->attempts);

            throw new RuntimeException($remaining > 0
                ? "Invalid verification code. {$remaining} attempt(s) remaining."
                : 'Too many invalid attempts. Please request a new verification code.');
        }

        $record->forceFill([
            'verified' => true,
            'verified_at' => now(),
        ])->save();

        return ['success' => true, 'message' => 'Verification successful.'];
    }
}