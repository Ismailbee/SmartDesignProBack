<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_name',
        'site_url',
        'support_email',
        'maintenance_mode',
        'allow_registration',
        'require_email_verification',
        'max_upload_size',
        'enable_ai',
        'default_user_plan',
        'session_timeout',
        'max_free_tokens',
        'pricing',
        'features',
    ];

    protected $casts = [
        'maintenance_mode' => 'boolean',
        'allow_registration' => 'boolean',
        'require_email_verification' => 'boolean',
        'enable_ai' => 'boolean',
        'pricing' => 'array',
        'features' => 'array',
    ];
}