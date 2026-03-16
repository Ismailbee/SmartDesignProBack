<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'firebase_uid',
        'google_id',
        'name',
        'email',
        'password',
        'avatar',
        'role',
        'status',
        'plan',
        'plan_expiry_at',
        'tokens',
        'admin_credit_tokens',
        'total_designs_generated',
        'referral_code',
        'referred_by',
        'total_referrals',
        'last_active_at',
        'last_feature_used',
        'fcm_tokens',
        'suspended_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'plan_expiry_at' => 'datetime',
            'last_active_at' => 'datetime',
            'suspended_at' => 'datetime',
            'fcm_tokens' => 'array',
            'password' => 'hashed',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function referralsMade(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    public function referralsReceived(): HasMany
    {
        return $this->hasMany(Referral::class, 'referred_user_id');
    }

    public function paymentReports(): HasMany
    {
        return $this->hasMany(PaymentReport::class);
    }

    public function getApiIdAttribute(): string
    {
        return $this->firebase_uid ?: (string) $this->getKey();
    }
}
