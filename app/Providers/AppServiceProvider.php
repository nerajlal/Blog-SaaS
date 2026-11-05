<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\View::composer('partials.sidebar', function ($view) {
            if ($user = auth()->user()) {
                $view->with('workspaces', $user->workspaces)->with('workspace', $user->currentWorkspace);
            }
        });
    }
}
