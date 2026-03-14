<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PublicApiController;
use App\Http\Controllers\Api\TemplateController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'PlutoD Laravel Backend',
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::post('/sendLoginOTP', [AuthController::class, 'sendLoginOtp']);
Route::post('/verifyLoginOTP', [AuthController::class, 'verifyLoginOtp']);
Route::post('/initializePayment', [PaymentController::class, 'initialize']);
Route::post('/verifyPayment', [PaymentController::class, 'verify']);
Route::post('/paystackWebhook', [PaymentController::class, 'webhook']);
Route::post('/extractWeddingEntities', [PaymentController::class, 'extractWeddingEntities']);
Route::middleware('firebase.admin')->post('/adminVerifyReference', [PaymentController::class, 'adminVerifyReference']);

Route::prefix('/api')->group(function () {
    Route::prefix('/auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/password-reset/request', [AuthController::class, 'sendPasswordReset']);
        Route::post('/password-reset/confirm', [AuthController::class, 'confirmPasswordReset']);
        Route::middleware('firebase.auth')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/password-change', [AuthController::class, 'changePassword']);
            Route::put('/profile', [AuthController::class, 'updateProfile']);
        });
    });

    Route::post('/send-password-reset', [AuthController::class, 'sendPasswordReset']);

    Route::post('/payments/initialize', [PaymentController::class, 'initialize']);
    Route::get('/payments/verify/{reference}', [PaymentController::class, 'verifyFromPath']);

    Route::get('/users/{userId}', [PublicApiController::class, 'showUser']);
    Route::post('/users/{userId}/deduct-tokens', [PublicApiController::class, 'deductTokens']);
    Route::post('/users/{userId}/track-feature', [PublicApiController::class, 'trackFeature']);
    Route::get('/users/{userId}/notifications', [PublicApiController::class, 'notifications']);
    Route::post('/users/{userId}/notifications/{notificationId}/read', [PublicApiController::class, 'markNotificationRead']);
    Route::post('/users/{userId}/notifications/read-all', [PublicApiController::class, 'markAllNotificationsRead']);
    Route::get('/users/{userId}/payments', [PublicApiController::class, 'userPayments']);

    Route::post('/referral/validate', [PublicApiController::class, 'validateReferral']);
    Route::post('/referral/apply', [PublicApiController::class, 'applyReferral']);
    Route::get('/referral/stats/{userId}', [PublicApiController::class, 'referralStats']);
    Route::get('/referral/code/{userId}', [PublicApiController::class, 'referralCode']);

    Route::get('/subscription/plans', [PublicApiController::class, 'subscriptionPlans']);
    Route::get('/subscription/status/{userId}', [PublicApiController::class, 'subscriptionStatus']);

    Route::post('/reports', [PaymentController::class, 'report']);
    Route::get('/settings/maintenance-status', [PublicApiController::class, 'maintenanceStatus']);
    Route::post('/admin/log-activity', [PublicApiController::class, 'logActivity']);

    Route::middleware('firebase.admin')->prefix('/admin')->group(function () {
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/{id}', [AdminController::class, 'userDetail']);
        Route::put('/users/{id}', [AdminController::class, 'updateUser']);
        Route::post('/users/{id}/suspend', [AdminController::class, 'suspendUser']);
        Route::post('/users/{id}/reset-password', [AdminController::class, 'resetUserPassword']);
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
        Route::post('/users/{id}/credit-tokens', [AdminController::class, 'creditTokens']);
        Route::post('/set-admin', [AdminController::class, 'setAdmin']);

        Route::get('/payments', [AdminController::class, 'payments']);
        Route::get('/payments/{id}', [AdminController::class, 'paymentDetail']);
        Route::post('/payments/{id}/refund', [AdminController::class, 'refundPayment']);
        Route::get('/revenue', [AdminController::class, 'revenue']);
        Route::get('/active-users', [AdminController::class, 'activeUsers']);
        Route::get('/users-status', [AdminController::class, 'usersStatus']);
        Route::get('/recent-activity', [AdminController::class, 'recentActivity']);
        Route::get('/analytics/user-growth', [AdminController::class, 'userGrowth']);
        Route::get('/revenue-chart', [AdminController::class, 'revenueChart']);
        Route::get('/daily-active', [AdminController::class, 'dailyActive']);
        Route::get('/reports', [AdminController::class, 'reports']);
        Route::post('/reports/{id}/resolve', [AdminController::class, 'resolveReport']);
        Route::get('/settings', [AdminController::class, 'settings']);
        Route::put('/settings', [AdminController::class, 'updateSettings']);
        Route::get('/settings/pricing', [AdminController::class, 'pricing']);
        Route::put('/settings/pricing', [AdminController::class, 'updatePricing']);
        Route::post('/notifications/send', [AdminController::class, 'sendNotification']);
        Route::get('/system/health', [AdminController::class, 'systemHealth']);

        Route::get('/analytics/users', [AdminController::class, 'legacyAnalyticsUsers']);
        Route::get('/analytics/revenue', [AdminController::class, 'legacyAnalyticsRevenue']);
        Route::get('/analytics/plans', [AdminController::class, 'legacyAnalyticsPlans']);
        Route::get('/analytics/templates', [AdminController::class, 'legacyAnalyticsTemplates']);
        Route::get('/analytics/popular-templates', [AdminController::class, 'popularTemplates']);

        Route::get('/templates', [TemplateController::class, 'index']);
        Route::get('/templates/pending', [TemplateController::class, 'pending']);
        Route::get('/templates/{id}', [TemplateController::class, 'show']);
        Route::put('/templates/{id}', [TemplateController::class, 'update']);
        Route::post('/templates/{id}/approve', [TemplateController::class, 'approve']);
        Route::post('/templates/{id}/reject', [TemplateController::class, 'reject']);
        Route::delete('/templates/{id}', [TemplateController::class, 'destroy']);
    });
});