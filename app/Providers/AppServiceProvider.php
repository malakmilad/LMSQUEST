<?php

namespace App\Providers;

use App\Payments\Contracts\PaymentProvider;
use App\Payments\MockPaymentProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MockPaymentProvider::class);
        $this->app->singleton(PaymentProvider::class, fn ($app) => $app->make(MockPaymentProvider::class));
    }

    public function boot(): void
    {
        //
    }
}
