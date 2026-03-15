<?php

namespace App\Services;

use App\Models\AppSetting;

class AppSettingService
{
    public function get(): AppSetting
    {
        return AppSetting::query()->firstOrCreate(
            ['id' => 1],
            [
                'site_name' => 'PlutoD',
                'site_url' => 'https://plutod.com',
                'support_email' => 'support@plutod.com',
                'maintenance_mode' => false,
                'allow_registration' => true,
                'require_email_verification' => false,
                'max_upload_size' => 50,
                'enable_ai' => true,
                'default_user_plan' => 'Basic',
                'session_timeout' => 120,
                'max_free_tokens' => (int) config('plutod.starter_tokens', 16),
                'pricing' => $this->defaultPricing(),
                'features' => [
                    'imposition' => true,
                    'ai' => true,
                    'payments' => true,
                ],
            ]
        );
    }

    public function defaultPricing(): array
    {
        $premium = config('plutod.plans.premium', []);

        return [
            'Premium' => [
                'price' => (int) ($premium['price'] ?? 2500),
                'tokens' => (int) ($premium['token_bonus'] ?? 1000),
                'durationMonths' => (int) ($premium['duration_months'] ?? 2),
                'planId' => $premium['plan_id'] ?? null,
            ],
        ];
    }

    public function maintenanceStatus(): array
    {
        return ['maintenanceMode' => (bool) $this->get()->maintenance_mode];
    }
}