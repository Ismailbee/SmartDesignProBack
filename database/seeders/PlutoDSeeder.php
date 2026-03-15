<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Seeder;

class PlutoDSeeder extends Seeder
{
    public function run(): void
    {
        AppSetting::query()->updateOrCreate(
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
                'pricing' => [
                    'Premium' => [
                        'price' => 2500,
                        'tokens' => 1000,
                        'durationMonths' => 2,
                        'planId' => 'PLN_5x6n9kfpr8z34lu',
                    ],
                ],
                'features' => [
                    'imposition' => true,
                    'ai' => true,
                    'payments' => true,
                ],
            ]
        );

        $adminOne = User::query()->updateOrCreate(
            ['email' => 'ismailabdulrauf639@gmail.com'],
            [
                'firebase_uid' => 'admin-ismail',
                'name' => 'Ismail',
                'password' => 'Password123!',
                'role' => 'admin',
                'status' => 'active',
                'plan' => 'Premium',
                'tokens' => 5000,
                'referral_code' => 'ISMAIL01',
                'last_active_at' => now(),
            ]
        );

        $adminTwo = User::query()->updateOrCreate(
            ['email' => 'mohammedabdulsalam06@gmail.com'],
            [
                'firebase_uid' => 'admin-mohammed',
                'name' => 'Mohammed',
                'password' => 'Password123!',
                'role' => 'admin',
                'status' => 'active',
                'plan' => 'Premium',
                'tokens' => 5000,
                'referral_code' => 'MOHAMMED',
                'last_active_at' => now(),
            ]
        );

        $sampleUsers = collect([
            ['firebase_uid' => 'user-demo-1', 'name' => 'Ada User', 'email' => 'ada@example.com', 'password' => 'Password123!', 'plan' => 'Basic', 'tokens' => 240, 'referral_code' => 'ADAUSER1'],
            ['firebase_uid' => 'user-demo-2', 'name' => 'Tunde Premium', 'email' => 'tunde@example.com', 'password' => 'Password123!', 'plan' => 'Premium', 'tokens' => 1250, 'referral_code' => 'TUNDE001'],
            ['firebase_uid' => 'user-demo-3', 'name' => 'Kemi Designer', 'email' => 'kemi@example.com', 'password' => 'Password123!', 'plan' => 'Basic', 'tokens' => 90, 'referral_code' => 'KEMI0001'],
        ])->map(fn (array $user) => User::query()->updateOrCreate(
            ['email' => $user['email']],
            array_merge($user, [
                'role' => 'user',
                'status' => 'active',
                'last_active_at' => now()->subMinutes(random_int(1, 240)),
            ])
        ));

        $templates = [
            [
                'id' => 'template-wedding-01',
                'creator_id' => $adminOne->id,
                'title' => 'Wedding Sticker Classic',
                'description' => 'Classic wedding sticker layout for quick print jobs.',
                'category' => 'Stickers',
                'tags' => ['wedding', 'sticker', 'classic'],
                'status' => 'published',
                'access_level' => 'free',
                'price' => 0,
                'thumbnail_url' => 'https://plutod.com/templates/wedding-sticker-classic.png',
                'file_url' => '/templates/wedding-sticker-classic.json',
                'downloads' => 420,
                'likes' => 87,
            ],
            [
                'id' => 'template-receipt-01',
                'creator_id' => $adminTwo->id,
                'title' => 'POS Receipt Premium',
                'description' => 'Clean receipt template with premium styling.',
                'category' => 'Receipts',
                'tags' => ['receipt', 'retail'],
                'status' => 'published',
                'access_level' => 'premium',
                'price' => 150,
                'thumbnail_url' => 'https://plutod.com/templates/pos-receipt-premium.png',
                'file_url' => '/templates/pos-receipt-premium.json',
                'downloads' => 180,
                'likes' => 31,
            ],
            [
                'id' => 'template-flyer-pending',
                'creator_id' => $sampleUsers[0]->id,
                'title' => 'Sunday Service Flyer',
                'description' => 'Pending church flyer awaiting admin review.',
                'category' => 'Flyers',
                'tags' => ['flyer', 'church'],
                'status' => 'pending',
                'access_level' => 'free',
                'price' => 0,
                'thumbnail_url' => 'https://plutod.com/templates/sunday-service-flyer.png',
                'file_url' => '/templates/sunday-service-flyer.json',
                'downloads' => 0,
                'likes' => 0,
            ],
        ];

        foreach ($templates as $template) {
            Template::query()->updateOrCreate(['id' => $template['id']], $template);
        }
    }
}