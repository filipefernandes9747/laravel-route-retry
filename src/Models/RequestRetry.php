<?php

namespace LaravelRouteRetry\Models;

use Illuminate\Database\Eloquent\Model;

class RequestRetry extends Model
{
    protected $table = 'request_retries';

    protected $guarded = [];

    protected $casts = [
        'headers' => 'array',
        'body' => 'array',
        'files' => 'array',
        'tags' => 'array',
        'next_attempt_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeDue($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now());
        });
    }
}
