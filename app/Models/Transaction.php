<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'reference',
        'user_id',
        'user_name',
        'user_email',
        'amount',
        'currency',
        'type',
        'plan',
        'tokens',
        'status',
        'channel',
        'paid_at',
        'verified_at',
        'source',
        'credited_by',
        'report_id',
        'reason',
        'refund_reason',
        'refunded_at',
        'metadata',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'verified_at' => 'datetime',
        'refunded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}