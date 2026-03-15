<?php

namespace App\Services;

use App\Models\PaymentReport;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentService
{
    public function __construct(
        private readonly PaystackService $paystackService,
        private readonly UserService $userService,
        private readonly NotificationService $notificationService,
        private readonly AppSettingService $appSettingService,
    ) {
    }

    public function initialize(array $input): array
    {
        $amount = (int) ($input['amount'] ?? 0);

        if ($amount < 100 || $amount > 10000000) {
            throw new RuntimeException('Amount must be between 100 and 10000000 NGN');
        }

        $userId = trim((string) ($input['userId'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));

        if ($userId === '' || $email === '') {
            throw new RuntimeException('userId and email are required');
        }

        $type = (string) ($input['type'] ?? 'token_purchase');
        $reference = 'PLUTOD_'.now()->format('YmdHis').'_'.strtoupper(Str::random(8));
        $callbackUrl = $this->paystackService->sanitizeCallbackUrl($input['callbackUrl'] ?? null);
        $metadata = [
            'userId' => $userId,
            'type' => $type,
            'tokens' => $input['tokens'] ?? null,
            'plan' => $input['plan'] ?? null,
            'planId' => $input['planId'] ?? null,
            'name' => $input['name'] ?? null,
            'custom_fields' => [
                ['display_name' => 'User ID', 'variable_name' => 'userId', 'value' => $userId],
                ['display_name' => 'Payment Type', 'variable_name' => 'type', 'value' => $type],
            ],
        ];

        $response = $this->paystackService->initialize([
            'email' => $email,
            'amount' => $amount * 100,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]);

        return [
            'authorization_url' => $response['authorization_url'] ?? null,
            'access_code' => $response['access_code'] ?? null,
            'reference' => $response['reference'] ?? $reference,
        ];
    }

    public function verifyAndCredit(string $reference, string $source = 'verifyPayment'): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,100}$/', $reference)) {
            throw new RuntimeException('Invalid payment reference');
        }

        $paystackData = $this->paystackService->verifyReference($reference);

        if (($paystackData['status'] ?? null) !== 'success') {
            throw new RuntimeException('Payment has not been completed successfully');
        }

        $transaction = DB::transaction(function () use ($paystackData, $source) {
            $reference = (string) ($paystackData['reference'] ?? '');
            $existing = Transaction::query()->where('reference', $reference)->first();

            if ($existing) {
                return $existing;
            }

            $metadata = $this->paystackService->normalizeMetadata($paystackData);
            $user = $this->resolveUserFromPayment($metadata, $paystackData);
            $type = (string) ($metadata['type'] ?? 'purchase');
            $tokens = isset($metadata['tokens']) ? (int) $metadata['tokens'] : null;
            $plan = $metadata['plan'] ?? null;
            $amount = (int) round(((int) ($paystackData['amount'] ?? 0)) / 100);

            if ($type === 'token_purchase') {
                $user->increment('tokens', max(0, $tokens ?? $amount));
            }

            if ($type === 'plan_upgrade') {
                $settings = $this->appSettingService->get();
                $pricing = $settings->pricing['Premium'] ?? config('plutod.plans.premium', []);
                $user->forceFill([
                    'plan' => $plan ?: 'Premium',
                    'plan_expiry_at' => now()->addMonths((int) ($pricing['durationMonths'] ?? 2)),
                    'tokens' => $user->tokens + (int) ($pricing['tokens'] ?? 1000),
                ])->save();
            }

            $user->forceFill(['last_active_at' => now()])->save();

            $transaction = Transaction::query()->create([
                'id' => $reference,
                'reference' => $reference,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'amount' => $amount,
                'currency' => (string) ($paystackData['currency'] ?? 'NGN'),
                'type' => $type,
                'plan' => $plan,
                'tokens' => $tokens,
                'status' => 'success',
                'channel' => $paystackData['channel'] ?? null,
                'paid_at' => $paystackData['paid_at'] ?? now(),
                'verified_at' => now(),
                'source' => $source,
                'metadata' => $metadata,
            ]);

            $this->userService->logActivity($user, 'purchase', 'Payment verified', [
                'reference' => $reference,
                'type' => $type,
                'amount' => $amount,
                'tokens' => $tokens,
                'plan' => $plan,
                'source' => $source,
            ]);

            $this->notificationService->createForUser(
                $user,
                'Payment confirmed',
                $type === 'plan_upgrade'
                    ? 'Your plan upgrade has been applied successfully.'
                    : 'Your payment has been verified and tokens have been credited.',
                'payment_credited'
            );

            return $transaction;
        });

        return $this->verificationResponse($transaction);
    }

    public function adminVerifyReference(string $reference): array
    {
        $paystackData = $this->paystackService->verifyReference($reference);
        $internal = Transaction::query()->where('reference', $reference)->first();

        return [
            'success' => true,
            'paystackData' => $paystackData,
            'firestoreData' => $internal ? $this->transactionToArray($internal) : null,
        ];
    }

    public function userPayments(User $user, array $filters): array
    {
        $query = Transaction::query()
            ->where('user_id', $user->id)
            ->latest();

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['startDate'])) {
            $query->whereDate('created_at', '>=', $filters['startDate']);
        }

        if (! empty($filters['endDate'])) {
            $query->whereDate('created_at', '<=', $filters['endDate']);
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $paginator = $query->paginate($limit, ['*'], 'page', $page);

        return [
            'payments' => $paginator->getCollection()->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'amount' => (int) $transaction->amount,
                'type' => $transaction->type,
                'tokens' => $transaction->tokens,
                'plan' => $transaction->plan,
                'status' => $transaction->status,
                'reference' => $transaction->reference,
                'createdAt' => optional($transaction->created_at)?->toIso8601String(),
                'verifiedAt' => optional($transaction->verified_at)?->toIso8601String(),
            ])->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'limit' => $paginator->perPage(),
                'total' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ];
    }

    public function report(array $input): array
    {
        $reference = trim((string) ($input['reference'] ?? ''));

        if ($reference === '') {
            throw new RuntimeException('reference is required');
        }

        $existing = PaymentReport::query()->where('reference', $reference)->first();

        if ($existing) {
            return [
                'success' => true,
                'reportId' => $existing->id,
                'message' => 'Payment report already submitted',
                'alreadyReported' => true,
            ];
        }

        $user = isset($input['userId']) ? $this->userService->findByApiId((string) $input['userId']) : null;
        $report = PaymentReport::query()->create([
            'id' => (string) Str::ulid(),
            'user_id' => $user?->id,
            'email' => strtolower(trim((string) ($input['email'] ?? $user?->email ?? ''))),
            'reference' => $reference,
            'amount' => (int) ($input['amount'] ?? 0),
            'tokens' => isset($input['tokens']) ? (int) $input['tokens'] : null,
            'description' => $input['description'] ?? null,
            'status' => 'pending',
        ]);

        return [
            'success' => true,
            'reportId' => $report->id,
            'message' => 'Payment report submitted successfully',
        ];
    }

    public function creditTokens(User $user, int $tokens, ?string $reason, ?string $creditedBy = null): array
    {
        if ($tokens <= 0) {
            throw new RuntimeException('tokens must be greater than zero');
        }

        $previousTokens = (int) $user->tokens;
        $previousAdminCreditTokens = (int) $user->admin_credit_tokens;

        DB::transaction(function () use ($user, $tokens, $reason, $creditedBy) {
            $user->increment('tokens', $tokens);
            $user->increment('admin_credit_tokens', $tokens);

            Transaction::query()->create([
                'id' => 'ADMIN_CREDIT_'.Str::upper(Str::random(20)),
                'reference' => null,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'amount' => 0,
                'currency' => 'NGN',
                'type' => 'manual_credit',
                'tokens' => $tokens,
                'status' => 'success',
                'verified_at' => now(),
                'source' => 'admin',
                'credited_by' => $creditedBy,
                'reason' => $reason,
                'metadata' => ['tokens' => $tokens, 'reason' => $reason],
            ]);
        });

        $user->refresh();

        $this->notificationService->createForUser(
            $user,
            'Tokens credited',
            "{$tokens} tokens have been added to your account.",
            'tokens_credited',
            $creditedBy,
            ['reason' => $reason]
        );

        return [
            'success' => true,
            'previousTokens' => $previousTokens,
            'newTokens' => (int) $user->tokens,
            'previousAdminCreditTokens' => $previousAdminCreditTokens,
            'newAdminCreditTokens' => (int) $user->admin_credit_tokens,
            'credited' => $tokens,
        ];
    }

    public function resolveReport(PaymentReport $report, string $action, ?string $adminNotes, ?string $adminId): array
    {
        if (! in_array($action, ['credit', 'reject'], true)) {
            throw new RuntimeException('action must be credit or reject');
        }

        if ($report->status !== 'pending') {
            throw new RuntimeException('This report has already been processed');
        }

        if ($action === 'credit' && $report->user_id) {
            $user = User::query()->find($report->user_id);

            if ($user && $report->tokens) {
                $this->creditTokens($user, (int) $report->tokens, 'Payment report resolution', $adminId);
            }
        }

        $report->forceFill([
            'status' => $action === 'credit' ? 'resolved' : 'rejected',
            'resolved_by' => $adminId,
            'resolved_at' => now(),
            'admin_notes' => $adminNotes,
        ])->save();

        if ($report->user_id) {
            $user = User::query()->find($report->user_id);
            if ($user) {
                $this->notificationService->createForUser(
                    $user,
                    $action === 'credit' ? 'Payment report resolved' : 'Payment report rejected',
                    $action === 'credit' ? 'Your payment report has been resolved and tokens credited.' : 'Your payment report was reviewed and rejected.',
                    $action === 'credit' ? 'payment_credited' : 'report_rejected',
                    $adminId,
                    ['reportId' => $report->id]
                );
            }
        }

        return ['success' => true, 'action' => $action, 'reportId' => $report->id];
    }

    public function paymentDetail(string $id): array
    {
        $transaction = Transaction::query()->findOrFail($id);

        return $this->transactionToArray($transaction);
    }

    public function refund(string $id, ?string $reason): array
    {
        $transaction = Transaction::query()->findOrFail($id);
        $transaction->forceFill([
            'status' => 'refunded',
            'refund_reason' => $reason,
            'refunded_at' => now(),
        ])->save();

        return ['success' => true, 'message' => 'Payment refunded successfully'];
    }

    public function transactionToArray(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'userId' => optional($transaction->user)->api_id,
            'userName' => $transaction->user_name,
            'userEmail' => $transaction->user_email,
            'amount' => (int) $transaction->amount,
            'currency' => $transaction->currency,
            'plan' => $transaction->plan,
            'status' => $transaction->status,
            'paymentMethod' => $transaction->channel ?? 'card',
            'transactionDate' => optional($transaction->paid_at ?? $transaction->created_at)?->toIso8601String(),
            'refundedAt' => optional($transaction->refunded_at)?->toIso8601String(),
            'refundReason' => $transaction->refund_reason,
            'reference' => $transaction->reference,
            'type' => $transaction->type,
            'tokens' => $transaction->tokens,
            'metadata' => $transaction->metadata ?? [],
        ];
    }

    private function resolveUserFromPayment(array $metadata, array $paystackData): User
    {
        $userId = trim((string) ($metadata['userId'] ?? $metadata['user_id'] ?? ''));
        $email = strtolower(trim((string) ($paystackData['customer']['email'] ?? $metadata['email'] ?? '')));
        $name = $metadata['name'] ?? ($paystackData['customer']['first_name'] ?? null);

        if ($userId !== '') {
            return $this->userService->findOrCreateByApiId($userId, $email, is_string($name) ? $name : null);
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            throw new RuntimeException('Unable to resolve user for payment');
        }

        return $user;
    }

    private function verificationResponse(Transaction $transaction): array
    {
        return [
            'success' => true,
            'message' => 'Payment verified successfully',
            'data' => [
                'reference' => $transaction->reference,
                'amount' => (int) $transaction->amount,
                'currency' => $transaction->currency,
                'email' => $transaction->user_email,
                'status' => $transaction->status,
                'paidAt' => optional($transaction->paid_at)?->toIso8601String(),
                'channel' => $transaction->channel,
                'metadata' => $transaction->metadata ?? [],
            ],
        ];
    }
}