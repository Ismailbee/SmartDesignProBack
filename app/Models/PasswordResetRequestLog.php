<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PasswordResetRequestLog extends Model
{
    use HasFactory;

    protected $table = 'password_reset_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'email',
        'ip_address',
        'status',
    ];
}