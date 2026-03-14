<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtpVerification extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'email',
        'otp_hash',
        'expires_at',
        'verified',
        'attempts',
        'verified_at',
        'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified' => 'boolean',
        'verified_at' => 'datetime',
    ];
}