<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'creator_id',
        'title',
        'description',
        'category',
        'tags',
        'status',
        'access_level',
        'price',
        'thumbnail_url',
        'file_url',
        'downloads',
        'likes',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}