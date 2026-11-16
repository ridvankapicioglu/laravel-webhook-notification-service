<?php

namespace App\Providers;

use App\Delivery\ChannelRateLimiter;
use App\Delivery\NotificationProvider;
use App\Delivery\RetryPolicy;
use App\Delivery\WebhookProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationProvider::class, fn () => new WebhookProvider(
            url: config('notifications.provider.url'),
            timeout: config('notifications.provider.timeout'),
            connectTimeout: config('notifications.provider.connect_timeout'),
        ));

        $this->app->bind(ChannelRateLimiter::class, fn ($app) => new ChannelRateLimiter(
            redis: $app['redis']->connection(),
            perWindow: config('notifications.rate_limit_per_second'),
            windowMs: config('notifications.rate_limit_window_ms'),
        ));

        $this->app->bind(RetryPolicy::class, fn () => new RetryPolicy(
            maxAttempts: config('notifications.max_attempts'),
            backoff: config('notifications.backoff'),
            jitter: config('notifications.backoff_jitter'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
