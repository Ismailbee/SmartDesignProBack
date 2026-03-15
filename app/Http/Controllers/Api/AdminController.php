<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\PaymentReport;
use App\Models\Template;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AppSettingService;
use App\Services\NotificationService;
use App\Services\PasswordResetService;
use App\Services\PaymentService;
use App\Services\UserService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class AdminController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
        private readonly PaymentService $paymentService,
        private readonly AppSettingService $appSettingService,
        private readonly NotificationService $notificationService,
        private readonly PasswordResetService $passwordResetService,
    ) {
    }

    public function stats(): JsonResponse
    {
        $totalUsers = User::query()->count();
        $activeUsers = User::query()->where('last_active_at', '>=', now()->subDays(7))->count();
        $onlineUsers = User::query()->where('last_active_at', '>=', now()->subMinutes(5))->count();
        $totalRevenue = (int) Transaction::query()->where('status', 'success')->sum('amount');
        $monthlyRevenue = (int) Transaction::query()->where('status', 'success')->where('created_at', '>=', now()->startOfMonth())->sum('amount');
        $totalDesigns = (int) User::query()->sum('total_designs_generated');
        $totalPayments = Transaction::query()->where('status', 'success')->count();
        $planDistribution = User::query()->selectRaw('plan, COUNT(*) as total')->groupBy('plan')->pluck('total', 'plan')->all();

        return response()->json([
            'totalUsers' => $totalUsers,
            'activeUsers' => $activeUsers,
            'onlineUsers' => $onlineUsers,
            'loggedOutUsers' => max(0, $totalUsers - $onlineUsers),
            'totalRevenue' => $totalRevenue,
            'monthlyRevenue' => $monthlyRevenue,
            'totalDesigns' => $totalDesigns,
            'totalPayments' => $totalPayments,
            'planDistribution' => $planDistribution,
            'featureUsage' => [
                'wedding_sticker' => Activity::query()->where('type', 'feature_used')->where('metadata->feature', 'wedding_sticker')->count(),
                'letterhead' => Activity::query()->where('type', 'feature_used')->where('metadata->feature', 'letterhead')->count(),
                'other' => Activity::query()->where('type', 'feature_used')->whereNotIn('metadata->feature', ['wedding_sticker', 'letterhead'])->count(),
            ],
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $query = User::query();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('firebase_uid', 'like', "%{$search}%");
            });
        }

        if ($plan = $request->query('plan')) {
            if (! in_array($plan, ['all', ''], true)) {
                $query->where('plan', $plan);
            }
        }

        if ($status = $request->query('status')) {
            if (! in_array($status, ['all', ''], true)) {
                $query->where('status', $status);
            }
        }

        if ($role = $request->query('role')) {
            if (! in_array($role, ['all', ''], true)) {
                $query->where('role', $role);
            }
        }

        $sortBy = $request->query('sortBy', 'created_at');
        $sortOrder = $request->query('sortOrder', 'desc') === 'asc' ? 'asc' : 'desc';
        if (! in_array($sortBy, ['created_at', 'updated_at', 'last_active_at', 'tokens', 'name', 'email'], true)) {
            $sortBy = 'created_at';
        }

        $paginator = $query->orderBy($sortBy, $sortOrder)->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'users' => $paginator->getCollection()->map(fn (User $user) => $this->userService->toAdminArray($user))->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'limit' => $paginator->perPage(),
            'totalPages' => $paginator->lastPage(),
        ]);
    }

    public function userDetail(string $id): JsonResponse
    {
        $user = $this->userService->findByApiId($id);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $transactions = $user->transactions()->latest()->limit(50)->get()->map(fn (Transaction $transaction) => $this->paymentService->transactionToArray($transaction))->values()->all();
        $reports = $user->paymentReports()->latest()->get()->values()->all();
        $notifications = $user->notifications()->latest()->limit(20)->get()->map(fn ($notification) => $this->notificationService->toArray($notification, $user))->values()->all();
        $activities = $user->activities()->latest('timestamp')->limit(50)->get()->map(fn (Activity $activity) => [
            'id' => $activity->id,
            'userId' => $user->api_id,
            'type' => $activity->type,
            'description' => $activity->description,
            'timestamp' => optional($activity->timestamp)?->toIso8601String(),
            'metadata' => $activity->metadata ?? [],
        ])->values()->all();
        $referrals = $user->referralsMade()->latest()->get()->map(fn ($referral) => [
            'id' => $referral->id,
            'referredName' => $referral->referred_user_name,
            'tokensAwarded' => (int) ($referral->tokens_awarded['referrer'] ?? 0),
            'createdAt' => optional($referral->created_at)?->toIso8601String(),
        ])->values()->all();

        return response()->json([
            'user' => $this->userService->toAdminArray($user),
            'transactions' => $transactions,
            'referralCount' => count($referrals),
            'referrals' => $referrals,
            'reports' => $reports,
            'notifications' => $notifications,
            'activities' => $activities,
            'totalSpent' => (int) $user->transactions()->where('status', 'success')->sum('amount'),
            'subscription' => [
                'plan' => $user->plan,
                'expiry' => optional($user->plan_expiry_at)?->toIso8601String(),
                'isActive' => $user->plan === 'Premium' && (! $user->plan_expiry_at || $user->plan_expiry_at->isFuture()),
                'tokensBalance' => (int) $user->tokens,
                'paidTokensBalance' => max(0, (int) $user->tokens - (int) $user->admin_credit_tokens),
                'adminCreditTokens' => (int) $user->admin_credit_tokens,
                'totalTokensPurchased' => (int) $user->transactions()
                    ->where('status', 'success')
                    ->whereIn('type', ['token_purchase', 'plan_upgrade'])
                    ->sum('tokens'),
            ],
        ]);
    }

    public function updateUser(Request $request, string $id): JsonResponse
    {
        $user = $this->userService->findByApiId($id);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $data = $request->validate([
            'plan' => ['nullable', 'string'],
            'tokens' => ['nullable', 'integer', 'min:0'],
            'name' => ['nullable', 'string'],
            'planExpiry' => ['nullable', 'date'],
            'planExpiryDate' => ['nullable', 'date'],
            'role' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);

        $updates = [];
        foreach (['plan', 'tokens', 'name', 'role', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (isset($data['planExpiry']) || isset($data['planExpiryDate'])) {
            $updates['plan_expiry_at'] = $data['planExpiryDate'] ?? $data['planExpiry'];
        }

        $user->fill($updates)->save();

        return response()->json(['success' => true, 'updated' => array_keys($updates), 'user' => $this->userService->toAdminArray($user->refresh())]);
    }

    public function suspendUser(Request $request, string $id): JsonResponse
    {
        $user = $this->userService->findByApiId($id);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $data = $request->validate(['suspended' => ['nullable', 'boolean']]);
        $suspended = (bool) ($data['suspended'] ?? true);
        $user->forceFill([
            'status' => $suspended ? 'suspended' : 'active',
            'suspended_at' => $suspended ? now() : null,
        ])->save();

        return response()->json(['success' => true, 'suspended' => $suspended]);
    }

    public function deleteUser(string $id): JsonResponse
    {
        $user = $this->userService->findByApiId($id);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $user->delete();

        return response()->json(['success' => true]);
    }

    public function resetUserPassword(Request $request, string $id): JsonResponse
    {
        $user = $this->userService->findByApiId($id);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        return response()->json($this->passwordResetService->request($user->email, $request->ip() ?? 'admin'));
    }

    public function creditTokens(Request $request, string $id): JsonResponse
    {
        $user = $this->userService->findByApiId($id);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $data = $request->validate([
            'tokens' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string'],
        ]);

        try {
            $claims = (array) $request->attributes->get('firebase_claims', []);
            return response()->json($this->paymentService->creditTokens($user, $data['tokens'], $data['reason'] ?? null, $claims['sub'] ?? null));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }

    public function setAdmin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'targetUserId' => ['required', 'string'],
            'isAdmin' => ['nullable', 'boolean'],
        ]);

        $user = $this->userService->findByApiId($data['targetUserId']);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $isAdmin = (bool) ($data['isAdmin'] ?? true);
        $user->forceFill(['role' => $isAdmin ? 'admin' : 'user'])->save();

        return response()->json(['success' => true, 'message' => $isAdmin ? 'Admin role granted' : 'Admin role revoked']);
    }

    public function payments(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = min(200, max(1, (int) $request->query('limit', 50)));
        $query = Transaction::query()->latest();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('reference', 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%")
                    ->orWhere('user_email', 'like', "%{$search}%");
            });
        }

        foreach (['status', 'plan'] as $field) {
            $value = $request->query($field);
            if ($value && ! in_array($value, ['all', ''], true)) {
                $query->where($field, $value);
            }
        }

        if ($dateFrom = $request->query('dateFrom')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->query('dateTo')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $paginator = $query->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'payments' => $paginator->getCollection()->map(fn (Transaction $transaction) => $this->paymentService->transactionToArray($transaction))->values()->all(),
            'total' => $paginator->total(),
        ]);
    }

    public function paymentDetail(string $id): JsonResponse
    {
        return response()->json($this->paymentService->paymentDetail($id));
    }

    public function refundPayment(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string']]);

        return response()->json($this->paymentService->refund($id, $data['reason'] ?? null));
    }

    public function revenue(Request $request): JsonResponse
    {
        $period = max(1, (int) $request->query('period', 30));
        $start = now()->subDays($period - 1)->startOfDay();
        $transactions = Transaction::query()->where('status', 'success')->where('created_at', '>=', $start)->get();
        $dailyRevenue = [];

        foreach ($transactions as $transaction) {
            $key = $transaction->created_at?->format('Y-m-d') ?? now()->format('Y-m-d');
            $dailyRevenue[$key] = ($dailyRevenue[$key] ?? 0) + (int) $transaction->amount;
        }

        return response()->json([
            'total' => array_sum($dailyRevenue),
            'period' => $period,
            'transactionCount' => $transactions->count(),
            'dailyRevenue' => $dailyRevenue,
            'currency' => 'NGN',
        ]);
    }

    public function activeUsers(Request $request): JsonResponse
    {
        $limit = min(200, max(1, (int) $request->query('limit', 50)));
        $users = User::query()->whereNotNull('last_active_at')->orderByDesc('last_active_at')->limit($limit)->get();

        return response()->json([
            'users' => $users->map(fn (User $user) => [
                'id' => $user->api_id,
                'name' => $user->name,
                'email' => $user->email,
                'plan' => $user->plan,
                'tokens' => (int) $user->tokens,
                'lastActiveAt' => optional($user->last_active_at)?->toIso8601String(),
                'totalDesignsGenerated' => (int) $user->total_designs_generated,
            ])->values()->all(),
            'total' => $users->count(),
        ]);
    }

    public function usersStatus(): JsonResponse
    {
        $online = User::query()->where('last_active_at', '>=', now()->subMinutes(5))->orderByDesc('last_active_at')->limit(50)->get();
        $activeToday = User::query()->where('last_active_at', '>=', now()->subDay())->where('last_active_at', '<', now()->subMinutes(5))->orderByDesc('last_active_at')->limit(50)->get();
        $inactive = User::query()->where(function (Builder $builder) {
            $builder->whereNull('last_active_at')->orWhere('last_active_at', '<', now()->subDay());
        })->orderByDesc('updated_at')->limit(50)->get();

        $map = fn ($users) => $users->map(fn (User $user) => $this->userService->toAdminArray($user))->values()->all();

        return response()->json([
            'online' => $map($online),
            'activeToday' => $map($activeToday),
            'inactive' => $map($inactive),
            'counts' => [
                'online' => $online->count(),
                'activeToday' => $activeToday->count(),
                'inactive' => $inactive->count(),
                'total' => User::query()->count(),
            ],
        ]);
    }

    public function recentActivity(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 20)));
        $activities = Activity::query()->latest('timestamp')->limit($limit)->get();

        return response()->json([
            'activities' => $activities->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'userId' => optional($activity->user)->api_id,
                'type' => $activity->type,
                'description' => $activity->description,
                'metadata' => $activity->metadata ?? [],
                'timestamp' => optional($activity->timestamp)?->toIso8601String(),
            ])->values()->all(),
            'total' => $activities->count(),
        ]);
    }

    public function userGrowth(Request $request): JsonResponse
    {
        $days = max(1, (int) $request->query('days', 30));
        $rows = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = now()->subDays($offset)->startOfDay();
            $next = $date->copy()->endOfDay();
            $rows[] = [
                'date' => $date->format('Y-m-d'),
                'newUsers' => User::query()->whereBetween('created_at', [$date, $next])->count(),
                'activeUsers' => User::query()->whereBetween('last_active_at', [$date, $next])->count(),
                'users' => User::query()->whereBetween('created_at', [$date, $next])->count(),
                'churnedUsers' => 0,
            ];
        }

        return response()->json(['data' => $rows, 'totalNew' => array_sum(array_column($rows, 'newUsers')), 'period' => $days]);
    }

    public function legacyAnalyticsUsers(Request $request): JsonResponse
    {
        return $this->userGrowth($request);
    }

    public function legacyAnalyticsRevenue(Request $request): JsonResponse
    {
        $period = max(1, (int) $request->query('period', 30));
        $response = $this->revenue(new Request(['period' => $period]));
        $decoded = $response->getData(true);
        $rows = [];
        foreach (($decoded['dailyRevenue'] ?? []) as $date => $amount) {
            $rows[] = ['date' => $date, 'revenue' => $amount, 'transactions' => 0];
        }
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function legacyAnalyticsPlans(): JsonResponse
    {
        $distribution = User::query()->selectRaw('plan, COUNT(*) as count')->groupBy('plan')->get();
        $total = max(1, $distribution->sum('count'));
        return response()->json(['success' => true, 'data' => $distribution->map(fn ($row) => ['plan' => $row->plan, 'count' => (int) $row->count, 'percentage' => (int) round(($row->count / $total) * 100)])->values()->all()]);
    }

    public function legacyAnalyticsTemplates(): JsonResponse
    {
        $categories = Template::query()->selectRaw('category, SUM(downloads) as downloads, SUM(price) as revenue')->groupBy('category')->get();
        return response()->json(['success' => true, 'data' => $categories->map(fn ($row) => ['category' => $row->category, 'downloads' => (int) $row->downloads, 'revenue' => (int) $row->revenue])->values()->all()]);
    }

    public function popularTemplates(): JsonResponse
    {
        $templates = Template::query()->orderByDesc('downloads')->limit(10)->get();

        return response()->json(['success' => true, 'data' => $templates->map(fn (Template $template) => [
            'id' => $template->id,
            'title' => $template->title,
            'category' => $template->category,
            'downloads' => (int) $template->downloads,
            'revenue' => (int) $template->price,
            'thumbnailUrl' => $template->thumbnail_url,
        ])->values()->all()]);
    }

    public function revenueChart(Request $request): JsonResponse
    {
        $months = max(1, (int) $request->query('months', 12));
        $rows = [];

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $date = now()->subMonths($offset)->startOfMonth();
            $end = $date->copy()->endOfMonth();
            $rows[] = [
                'date' => $date->format('Y-m'),
                'revenue' => (int) Transaction::query()->where('status', 'success')->whereBetween('created_at', [$date, $end])->sum('amount'),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    public function dailyActive(): JsonResponse
    {
        $users = User::query()->where('last_active_at', '>=', now()->startOfDay())->orderByDesc('last_active_at')->limit(100)->get();

        return response()->json([
            'count' => $users->count(),
            'users' => $users->map(fn (User $user) => [
                'id' => $user->api_id,
                'name' => $user->name,
                'email' => $user->email,
                'plan' => $user->plan,
                'tokens' => (int) $user->tokens,
                'lastActiveAt' => optional($user->last_active_at)?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        $status = $request->query('status', 'all');
        $query = PaymentReport::query()->latest();
        if (! in_array($status, ['all', '', null], true)) {
            $query->where('status', $status);
        }
        $reports = $query->get();

        return response()->json(['reports' => $reports->values()->all(), 'total' => $reports->count()]);
    }

    public function resolveReport(Request $request, string $id): JsonResponse
    {
        $report = PaymentReport::query()->find($id);
        if (! $report) {
            return response()->json(['error' => 'Report not found'], 404);
        }

        $data = $request->validate([
            'action' => ['required', 'string'],
            'adminNotes' => ['nullable', 'string'],
        ]);

        try {
            $claims = (array) $request->attributes->get('firebase_claims', []);
            return response()->json($this->paymentService->resolveReport($report, $data['action'], $data['adminNotes'] ?? null, $claims['sub'] ?? null));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }
    }

    public function settings(): JsonResponse
    {
        return response()->json($this->appSettingService->get());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $settings = $this->appSettingService->get();
        $data = $request->validate([
            'siteName' => ['nullable', 'string'],
            'siteUrl' => ['nullable', 'string'],
            'supportEmail' => ['nullable', 'email'],
            'maintenanceMode' => ['nullable', 'boolean'],
            'allowRegistration' => ['nullable', 'boolean'],
            'requireEmailVerification' => ['nullable', 'boolean'],
            'maxUploadSize' => ['nullable', 'integer'],
            'enableAI' => ['nullable', 'boolean'],
            'defaultUserPlan' => ['nullable', 'string'],
            'sessionTimeout' => ['nullable', 'integer'],
            'maxFreeTokens' => ['nullable', 'integer'],
            'pricing' => ['nullable', 'array'],
            'features' => ['nullable', 'array'],
        ]);

        $map = [
            'siteName' => 'site_name',
            'siteUrl' => 'site_url',
            'supportEmail' => 'support_email',
            'maintenanceMode' => 'maintenance_mode',
            'allowRegistration' => 'allow_registration',
            'requireEmailVerification' => 'require_email_verification',
            'maxUploadSize' => 'max_upload_size',
            'enableAI' => 'enable_ai',
            'defaultUserPlan' => 'default_user_plan',
            'sessionTimeout' => 'session_timeout',
            'maxFreeTokens' => 'max_free_tokens',
            'pricing' => 'pricing',
            'features' => 'features',
        ];

        $updates = [];
        foreach ($data as $key => $value) {
            $updates[$map[$key]] = $value;
        }

        $settings->fill($updates)->save();

        return response()->json(['success' => true, 'updated' => array_keys($updates)]);
    }

    public function pricing(): JsonResponse
    {
        return response()->json(['pricing' => $this->appSettingService->get()->pricing]);
    }

    public function updatePricing(Request $request): JsonResponse
    {
        $data = $request->validate(['pricing' => ['required', 'array']]);
        $settings = $this->appSettingService->get();
        $settings->forceFill(['pricing' => $data['pricing']])->save();

        return response()->json(['success' => true, 'pricing' => $settings->pricing]);
    }

    public function sendNotification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'userIds' => ['nullable', 'array'],
            'userIds.*' => ['string'],
            'title' => ['required', 'string'],
            'message' => ['required', 'string'],
            'type' => ['nullable', 'string'],
        ]);

        $claims = (array) $request->attributes->get('firebase_claims', []);
        $users = ! empty($data['userIds'])
            ? User::query()->whereIn('firebase_uid', $data['userIds'])->orWhereIn('id', array_filter($data['userIds'], 'ctype_digit'))->get()
            : User::query()->get();

        $sent = $this->notificationService->createForMany($users, $data['title'], $data['message'], $data['type'] ?? 'admin_message', $claims['sub'] ?? null);

        return response()->json(['success' => true, 'sentTo' => $sent, 'title' => $data['title']]);
    }

    public function systemHealth(): JsonResponse
    {
        $memoryBytes = memory_get_usage(true);

        return response()->json([
            'status' => 'healthy',
            'cpu' => 0,
            'memory' => 0,
            'disk' => 0,
            'apiResponseTime' => 0,
            'activeConnections' => 0,
            'uptime' => (int) (microtime(true) - LARAVEL_START),
            'checks' => [
                'database' => 'ok',
                'memory' => $memoryBytes,
                'phpVersion' => PHP_VERSION,
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}