<?php

namespace LaravelRouteRetry\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LaravelRouteRetry\Models\RequestRetry;

class RetryFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public RequestRetry $requestRetry, public string $reason)
    {
    }
}
