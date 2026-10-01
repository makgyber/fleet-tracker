<?php

namespace App\Providers;

use App\Services\FirebaseService;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Contract\Database as FirebaseDatabase;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolve the Firebase SDK contracts lazily and only when configured,
        // so the app boots and runs cleanly without service-account credentials.
        $this->app->singleton(FirebaseService::class, function ($app) {
            $configured = ! empty(config('firebase.projects.app.credentials'))
                && ! empty(config('firebase.projects.app.database.url'));

            if (! $configured) {
                return new FirebaseService(null, null);
            }

            return new FirebaseService(
                $app->make(FirebaseDatabase::class),
                $app->make(FirebaseAuth::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
